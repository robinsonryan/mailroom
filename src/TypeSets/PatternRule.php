<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\TypeSets;

use RobinsonRyan\Mailroom\Data\MessageSummary;

/**
 * Matches when every given pattern matches (PCRE, case-insensitive):
 * $from against the From header, $subject against the subject, and
 * $attachment against at least one attachment name.
 */
final readonly class PatternRule implements Rule
{
    public function __construct(
        public string $type,
        public ?string $from = null,
        public ?string $subject = null,
        public ?string $attachment = null,
    ) {}

    public function match(MessageSummary $message): ?string
    {
        if ($this->from === null && $this->subject === null && $this->attachment === null) {
            return null;
        }

        if ($this->from !== null && preg_match($this->wrap($this->from), $message->from) !== 1) {
            return null;
        }

        if ($this->subject !== null && preg_match($this->wrap($this->subject), $message->subject) !== 1) {
            return null;
        }

        if ($this->attachment !== null && preg_grep($this->wrap($this->attachment), $message->attachmentNames) === []) {
            return null;
        }

        return $this->type;
    }

    private function wrap(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~i';
    }
}
