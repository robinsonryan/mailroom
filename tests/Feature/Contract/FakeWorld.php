<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Tests\Feature\Contract;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Contracts\MailProvider;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\Mailbox;
use RobinsonRyan\Mailroom\Testing\FakeProvider;

final readonly class FakeWorld implements MailWorld
{
    private const ADDRESS = 'info@example.com';

    private FakeProvider $fake;

    private Mailbox $mailbox;

    public function __construct()
    {
        $this->fake = FakeProvider::install();
        $this->mailbox = app(Mailroom::class)->connect(self::ADDRESS, 'afwd-info');
    }

    public function provider(): MailProvider
    {
        return $this->fake;
    }

    public function mailbox(): Mailbox
    {
        return $this->mailbox;
    }

    public function deliver(
        string $from = 'Sender <sender@example.com>',
        string $subject = 'Hello',
        ?string $text = 'Body',
        ?string $html = null,
        array $attachments = [],
        ?CarbonImmutable $receivedAt = null,
        array $labels = [],
    ): string {
        return $this->fake->deliver(self::ADDRESS, $from, $subject, $text, $html, $attachments, $receivedAt, labels: $labels);
    }

    public function personLabels(string $id, array $add = [], array $remove = []): void
    {
        $this->fake->personLabels(self::ADDRESS, $id, $add, $remove);
    }

    public function expireHistory(): void
    {
        $this->fake->expireHistory(self::ADDRESS);
    }

    public function disconnect(): void
    {
        $this->fake->disconnect(self::ADDRESS);
    }

    public function labelsOf(string $id): array
    {
        return $this->fake->labelsOf(self::ADDRESS, $id);
    }
}
