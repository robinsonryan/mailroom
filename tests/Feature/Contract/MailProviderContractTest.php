<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Data\Attachment;
use RobinsonRyan\Mailroom\Data\HistoryChange;
use RobinsonRyan\Mailroom\Data\HistoryPage;
use RobinsonRyan\Mailroom\Data\OutgoingMessage;
use RobinsonRyan\Mailroom\Enums\ChangeKind;
use RobinsonRyan\Mailroom\Exceptions\CursorExpired;
use RobinsonRyan\Mailroom\Exceptions\MailboxDisconnected;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Tests\Feature\Contract\FakeWorld;
use RobinsonRyan\Mailroom\Tests\Feature\Contract\GmailWorld;
use RobinsonRyan\Mailroom\Tests\Feature\Contract\MailWorld;

/**
 * What every adapter owes the rest of Mailroom. Hub tests run on the fake, so
 * a difference between these two is a difference tests can't see in production.
 */
dataset('adapters', [
    'fake' => fn (): MailWorld => new FakeWorld,
    'gmail' => fn (): MailWorld => new GmailWorld,
]);

/** @return list<array{ChangeKind, string, list<string>}> */
function changes(HistoryPage $page): array
{
    return array_map(fn (HistoryChange $c): array => [$c->kind, $c->providerMessageId, $c->labels], $page->changes);
}

function chatter(MailWorld $mail): string
{
    return $mail->deliver(subject: 'Chatter');
}

/** One contract method on message $id, named so a dataset can list them. */
function call(MailWorld $mail, string $call, string $id): void
{
    $provider = $mail->provider();
    $mailbox = $mail->mailbox();
    $message = new OutgoingMessage(['a@vendor.example'], 'Hello', 'Body');
    $reply = new OutgoingMessage(['a@vendor.example'], 'Re: Hello', 'Body', inReplyTo: $id);

    match ($call) {
        'currentCursor' => $provider->currentCursor($mailbox),
        'history' => $provider->history($mailbox, '1'),
        'search' => $provider->search($mailbox, 'in:inbox'),
        'summary' => $provider->summary($mailbox, $id, 10),
        'content' => $provider->content($mailbox, $id),
        'attachment' => $provider->attachment($mailbox, $id, '0'),
        'changeLabels' => $provider->changeLabels($mailbox, $id, ['Invoices/Invoice'], []),
        'archive' => $provider->archive($mailbox, $id),
        'insert' => $provider->insert($mailbox, "From: a@vendor.example\r\n\r\nBody\r\n"),
        'draft' => $provider->draft($mailbox, $message),
        'send' => $provider->send($mailbox, $message),
        'draft a reply' => $provider->draft($mailbox, $reply),
        'send a reply' => $provider->send($mailbox, $reply),
    };
}

it('starts from now: a new cursor has nothing after it', function (MailWorld $mail): void {
    chatter($mail);

    $cursor = $mail->provider()->currentCursor($mail->mailbox());

    expect($mail->provider()->history($mail->mailbox(), $cursor)->changes)->toBe([]);
})->with('adapters');

it('reports arrivals and label changes in order, by label name, then nothing more', function (MailWorld $mail): void {
    $provider = $mail->provider();
    $cursor = $provider->currentCursor($mail->mailbox());

    $id = chatter($mail);
    $mail->personLabels($id, add: ['Invoices/Invoice']);
    $mail->personLabels($id, remove: ['Invoices/Invoice']);

    $page = $provider->history($mail->mailbox(), $cursor);

    expect(changes($page))->toBe([
        [ChangeKind::MessageAdded, $id, []],
        [ChangeKind::LabelAdded, $id, ['Invoices/Invoice']],
        [ChangeKind::LabelRemoved, $id, ['Invoices/Invoice']],
    ])->and($provider->history($mail->mailbox(), $page->cursor)->changes)->toBe([]);
})->with('adapters');

