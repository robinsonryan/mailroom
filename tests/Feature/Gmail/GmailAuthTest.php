<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RobinsonRyan\Mailroom\Data\OutgoingMessage;
use RobinsonRyan\Mailroom\Enums\MailboxStatus;
use RobinsonRyan\Mailroom\Events\MailboxLostAccess;
use RobinsonRyan\Mailroom\Exceptions\MailboxDisconnected;
use RobinsonRyan\Mailroom\Exceptions\MissingCredentials;
use RobinsonRyan\Mailroom\Exceptions\ProviderUnavailable;
use RobinsonRyan\Mailroom\Tests\Feature\Gmail\GmailApi;
use RobinsonRyan\Mailroom\Watching\Watcher;

beforeEach(function (): void {
    $this->slept = [];
    $this->provider = GmailApi::provider($this->slept);
    $this->mailbox = GmailApi::mailbox();
});

it('exchanges the refresh token and calls Gmail with the access token', function (): void {
    GmailApi::fake(['profile' => Http::response(GmailApi::fixture('profile'))]);

    $this->provider->currentCursor($this->mailbox);

    Http::assertSent(fn (Request $request): bool => $request->url() === GmailApi::TOKEN_URL
        && $request->isForm()
        && $request['grant_type'] === 'refresh_token'
        && $request['client_id'] === 'fixture-client.apps.googleusercontent.example'
        && $request['client_secret'] === 'fixture-client-secret'
        && $request['refresh_token'] === 'fixture-refresh-token');
    Http::assertSent(fn (Request $request): bool => $request->url() === GmailApi::BASE.'profile'
        && $request->hasHeader('Authorization', 'Bearer ya29.fixture-access-token'));
});

it('reuses the access token per identity until shortly before it expires', function (): void {
    GmailApi::fake(['profile' => Http::response(GmailApi::fixture('profile'))]);
    $other = GmailApi::mailbox('other@example.com', 'other-box');

    $this->provider->currentCursor($this->mailbox);
    $this->provider->currentCursor($this->mailbox);
    expect(GmailApi::requests(GmailApi::TOKEN_URL))->toHaveCount(1);

    $this->provider->currentCursor($other);
    expect(GmailApi::requests(GmailApi::TOKEN_URL))->toHaveCount(2);

    $this->travel(3500)->seconds();
    $this->provider->currentCursor($this->mailbox);
    expect(GmailApi::requests(GmailApi::TOKEN_URL))->toHaveCount(2);

    // Fixture token lives 3599s; it must be gone a little before that.
    $this->travel(60)->seconds();
    $this->provider->currentCursor($this->mailbox);
    expect(GmailApi::requests(GmailApi::TOKEN_URL))->toHaveCount(3);
});

it('never holds the access token in the cache store in the clear', function (): void {
    // The hub's cache store is the database, and R6 keeps tokens out of it.
    GmailApi::fake(['profile' => Http::response(GmailApi::fixture('profile'))]);

    $this->provider->currentCursor($this->mailbox);

    $cached = app(Repository::class)->getStore()->get('mailroom:gmail:token:afwd-info');
    expect($cached)->toBeString()->not->toContain('ya29');
});

it('treats an unreadable cached token as a cache miss', function (): void {
    GmailApi::fake(['profile' => Http::response(GmailApi::fixture('profile'))]);
    app(Repository::class)->put('mailroom:gmail:token:afwd-info', 'encrypted-with-a-rotated-key', 600);

    $this->provider->currentCursor($this->mailbox);

    expect(GmailApi::requests(GmailApi::TOKEN_URL))->toHaveCount(1);
});

it('reports invalid_grant as a disconnected mailbox without calling Gmail', function (): void {
    GmailApi::fake([GmailApi::TOKEN_URL => Http::response(GmailApi::fixture('token-invalid-grant'), 400)]);

    expect(fn () => $this->provider->currentCursor($this->mailbox))->toThrow(MailboxDisconnected::class);

    expect(GmailApi::requests(GmailApi::BASE.'*'))->toBeEmpty();
});

