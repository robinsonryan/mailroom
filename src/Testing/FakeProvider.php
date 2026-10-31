<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Testing;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Contracts\MailProvider;
use RobinsonRyan\Mailroom\Data\Attachment;
use RobinsonRyan\Mailroom\Data\HistoryChange;
use RobinsonRyan\Mailroom\Data\HistoryPage;
use RobinsonRyan\Mailroom\Data\MessageContent;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Data\MessageSummary;
use RobinsonRyan\Mailroom\Data\OutgoingMessage;
use RobinsonRyan\Mailroom\Enums\ChangeKind;
use RobinsonRyan\Mailroom\Exceptions\CursorExpired;
use RobinsonRyan\Mailroom\Exceptions\MailboxDisconnected;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Models\Mailbox;
use RobinsonRyan\Mailroom\ProviderRegistry;
use RobinsonRyan\Mailroom\Support\HtmlText;

/**
 * An in-memory mail system for consumers' tests. Behaves like Gmail where it
 * matters: every label change (including Mailroom's own) is echoed into the
 * history; archive removes INBOX; cursors can be expired; a mailbox can be
 * disconnected.
 *
 *     $fake = FakeProvider::install();               // replaces 'gmail'
 *     $id = $fake->deliver('info@x.com', from: 'Vendor <a@v.com>', subject: 'Invoice 1',
 *         attachments: [['name' => 'inv.pdf', 'mime' => 'application/pdf', 'bytes' => '%PDF']]);
 *     $fake->personLabels('info@x.com', $id, add: ['Invoices/Invoice']);
 */
final class FakeProvider implements MailProvider
{
    private const IGNORED_LABELS = ['DRAFT', 'SPAM', 'TRASH'];

    private const RESCUE_LABELS = ['SPAM', 'TRASH'];

    /** @var array<string, array<string, array{thread: string, from: string, to: string, subject: string, text: ?string, html: ?string, received: CarbonImmutable, labels: list<string>, attachments: list<array{name: string, mime: string, bytes: string}>}>> */
    private array $messages = [];

    /** @var array<string, list<array{cursor: int, change: HistoryChange}>> */
    private array $history = [];

    private int $cursor = 100;

    /** @var array<string, int> history older than this cursor is gone, per mailbox */
    private array $expiredBefore = [];

    /** @var array<string, bool> */
    private array $disconnected = [];

    /** @var list<array{mailbox: string, message: OutgoingMessage}> */
    public array $sent = [];

    /** @var list<array{mailbox: string, message: OutgoingMessage}> */
    public array $drafts = [];

    /** @var list<array{mailbox: string, raw: string, labels: list<string>}> */
    public array $inserted = [];

    /** @var list<array{mailbox: string, id: string}> */
    public array $contentFetches = [];

    public static function install(string $as = 'gmail'): self
    {
        $fake = new self;
        app(ProviderRegistry::class)->swap($as, $fake);

        return $fake;
    }

    /**
     * A new message arrives in the inbox. Returns its provider message id.
     *
     * @param  list<array{name: string, mime: string, bytes: string}>  $attachments
     * @param  list<string>  $labels  extra labels besides INBOX
     */
    public function deliver(
        string $mailbox,
        string $from = 'Sender <sender@example.com>',
        string $subject = 'Hello',
        ?string $text = 'Body',
        ?string $html = null,
        array $attachments = [],
        ?CarbonImmutable $receivedAt = null,
        string $to = '',
        array $labels = [],
        ?string $id = null,
        ?string $thread = null,
    ): string {
        $mailbox = strtolower($mailbox);
        $id ??= 'm'.(++$this->cursor);
        $thread ??= 't'.$id;
        $this->messages[$mailbox][$id] = [
            'thread' => $thread,
            'from' => $from,
            'to' => $to === '' ? $mailbox : $to,
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
            'received' => $receivedAt ?? CarbonImmutable::now(),
            'labels' => array_values(array_unique(['INBOX', ...$labels])),
            'attachments' => $attachments,
        ];
        $this->recordArrival($mailbox, $id);

        return $id;
    }

