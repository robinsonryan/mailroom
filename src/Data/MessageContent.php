<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Data;

/**
 * The full message as Contract A's message(ref) returns it: headers, both body
 * parts and attachment metadata.
 */
final readonly class MessageContent
{
    /**
     * @param  array<string, string>  $headers  header name (as sent) => value; last one wins
     * @param  list<Attachment>  $attachments
     */
    public function __construct(
        public MessageRef $ref,
        public array $headers,
        public string $from,
        public string $to,
        public string $subject,
        public ?string $text,
        public ?string $html,
        public array $attachments,
    ) {}
}
