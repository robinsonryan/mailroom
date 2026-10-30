<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Webhooks;

use Carbon\CarbonImmutable;

/**
 * The `X-Mailroom-Signature` header: `t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>">`.
 */
final class Signature
{
    public const HEADER = 'X-Mailroom-Signature';

    private const WINDOW_SECONDS = 300;

    public static function header(string $body, string $secret, int $timestamp): string
    {
        return "t={$timestamp},v1=".self::hmac($body, $secret, $timestamp);
    }

    /** For receivers: true when $header signs $body and is under five minutes old. */
    public static function verify(string $header, string $body, string $secret): bool
    {
        if (preg_match('/\At=(\d+),v1=([0-9a-f]{64})\z/', $header, $parts) !== 1) {
            return false;
        }

        $timestamp = (int) $parts[1];

        if (abs(CarbonImmutable::now()->getTimestamp() - $timestamp) > self::WINDOW_SECONDS) {
            return false;
        }

        return hash_equals(self::hmac($body, $secret, $timestamp), $parts[2]);
    }

    private static function hmac(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}
