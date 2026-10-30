<?php

declare(strict_types=1);

use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RobinsonRyan\Mailroom\Data\OutgoingMessage;
use RobinsonRyan\Mailroom\Exceptions\ProviderUnavailable;
use RobinsonRyan\Mailroom\Tests\Feature\Gmail\GmailApi;

beforeEach(function (): void {
    $this->slept = [];
    $this->provider = GmailApi::provider($this->slept);
    $this->mailbox = GmailApi::mailbox();
});

it('backs off and retries a transient failure', function (string $fixture, int $status): void {
    GmailApi::fake([
        'profile' => Http::sequence()
            ->push(GmailApi::fixture($fixture), $status)
            ->push(GmailApi::fixture('profile')),
    ]);

    expect($this->provider->currentCursor($this->mailbox))->toBe('8842199')
        ->and($this->slept)->toBe([1]);
})->with([
    '429' => ['error-rate-limit', 429],
    '500' => ['error-backend', 500],
    '503' => ['error-backend', 503],
    '403 rateLimitExceeded' => ['error-rate-limit', 403],
    '403 userRateLimitExceeded' => ['error-user-rate-limit', 403],
]);

it('gives up after 1s, 4s and 16s', function (): void {
    GmailApi::fake(['profile' => Http::response(GmailApi::fixture('error-backend'), 503)]);

    expect(fn () => $this->provider->currentCursor($this->mailbox))->toThrow(ProviderUnavailable::class);

    expect($this->slept)->toBe([1, 4, 16])
        ->and(GmailApi::requests(GmailApi::BASE.'profile'))->toHaveCount(4);
});

it('waits as long as Retry-After asks', function (): void {
    GmailApi::fake([
        'profile' => Http::sequence()
            ->push(GmailApi::fixture('error-rate-limit'), 429, ['Retry-After' => '7'])
            ->push(GmailApi::fixture('profile')),
    ]);

    $this->provider->currentCursor($this->mailbox);

    expect($this->slept)->toBe([7]);
});

it('reads Retry-After as an HTTP date too', function (): void {
    $this->freezeTime();
    GmailApi::fake([
        'profile' => Http::sequence()
            ->push(GmailApi::fixture('error-rate-limit'), 429, ['Retry-After' => now()->addSeconds(30)->toRfc7231String()])
            ->push(GmailApi::fixture('profile')),
    ]);

    $this->provider->currentCursor($this->mailbox);

    expect($this->slept)->toBe([30]);
});

it('never waits less than the backoff', function (): void {
    GmailApi::fake([
        'profile' => Http::sequence()
            ->push(GmailApi::fixture('error-backend'), 503)
            ->push(GmailApi::fixture('error-rate-limit'), 429, ['Retry-After' => '2'])
            ->push(GmailApi::fixture('profile')),
    ]);

    $this->provider->currentCursor($this->mailbox);

    expect($this->slept)->toBe([1, 4]);
});

it('does not retry a 403 that is not about rate', function (): void {
    GmailApi::fake(['profile' => Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']]]], 403)]);

    expect(fn () => $this->provider->currentCursor($this->mailbox))->toThrow(ProviderUnavailable::class);

    expect($this->slept)->toBe([])
        ->and(GmailApi::requests(GmailApi::BASE.'profile'))->toHaveCount(1);
});

it('retries a dropped connection and reports it as unavailable', function (): void {
    GmailApi::fake([
        'profile' => fn (): GuzzleHttp\Promise\PromiseInterface => Create::rejectionFor(new ConnectionException('cURL error 28')),
    ]);

    expect(fn () => $this->provider->currentCursor($this->mailbox))->toThrow(ProviderUnavailable::class);

    expect($this->slept)->toBe([1, 4, 16]);
});

it('never repeats a send after a 5xx, which may mean Gmail accepted it', function (): void {
    GmailApi::fake(['messages/send' => Http::response(GmailApi::fixture('error-backend'), 503)]);

    expect(fn () => $this->provider->send($this->mailbox, new OutgoingMessage(['a@vendor.example'], 'Hi', 'Body')))
        ->toThrow(ProviderUnavailable::class);

    expect(GmailApi::requests(GmailApi::BASE.'messages/send'))->toHaveCount(1);
});

it('retries a send refused by the rate limit, which Gmail did not accept', function (): void {
    GmailApi::fake(['messages/send' => Http::sequence()
        ->push(GmailApi::fixture('error-rate-limit'), 429)
        ->push(['id' => 'sent1', 'threadId' => 't1'])]);

    expect($this->provider->send($this->mailbox, new OutgoingMessage(['a@vendor.example'], 'Hi', 'Body')))->toBe('sent1');
});

it('caps a long Retry-After at a minute', function (): void {
    GmailApi::fake(['profile' => Http::sequence()
        ->push(GmailApi::fixture('error-rate-limit'), 429, ['Retry-After' => '86400'])
        ->push(GmailApi::fixture('profile'))]);

    $this->provider->currentCursor($this->mailbox);

    expect($this->slept)->toBe([60]);
});