it('refreshes once on a 401 and retries with the new token', function (): void {
    GmailApi::fake([
        GmailApi::TOKEN_URL => Http::sequence()
            ->push(['access_token' => 'first', 'expires_in' => 3599])
            ->push(['access_token' => 'second', 'expires_in' => 3599]),
        'profile' => Http::sequence()
            ->push(GmailApi::fixture('error-unauthorized'), 401)
            ->push(GmailApi::fixture('profile')),
    ]);

    expect($this->provider->currentCursor($this->mailbox))->toBe('8842199');

    $calls = GmailApi::requests(GmailApi::BASE.'profile');
    expect($calls)->toHaveCount(2)
        ->and($calls[0]->hasHeader('Authorization', 'Bearer first'))->toBeTrue()
        ->and($calls[1]->hasHeader('Authorization', 'Bearer second'))->toBeTrue();
});

it('treats a second 401 as a disconnected mailbox, after one refresh only', function (): void {
    GmailApi::fake(['profile' => Http::response(GmailApi::fixture('error-unauthorized'), 401)]);

    expect(fn () => $this->provider->currentCursor($this->mailbox))->toThrow(MailboxDisconnected::class);

    expect(GmailApi::requests(GmailApi::TOKEN_URL))->toHaveCount(2)
        ->and(GmailApi::requests(GmailApi::BASE.'profile'))->toHaveCount(2);
});

it('reports a token endpoint that refuses the client for good as a disconnected mailbox', function (string $error, int $status): void {
    GmailApi::fake([GmailApi::TOKEN_URL => Http::response(['error' => $error, 'error_description' => 'Refused.'], $status)]);

    expect(fn () => $this->provider->currentCursor($this->mailbox))->toThrow(MailboxDisconnected::class);

    expect(GmailApi::requests(GmailApi::TOKEN_URL))->toHaveCount(1)
        ->and(GmailApi::requests(GmailApi::BASE.'*'))->toBeEmpty();
})->with([
    'invalid_client' => ['invalid_client', 401],
    'unauthorized_client' => ['unauthorized_client', 400],
]);

it('does not read other token failures as a disconnected mailbox', function (): void {
    GmailApi::fake([GmailApi::TOKEN_URL => Http::response(['error' => 'invalid_request'], 400)]);

    expect(fn () => $this->provider->currentCursor($this->mailbox))->toThrow(ProviderUnavailable::class);

    expect(GmailApi::requests(GmailApi::TOKEN_URL))->toHaveCount(1);
});

it('reports a grant without the access it needs as a disconnected mailbox', function (Closure $call, string $url): void {
    GmailApi::fake([$url => Http::response(GmailApi::fixture('error-insufficient-permissions'), 403)]);

    expect(fn () => $call($this->provider, $this->mailbox))->toThrow(MailboxDisconnected::class);

    expect(GmailApi::requests(GmailApi::BASE.$url))->toHaveCount(1)
        ->and($this->slept)->toBe([]);
})->with([
    'a read' => [fn ($provider, $mailbox) => $provider->currentCursor($mailbox), 'profile'],
    'a label write' => [fn ($provider, $mailbox) => $provider->archive($mailbox, 'm1'), 'messages/m1/modify'],
    'a send' => [fn ($provider, $mailbox) => $provider->send($mailbox, new OutgoingMessage(['a@vendor.example'], 'Hi', 'Body')), 'messages/send'],
]);

it('tells a person when the watcher meets a permanent refusal', function (string $route, array $response, int $status): void {
    Event::fake([MailboxLostAccess::class]);
    GmailApi::fake([$route => Http::response($response, $status)]);

    app(Watcher::class)->tick($this->mailbox);

    expect($this->mailbox->refresh()->status)->toBe(MailboxStatus::Disconnected);
    Event::assertDispatched(MailboxLostAccess::class);
})->with([
    'a regenerated client secret' => [GmailApi::TOKEN_URL, GmailApi::fixture('token-invalid-client'), 401],
    'a narrowed grant' => ['profile', GmailApi::fixture('error-insufficient-permissions'), 403],
]);

it('retries a token endpoint outage', function (): void {
    GmailApi::fake([
        GmailApi::TOKEN_URL => Http::sequence()
            ->push('', 503)
            ->push(GmailApi::fixture('token')),
        'profile' => Http::response(GmailApi::fixture('profile')),
    ]);

    expect($this->provider->currentCursor($this->mailbox))->toBe('8842199')
        ->and($this->slept)->toBe([1]);
});

it('lets a missing credential file surface', function (): void {
    GmailApi::fake();

    $this->provider->currentCursor(GmailApi::mailbox('nobody@example.com', 'no-such-identity'));
})->throws(MissingCredentials::class);
