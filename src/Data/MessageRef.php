<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Data;

use Carbon\CarbonImmutable;

/**
 * How every caller names a message (Contract A): the mailbox row id, the
 * provider's own message id, its thread, and when it arrived.
 */
final readonly class MessageRef
{
    public function __construct(
        public string $mailboxId,
        public string $providerMessageId,
        public string $threadId,
        public CarbonImmutable $receivedAt,
    ) {}

    /**
     * @return array{mailbox_id: string, provider_message_id: string, thread_id: string, received_at: string}
     */
    public function toArray(): array
    {
        return [
            'mailbox_id' => $this->mailboxId,
            'provider_message_id' => $this->providerMessageId,
            'thread_id' => $this->threadId,
            'received_at' => $this->receivedAt->toIso8601String(),
        ];
    }
}
