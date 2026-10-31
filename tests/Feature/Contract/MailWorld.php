<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Tests\Feature\Contract;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Contracts\MailProvider;
use RobinsonRyan\Mailroom\Models\Mailbox;

/**
 * A mailbox with one adapter in front of it. The contract suite arranges mail
 * only through this, so one test body runs against every adapter.
 */
interface MailWorld
{
    public function provider(): MailProvider;

    public function mailbox(): Mailbox;

    /**
     * @param  list<array{name: string, mime: string, bytes: string}>  $attachments
     * @param  list<string>  $labels  extra labels besides INBOX
     */
    public function deliver(
        string $from = 'Sender <sender@example.com>',
        string $subject = 'Hello',
        ?string $text = 'Body',
        ?string $html = null,
        array $attachments = [],
        ?CarbonImmutable $receivedAt = null,
        array $labels = [],
    ): string;

    /**
     * A person changes labels in the mail client.
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function personLabels(string $id, array $add = [], array $remove = []): void;

    public function expireHistory(): void;

    public function disconnect(): void;

    /** @return list<string> */
    public function labelsOf(string $id): array;
}
