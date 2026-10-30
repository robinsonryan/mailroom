<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Data;

use Carbon\CarbonImmutable;

/**
 * What typing needs from a message: cheap to fetch (metadata plus text parts),
 * no attachment bytes, body text cut to the configured length.
 */
final readonly class MessageSummary
{
    /**
     * @param  list<string>  $attachmentNames
     * @param  list<string>  $labels  label names, provider-neutral
     */
    public function __construct(
        public string $providerMessageId,
        public string $threadId,
        public CarbonImmutable $receivedAt,
        public string $from,
        public string $subject,
        public array $attachmentNames,
        public string $text,
        public array $labels,
    ) {}
}
