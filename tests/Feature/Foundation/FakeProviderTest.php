<?php

declare(strict_types=1);

use RobinsonRyan\Mailroom\Enums\ChangeKind;
use RobinsonRyan\Mailroom\Exceptions\CursorExpired;
use RobinsonRyan\Mailroom\Exceptions\MailboxDisconnected;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Testing\FakeProvider;

beforeEach(function (): void {
    $this->fake = FakeProvider::install();
    $this->mailbox = app(Mailroom::class)->connect('info@example.com', 'shop-info');
});

it('reports new messages and label changes after a cursor, as Gmail history does', function (): void {
    $cursor = $this->fake->currentCursor($this->mailbox);
    $id = $this->fake->deliver('info@example.com');
    $this->fake->personLabels('info@example.com', $id, add: ['Invoices/Invoice']);

    $page = $this->fake->history($this->mailbox, $cursor);

    expect(array_map(fn ($c) => $c->kind, $page->changes))->toBe([ChangeKind::MessageAdded, ChangeKind::LabelAdded])
        ->and($page->changes[1]->labels)->toBe(['Invoices/Invoice'])
        ->and($this->fake->history($this->mailbox, $page->cursor)->changes)->toBe([]);
});

it('throws CursorExpired for history it no longer holds', function (): void {
    $cursor = $this->fake->currentCursor($this->mailbox);
    $this->fake->deliver('info@example.com');
    $this->fake->expireHistory('info@example.com');

    $this->fake->history($this->mailbox, $cursor);
})->throws(CursorExpired::class);

it('throws MailboxDisconnected once disconnected', function (): void {
    $this->fake->disconnect('info@example.com');

    $this->fake->currentCursor($this->mailbox);
})->throws(MailboxDisconnected::class);

it('cuts summary text to the limit and lists attachment names', function (): void {
    $id = $this->fake->deliver('info@example.com', text: str_repeat('a', 50), attachments: [['name' => 'INV-1.pdf', 'mime' => 'application/pdf', 'bytes' => 'x']]);

    $summary = $this->fake->summary($this->mailbox, $id, 10);

    expect($summary->text)->toBe('aaaaaaaaaa')->and($summary->attachmentNames)->toBe(['INV-1.pdf']);
});
