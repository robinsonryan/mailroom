<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Data;

/**
 * A message to draft or send. $inReplyTo is the provider message id of the
 * message being answered, which also places the new one in its thread.
 */
final readonly class OutgoingMessage
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public function __construct(
        public array $to,
        public string $subject,
        public string $text,
        public ?string $html = null,
        public array $cc = [],
        public ?string $inReplyTo = null,
    ) {}
}
