<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Tests\Feature\Gmail;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RobinsonRyan\Mailroom\Contracts\CredentialResolver;
use RobinsonRyan\Mailroom\Gmail\GmailClient;
use RobinsonRyan\Mailroom\Gmail\GmailProvider;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\Mailbox;
use RobinsonRyan\Mailroom\ProviderRegistry;

/**
 * Recorded-shape Gmail responses behind Http::fake, and a provider wired the
 * way the service provider wires it, with sleeps recorded instead of taken.
 */
final class GmailApi
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me/';

    /** @return array<string, mixed> */
    public static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::directory().'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Testbench ships no APP_KEY, and the token cache encrypts. */
    public static function appKey(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    public static function directory(): string
    {
        return dirname(__DIR__, 2).'/Fixtures/gmail';
    }

    /**
     * Routes are patterns relative to users/me/, checked before the token and
     * labels defaults, so a test can replace either.
     *
     * @param  array<string, mixed>  $routes
     */
    public static function fake(array $routes = []): void
    {
        $stubs = [];
        foreach ($routes as $pattern => $response) {
            $stubs[str_starts_with($pattern, 'https://') ? $pattern : self::BASE.$pattern] = $response;
        }

        $stubs += [
            self::TOKEN_URL => Http::response(self::fixture('token')),
            self::BASE.'labels' => Http::response(self::fixture('labels')),
        ];

        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** @param  array<int, int>  $slept  receives each backoff in seconds */
    public static function provider(array &$slept = []): GmailProvider
    {
        self::appKey();
        config()->set('mailroom.credentials.directory', self::directory().'/credentials');

        app()->instance(GmailClient::class, new GmailClient(
            app(CredentialResolver::class),
            app(Repository::class),
            app(StringEncrypter::class),
            function (int $seconds) use (&$slept): void {
                $slept[] = $seconds;
            },
        ));

        /** @var GmailProvider */
        return app(ProviderRegistry::class)->get('gmail');
    }

    public static function mailbox(string $address = 'info@example.com', string $identity = 'afwd-info'): Mailbox
    {
        return app(Mailroom::class)->connect($address, $identity);
    }

    /** @return list<Request> */
    public static function requests(string $pattern): array
    {
        $matches = [];
        foreach (Http::recorded() as [$request]) {
            if (fnmatch($pattern, $request->url())) {
                $matches[] = $request;
            }
        }

        return $matches;
    }

    /** base64url with no padding, the way Gmail delivers a body. */
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decodeRaw(string $raw): string
    {
        expect($raw)->not->toContain('+')->not->toContain('/');

        return (string) base64_decode(strtr($raw, '-_', '+/'), true);
    }

    /**
     * One rendered part or message as [headers, body], unfolded, names lower-cased.
     *
     * @return array{array<string, string>, string}
     */
    public static function split(string $entity): array
    {
        [$head, $body] = explode("\r\n\r\n", $entity, 2);
        $headers = [];
        foreach (explode("\r\n", (string) preg_replace('/\r\n[ \t]+/', ' ', $head)) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }

        return [$headers, $body];
    }
}
