<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Gmail;

use RobinsonRyan\Mailroom\Data\OutgoingMessage;

/**
 * Renders an OutgoingMessage as RFC 822: UTF-8, quoted-printable bodies,
 * multipart/alternative when there is html, an encoded-word subject.
 */
final class RawMessage
{
    private const EOL = "\r\n";

    /**
     * @param  string|null  $inReplyTo  the Message-ID being answered
     * @param  string|null  $references  the thread's References, ending in that Message-ID
     */
    public static function render(string $from, OutgoingMessage $message, ?string $inReplyTo = null, ?string $references = null): string
    {
        $headers = array_filter([
            'From' => self::headerValue($from),
            'To' => self::addresses($message->to),
            'Cc' => self::addresses($message->cc),
            'Subject' => mb_encode_mimeheader(self::headerValue($message->subject), 'UTF-8', 'B', self::EOL, strlen('Subject: ')),
            'Date' => now()->toRfc2822String(),
            'In-Reply-To' => self::headerValue((string) $inReplyTo),
            'References' => self::headerValue((string) $references),
            'MIME-Version' => '1.0',
        ], fn (string $value): bool => $value !== '');

        if ($message->html === null) {
            return self::entity([...$headers, ...self::partHeaders('text/plain')], self::quotedPrintable($message->text));
        }

        // QP output never contains "=_", so this cannot collide with a body line.
        $boundary = '=_mailroom_'.bin2hex(random_bytes(16));
        $body = '--'.$boundary.self::EOL
            .self::entity(self::partHeaders('text/plain'), self::quotedPrintable($message->text)).self::EOL
            .'--'.$boundary.self::EOL
            .self::entity(self::partHeaders('text/html'), self::quotedPrintable($message->html)).self::EOL
            .'--'.$boundary.'--'.self::EOL;

        return self::entity([...$headers, 'Content-Type' => "multipart/alternative; boundary=\"{$boundary}\""], $body);
    }

    /** @param  array<string, string>  $headers */
    private static function entity(array $headers, string $body): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode(self::EOL, $lines).self::EOL.self::EOL.$body;
    }

    /** @return array<string, string> */
    private static function partHeaders(string $mime): array
    {
        return ['Content-Type' => "{$mime}; charset=UTF-8", 'Content-Transfer-Encoding' => 'quoted-printable'];
    }

    private static function quotedPrintable(string $body): string
    {
        return quoted_printable_encode((string) preg_replace('/\r\n|\r|\n/', self::EOL, $body));
    }

    /** @param  list<string>  $addresses */
    private static function addresses(array $addresses): string
    {
        return implode(', ', array_map(self::headerValue(...), $addresses));
    }

    /** A line break in a value would start a header of the caller's choosing. */
    private static function headerValue(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}