it('echoes its own label changes into history', function (MailWorld $mail): void {
    $provider = $mail->provider();
    $id = chatter($mail);
    $cursor = $provider->currentCursor($mail->mailbox());

    $provider->changeLabels($mail->mailbox(), $id, ['Invoices/Filed'], []);
    $provider->archive($mail->mailbox(), $id);

    expect(changes($provider->history($mail->mailbox(), $cursor)))->toBe([
        [ChangeKind::LabelAdded, $id, ['Invoices/Filed']],
        [ChangeKind::LabelRemoved, $id, ['INBOX']],
    ]);
})->with('adapters');

it('passes over mail that lands in spam, trash or drafts', function (MailWorld $mail, string $label): void {
    $cursor = $mail->provider()->currentCursor($mail->mailbox());

    $id = $mail->deliver(labels: [$label]);
    $mail->personLabels($id, add: ['Invoices/Invoice']);

    expect($mail->provider()->history($mail->mailbox(), $cursor)->changes)->toBe([]);
})->with('adapters')->with(['SPAM', 'TRASH', 'DRAFT']);

it('reports mail rescued from spam or trash as an arrival', function (MailWorld $mail, string $label): void {
    $id = $mail->deliver(labels: [$label]);
    $cursor = $mail->provider()->currentCursor($mail->mailbox());

    $mail->personLabels($id, remove: [$label]);

    expect(changes($mail->provider()->history($mail->mailbox(), $cursor)))->toBe([
        [ChangeKind::MessageAdded, $id, []],
        [ChangeKind::LabelRemoved, $id, [$label]],
    ]);
})->with('adapters')->with(['SPAM', 'TRASH']);

it('refuses history older than it holds', function (MailWorld $mail): void {
    $cursor = $mail->provider()->currentCursor($mail->mailbox());
    chatter($mail);
    $mail->expireHistory();

    $mail->provider()->history($mail->mailbox(), $cursor);
})->with('adapters')->throws(CursorExpired::class);

it('lists messages newest first, up to the limit', function (MailWorld $mail): void {
    $old = $mail->deliver(receivedAt: CarbonImmutable::parse('2026-01-01 12:00:00 UTC'));
    $new = $mail->deliver(receivedAt: CarbonImmutable::parse('2026-03-01 12:00:00 UTC'));
    $mid = $mail->deliver(receivedAt: CarbonImmutable::parse('2026-02-01 12:00:00 UTC'));

    expect($mail->provider()->search($mail->mailbox(), 'in:inbox'))->toBe([$new, $mid, $old])
        ->and($mail->provider()->search($mail->mailbox(), 'in:inbox', 2))->toBe([$new, $mid]);
})->with('adapters');

it('summarises a message: sender, subject, date, attachment names, labels, cut text', function (MailWorld $mail): void {
    $received = CarbonImmutable::parse('2026-02-03 04:05:06 UTC');
    $id = $mail->deliver(
        from: 'Vendor <billing@vendor.example>',
        subject: 'Invoice 1',
        text: str_repeat('é', 50),
        attachments: [['name' => 'INV-1.pdf', 'mime' => 'application/pdf', 'bytes' => '%PDF-1']],
        receivedAt: $received,
        labels: ['Invoices/Invoice'],
    );

    $summary = $mail->provider()->summary($mail->mailbox(), $id, 10);

    expect($summary->providerMessageId)->toBe($id)
        ->and($summary->threadId)->not->toBe('')
        ->and($summary->receivedAt->equalTo($received))->toBeTrue()
        ->and($summary->from)->toBe('Vendor <billing@vendor.example>')
        ->and($summary->subject)->toBe('Invoice 1')
        ->and($summary->attachmentNames)->toBe(['INV-1.pdf'])
        ->and($summary->text)->toBe(str_repeat('é', 10))
        ->and($summary->labels)->toEqualCanonicalizing(['INBOX', 'Invoices/Invoice']);
})->with('adapters');

it('reduces html to its words when there is no plain text', function (MailWorld $mail): void {
    $id = $mail->deliver(text: null, html: '<html><head><style>p { color: red }</style></head><body><p>Total &amp; due</p><p>$10</p></body></html>');

    expect($mail->provider()->summary($mail->mailbox(), $id, 100)->text)->toBe('Total & due $10');
})->with('adapters');

