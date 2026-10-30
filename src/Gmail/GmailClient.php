<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Gmail;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RobinsonRyan\Mailroom\Contracts\CredentialResolver;
use RobinsonRyan\Mailroom\Exceptions\MailboxDisconnected;
use RobinsonRyan\Mailroom\Exceptions\ProviderUnavailable;
use RobinsonRyan\Mailroom\Models\Mailbox;

/**
 * The Gmail REST transport: OAuth for a mailbox's identity, and retries of
 * transient failures. Everything else about a response is the caller's.
 */
final readonly class GmailClient
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me/';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const TOKEN_CACHE = 'mailroom:gmail:token:';

    /** A token stops being used this many seconds before it expires. */
    private const EXPIRY_MARGIN = 60;

    /** @var list<int> seconds to wait before each retry */
    private const BACKOFF = [1, 4, 16];

    /** The longest a Retry-After header may hold a worker, in seconds. */
    private const MAX_WAIT = 60;

    private const RATE_LIMIT_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded'];

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  (Closure(int): void)|null  $sleep  waits a number of seconds; tests record instead
     */
    public function __construct(
        private CredentialResolver $credentials,
        private Repository $cache,
        private StringEncrypter $encrypter,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Call users/me/<path>. Returns whatever Gmail answered once it is neither
     * transient nor an authentication failure.
     *
     * @param  array<string, mixed>  $query  list values become repeated parameters
     * @param  array<string, mixed>|null  $body  sent as JSON
     * @param  bool  $repeatable  false for calls that create something (send, insert, draft):
     *                            a 5xx or dropped connection may mean Gmail accepted it, so only
     *                            an explicit rate-limit refusal is retried
     *
     * @throws MailboxDisconnected
     * @throws ProviderUnavailable
     */
    public function request(Mailbox $mailbox, string $method, string $path, array $query = [], ?array $body = null, bool $repeatable = true): Response
    {
        $url = $this->url($path, $query);
        $options = $body === null ? [] : ['json' => $body];
        $call = fn (): Response => Http::withToken($this->accessToken($mailbox))->acceptJson()->send($method, $url, $options);

        $response = $this->withRetries($call, $repeatable);

        if ($response->status() !== 401) {
            return $response;
        }

        $this->cache->forget(self::TOKEN_CACHE.$mailbox->identity);
        $response = $this->withRetries($call, $repeatable);

        if ($response->status() === 401) {
            throw new MailboxDisconnected("{$mailbox->address} refused its credentials.");
        }

        return $response;
    }

    /**
     * The decoded body of a response the caller has no special case for.
     *
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable
     */
    public function json(Response $response): array
    {
        if (! $response->successful()) {
            throw new ProviderUnavailable("Gmail answered HTTP {$response->status()}.");
        }

        return (array) $response->json();
    }

    /**
     * Google reads a repeated parameter as historyTypes=a&historyTypes=b, not
     * the historyTypes[0]=a that http_build_query writes.
     *
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query): string
    {
        if ($query === []) {
            return self::API.$path;
        }

        return self::API.$path.'?'.preg_replace('/%5B\d+%5D=/', '=', http_build_query($query));
    }

    private function accessToken(Mailbox $mailbox): string
    {
        return $this->cachedToken($mailbox) ?? $this->exchangeToken($mailbox);
    }

    private function cachedToken(Mailbox $mailbox): ?string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE.$mailbox->identity);

        if (! is_string($cached)) {
            return null;
        }

        try {
            return $this->encrypter->decryptString($cached);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * The token is encrypted in the cache because a consumer's cache store may
     * be its database, and tokens stay out of the database (R6).
     */
    private function exchangeToken(Mailbox $mailbox): string
    {
        $credentials = $this->credentials->resolve($mailbox->identity);

        $response = $this->withRetries(fn (): Response => Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'client_id' => $credentials->clientId,
            'client_secret' => $credentials->clientSecret,
            'refresh_token' => $credentials->refreshToken,
        ]));

        if ($response->json('error') === 'invalid_grant') {
            throw new MailboxDisconnected("{$mailbox->address} refused its credentials.");
        }

        if (! $response->successful()) {
            throw new ProviderUnavailable("Token exchange for {$mailbox->address} answered HTTP {$response->status()}.");
        }

        $token = (string) $response->json('access_token');
        $seconds = (int) $response->json('expires_in') - self::EXPIRY_MARGIN;
        $this->cache->put(self::TOKEN_CACHE.$mailbox->identity, $this->encrypter->encryptString($token), $seconds);

        return $token;
    }

    /**
     * @param  Closure(): Response  $call
     *
     * @throws ProviderUnavailable
     */
    private function withRetries(Closure $call, bool $repeatable = true): Response
    {
        foreach (self::BACKOFF as $seconds) {
            $response = $this->attempt($call);

            if ($response instanceof Response && ! $this->isTransient($response)) {
                return $response;
            }

            if (! $repeatable && (! $response instanceof Response || ! $this->isRateLimited($response))) {
                throw new ProviderUnavailable('Gmail failed on a call that must not be repeated.');
            }

            ($this->sleep)(min(self::MAX_WAIT, max($seconds, $response instanceof Response ? $this->retryAfter($response) : 0)));
        }

        $response = $this->attempt($call);

        if ($response instanceof Response && ! $this->isTransient($response)) {
            return $response;
        }

        throw new ProviderUnavailable('Gmail kept failing after retries.');
    }

    /**
     * @param  Closure(): Response  $call
     */
    private function attempt(Closure $call): ?Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            return null;
        }
    }

    private function isTransient(Response $response): bool
    {
        return $response->serverError() || $this->isRateLimited($response);
    }

    private function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        if ($response->status() !== 403) {
            return false;
        }

        $reasons = array_column((array) $response->json('error.errors'), 'reason');

        return array_intersect($reasons, self::RATE_LIMIT_REASONS) !== [];
    }

    /** Seconds the response asks to wait: Retry-After is either a number or an HTTP date. */
    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $at = strtotime($header);

        return $at === false ? 0 : max(0, $at - now()->getTimestamp());
    }
}