    /**
     * A person changes labels in the mail client (a correction, as the watcher sees it).
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    public function personLabels(string $mailbox, string $id, array $add = [], array $remove = []): void
    {
        $this->applyLabels(strtolower($mailbox), $id, $add, $remove);
    }

    /** Make history before the current cursor unavailable (Gmail's 404). */
    public function expireHistory(string $mailbox): void
    {
        $this->expiredBefore[strtolower($mailbox)] = $this->cursor + 1;
    }

    public function disconnect(string $mailbox): void
    {
        $this->disconnected[strtolower($mailbox)] = true;
    }

    /** @return list<string> */
    public function labelsOf(string $mailbox, string $id): array
    {
        return $this->find(strtolower($mailbox), $id)['labels'];
    }

    public function isArchived(string $mailbox, string $id): bool
    {
        return ! in_array('INBOX', $this->labelsOf($mailbox, $id), true);
    }

    public function ref(Mailbox $mailbox, string $id): MessageRef
    {
        $m = $this->find($mailbox->address, $id);

        return new MessageRef($mailbox->id, $id, $m['thread'], $m['received']);
    }

    public function currentCursor(Mailbox $mailbox): string
    {
        $this->guard($mailbox);

        return (string) $this->cursor;
    }

    public function history(Mailbox $mailbox, string $cursor): HistoryPage
    {
        $this->guard($mailbox);
        $address = $mailbox->address;

        if ((int) $cursor < ($this->expiredBefore[$address] ?? 0)) {
            throw new CursorExpired("History before {$cursor} is gone.");
        }

        $changes = [];
        foreach ($this->history[$address] ?? [] as $entry) {
            if ($entry['cursor'] > (int) $cursor) {
                $changes[] = $entry['change'];
            }
        }

        return new HistoryPage($changes, (string) $this->cursor);
    }

    public function search(Mailbox $mailbox, string $query, int $limit = 500): array
    {
        $this->guard($mailbox);
        $ids = array_keys($this->messages[$mailbox->address] ?? []);
        usort($ids, fn (string $a, string $b): int => $this->messages[$mailbox->address][$b]['received'] <=> $this->messages[$mailbox->address][$a]['received']);

        return array_slice(array_map(strval(...), $ids), 0, $limit);
    }

    public function summary(Mailbox $mailbox, string $providerMessageId, int $textLimit): MessageSummary
    {
        $this->guard($mailbox);
        $m = $this->find($mailbox->address, $providerMessageId);
        $text = $m['text'] ?? HtmlText::from((string) $m['html']);

        return new MessageSummary(
            $providerMessageId,
            $m['thread'],
            $m['received'],
            $m['from'],
            $m['subject'],
            array_column($m['attachments'], 'name'),
            mb_substr($text, 0, $textLimit),
            $m['labels'],
        );
    }

    public function content(Mailbox $mailbox, string $providerMessageId): MessageContent
    {
        $this->guard($mailbox);
        $this->contentFetches[] = ['mailbox' => $mailbox->address, 'id' => $providerMessageId];
        $m = $this->find($mailbox->address, $providerMessageId);
        $attachments = [];
        foreach ($m['attachments'] as $i => $a) {
            $attachments[] = new Attachment((string) $i, $a['name'], $a['mime'], strlen($a['bytes']));
        }

        return new MessageContent(
            new MessageRef($mailbox->id, $providerMessageId, $m['thread'], $m['received']),
            ['From' => $m['from'], 'To' => $m['to'], 'Subject' => $m['subject'], 'Date' => $m['received']->toRfc2822String()],
            $m['from'],
            $m['to'],
            $m['subject'],
            $m['text'],
            $m['html'],
            $attachments,
        );
    }

    public function attachment(Mailbox $mailbox, string $providerMessageId, string $partId): string
    {
        $this->guard($mailbox);
        $m = $this->find($mailbox->address, $providerMessageId);

        $part = ctype_digit($partId) ? ($m['attachments'][(int) $partId] ?? null) : null;

        return $part['bytes'] ?? throw new MessageNotFound("No part {$partId}.");
    }

    public function changeLabels(Mailbox $mailbox, string $providerMessageId, array $add, array $remove): void
    {
        $this->guard($mailbox);
        $this->applyLabels($mailbox->address, $providerMessageId, $add, $remove);
    }