it('gives the whole message, and each attachment by the part id it reports', function (MailWorld $mail): void {
    $id = $mail->deliver(
        from: 'Vendor <billing@vendor.example>',
        subject: 'Invoice 1',
        text: 'Plain',
        html: '<p>Rich</p>',
        attachments: [
            ['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => 'first'],
            ['name' => 'b.png', 'mime' => 'image/png', 'bytes' => 'second!'],
        ],
    );
    $provider = $mail->provider();

    $content = $provider->content($mail->mailbox(), $id);

    expect($content->ref->providerMessageId)->toBe($id)
        ->and($content->from)->toBe('Vendor <billing@vendor.example>')
        ->and($content->to)->toBe('info@example.com')
        ->and($content->subject)->toBe('Invoice 1')
        ->and($content->headers)->toHaveKey('Subject', 'Invoice 1')
        ->and($content->text)->toBe('Plain')
        ->and($content->html)->toBe('<p>Rich</p>')
        ->and(array_map(fn (Attachment $a): array => [$a->filename, $a->mime, $a->size], $content->attachments))->toBe([
            ['a.pdf', 'application/pdf', 5],
            ['b.png', 'image/png', 7],
        ])
        ->and($provider->attachment($mail->mailbox(), $id, $content->attachments[1]->partId))->toBe('second!');
})->with('adapters');

it('refuses a part the message does not have', function (MailWorld $mail): void {
    $id = $mail->deliver(attachments: [['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => 'first']]);

    $mail->provider()->attachment($mail->mailbox(), $id, 'no-such-part');
})->with('adapters')->throws(MessageNotFound::class);

it('creates a missing label, and treats removing an unknown one as nothing', function (MailWorld $mail): void {
    $id = chatter($mail);

    $mail->provider()->changeLabels($mail->mailbox(), $id, ['Invoices/New'], ['Never/Existed']);

    expect($mail->labelsOf($id))->toEqualCanonicalizing(['INBOX', 'Invoices/New']);
})->with('adapters');

it('archives by leaving the inbox; the message stays readable', function (MailWorld $mail): void {
    $id = $mail->deliver(subject: 'Keep me');

    $mail->provider()->archive($mail->mailbox(), $id);

    expect($mail->labelsOf($id))->not->toContain('INBOX')
        ->and($mail->provider()->summary($mail->mailbox(), $id, 10)->subject)->toBe('Keep me');
})->with('adapters');

it('puts inserted mail in the mailbox, labelled, as an arrival', function (MailWorld $mail): void {
    $provider = $mail->provider();
    $cursor = $provider->currentCursor($mail->mailbox());

    $id = $provider->insert($mail->mailbox(), "From: a@vendor.example\r\nSubject: Copied\r\n\r\nBody\r\n", ['Invoices/Filed']);

    $summary = $provider->summary($mail->mailbox(), $id, 100);

    expect($summary->from)->toBe('a@vendor.example')
        ->and($summary->subject)->toBe('Copied')
        ->and($summary->text)->toContain('Body')
        ->and($summary->labels)->toBe(['Invoices/Filed'])
        ->and(changes($provider->history($mail->mailbox(), $cursor)))->toBe([[ChangeKind::MessageAdded, $id, []]]);
})->with('adapters');

it('gives a draft and a sent message their ids', function (MailWorld $mail): void {
    $message = new OutgoingMessage(['a@vendor.example'], 'Hello', 'Body');

    expect($mail->provider()->draft($mail->mailbox(), $message))->not->toBe('')
        ->and($mail->provider()->send($mail->mailbox(), $message))->not->toBe('');
})->with('adapters');

it('answers MessageNotFound for a message it does not hold', function (MailWorld $mail, string $call): void {
    call($mail, $call, 'gone');
})->with('adapters')->with([
    'summary', 'content', 'attachment', 'changeLabels', 'archive', 'draft a reply', 'send a reply',
])->throws(MessageNotFound::class);

it('answers MailboxDisconnected for every call once access is refused', function (MailWorld $mail, string $call): void {
    $id = chatter($mail);
    $mail->disconnect();

    call($mail, $call, $id);
})->with('adapters')->with([
    'currentCursor', 'history', 'search', 'summary', 'content', 'attachment', 'changeLabels', 'archive', 'insert', 'draft', 'send',
])->throws(MailboxDisconnected::class);
