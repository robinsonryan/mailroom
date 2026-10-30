<?php

declare(strict_types=1);

use RobinsonRyan\Mailroom\Webhooks\Signature;

it('signs the timestamp and body as hex HMAC-SHA256 in the documented format', function (): void {
    $header = Signature::header('{"a":1}', 'shh', 1700000000);

    expect($header)->toBe('t=1700000000,v1='.hash_hmac('sha256', '1700000000.{"a":1}', 'shh'));
});

it('accepts a fresh signature', function (): void {
    $header = Signature::header('body', 'shh', Carbon\CarbonImmutable::now()->getTimestamp());

    expect(Signature::verify($header, 'body', 'shh'))->toBeTrue();
});

it('accepts a signature exactly five minutes old and rejects anything older', function (): void {
    $this->freezeTime();
    $now = Carbon\CarbonImmutable::now()->getTimestamp();

    expect(Signature::verify(Signature::header('body', 'shh', $now - 300), 'body', 'shh'))->toBeTrue()
        ->and(Signature::verify(Signature::header('body', 'shh', $now - 301), 'body', 'shh'))->toBeFalse();
});

it('rejects a timestamp far in the future', function (): void {
    $future = Carbon\CarbonImmutable::now()->getTimestamp() + 3600;

    expect(Signature::verify(Signature::header('body', 'shh', $future), 'body', 'shh'))->toBeFalse();
});

it('rejects a changed body, a wrong secret and a forged timestamp', function (): void {
    $now = Carbon\CarbonImmutable::now()->getTimestamp();
    $header = Signature::header('body', 'shh', $now);
    $forged = preg_replace('/t=\d+/', 't='.($now - 1), $header);

    expect(Signature::verify($header, 'other', 'shh'))->toBeFalse()
        ->and(Signature::verify($header, 'body', 'wrong'))->toBeFalse()
        ->and(Signature::verify((string) $forged, 'body', 'shh'))->toBeFalse();
});

it('rejects a malformed header', function (string $header): void {
    expect(Signature::verify($header, 'body', 'shh'))->toBeFalse();
})->with(['', 'nonsense', 't=abc,v1=abc', 'v1=abc,t=1']);