    public function archive(Mailbox $mailbox, string $providerMessageId): void
    {
        $this->guard($mailbox);
        $this->applyLabels($mailbox->address, $providerMessageId, [], ['INBOX']);
    }

    public function insert(Mailbox $mailbox, string $rawMime, array $labels = []): string
    {
        $this->guard($mailbox);
        $this->inserted[] = ['mailbox' => $mailbox->address, 'raw' => $rawMime, 'labels' => $labels];

        $id = 'ins'.(++$this->cursor);
        [$head, $body] = explode("\r\n\r\n", $rawMime, 2) + [1 => ''];
        preg_match_all('/^([\w-]+):\s*(.*)$/m', $head, $headers);
        $headers = array_combine(array_map(strtolower(...), $headers[1]), array_map(trim(...), $headers[2]));

        $this->messages[$mailbox->address][$id] = [
            'thread' => 't'.$id,
            'from' => $headers['from'] ?? '',
            'to' => $headers['to'] ?? $mailbox->address,
            'subject' => $headers['subject'] ?? '',
            'text' => $body,
            'html' => null,
            'received' => CarbonImmutable::now(),
            'labels' => $labels,
            'attachments' => [],
        ];
        $this->recordArrival($mailbox->address, $id);

        return $id;
    }

    public function draft(Mailbox $mailbox, OutgoingMessage $message): string
    {
        $this->guard($mailbox);
        $this->assertRepliable($mailbox, $message);
        $this->drafts[] = ['mailbox' => $mailbox->address, 'message' => $message];

        return 'd'.(++$this->cursor);
    }

    public function send(Mailbox $mailbox, OutgoingMessage $message): string
    {
        $this->guard($mailbox);
        $this->assertRepliable($mailbox, $message);
        $this->sent[] = ['mailbox' => $mailbox->address, 'message' => $message];

        return 's'.(++$this->cursor);
    }

    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    private function applyLabels(string $mailbox, string $id, array $add, array $remove): void
    {
        $m = $this->find($mailbox, $id);
        $before = $m['labels'];
        $after = array_values(array_unique(array_diff([...$before, ...$add], $remove)));
        $this->messages[$mailbox][$id]['labels'] = $after;

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if (array_intersect($after, self::IGNORED_LABELS) !== []) {
            return;
        }

        if (array_intersect($removed, self::RESCUE_LABELS) !== []) {
            $this->recordArrival($mailbox, $id);
        }

        if ($added !== []) {
            $this->record($mailbox, new HistoryChange(ChangeKind::LabelAdded, $id, $m['thread'], $added));
        }

        if ($removed !== []) {
            $this->record($mailbox, new HistoryChange(ChangeKind::LabelRemoved, $id, $m['thread'], $removed));
        }
    }

    /** As Gmail's adapter does: mail in Spam, Trash or Drafts has not arrived yet. */
    private function recordArrival(string $mailbox, string $id): void
    {
        $m = $this->messages[$mailbox][$id];

        if (array_intersect($m['labels'], self::IGNORED_LABELS) === []) {
            $this->record($mailbox, new HistoryChange(ChangeKind::MessageAdded, $id, $m['thread']));
        }
    }

    private function assertRepliable(Mailbox $mailbox, OutgoingMessage $message): void
    {
        if ($message->inReplyTo !== null) {
            $this->find($mailbox->address, $message->inReplyTo);
        }
    }

    private function record(string $mailbox, HistoryChange $change): void
    {
        $this->history[$mailbox][] = ['cursor' => ++$this->cursor, 'change' => $change];
    }

    /**
     * @return array{thread: string, from: string, to: string, subject: string, text: ?string, html: ?string, received: CarbonImmutable, labels: list<string>, attachments: list<array{name: string, mime: string, bytes: string}>}
     */
    private function find(string $mailbox, string $id): array
    {
        return $this->messages[strtolower($mailbox)][$id] ?? throw new MessageNotFound("No message {$id} in {$mailbox}.");
    }

    private function guard(Mailbox $mailbox): void
    {
        if ($this->disconnected[$mailbox->address] ?? false) {
            throw new MailboxDisconnected("{$mailbox->address} refused its credentials.");
        }
    }
}
