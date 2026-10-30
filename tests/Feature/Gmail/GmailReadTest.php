<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RobinsonRyan\Mailroom\Data\Attachment;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Gmail\GmailMessage;
use RobinsonRyan\Mailroom\Tests\Feature\Gmail\GmailApi;

beforeEach(function (): void {
    $this->provider = GmailApi::provider();
    $this->mailbox = GmailApi::mailbox();
});

it('reads the current cursor from the profile', function (): void {
    GmailApi::fake(['profile' => Http::response(GmailApi::fixture('profile'))]);

    expect($this->provider->currentCursor($this->mailbox))->toBe('8842199');
});

it('searches with the query, following pages up to the limit', function (): void {
    GmailApi::fake([
        'messages?*pageToken=11223344*' => Http::response(GmailApi::fixture('messages-list-page-2')),
        'messages?*' => Http::response(GmailApi::fixture('messages-list-page-1')),
    ]);

    $ids = $this->provider->search($this->mailbox, 'newer_than:7d -in:chats', 3);

    expect($ids)->toBe(['m3', 'm2', 'm1']);

    $calls = GmailApi::requests(GmailApi::BASE.'messages?*');
    expect($calls)->toHaveCount(2)
        ->and($calls[0]['q'])->toBe('newer_than:7d -in:chats')
        ->and($calls[0]['maxResults'])->toBe('3')
        ->and($calls[1]['maxResults'])->toBe('1');
});

it('stops searching when the pages run out', function (): void {
    GmailApi::fake(['messages?*' => Http::response(GmailApi::fixture('messages-list-empty'))]);

    expect($this->provider->search($this->mailbox, 'nothing'))->toBe([])
        ->and(GmailApi::requests(GmailApi::BASE.'messages?*')[0]['maxResults'])->toBe('500');
});

it('summarises a message from its text part, without touching attachments', function (): void {
    GmailApi::fake(['messages/19a2f0c1d2e3f405?*' => Http::response(GmailApi::fixture('message-multipart'))]);

    $summary = $this->provider->summary($this->mailbox, '19a2f0c1d2e3f405', 2500);

    expect($summary->providerMessageId)->toBe('19a2f0c1d2e3f405')
        ->and($summary->threadId)->toBe('19a2f0c1d2e3f400')
        ->and($summary->receivedAt->toIso8601String())->toBe('2026-10-26T16:14:02+00:00')
        ->and($summary->from)->toBe('Acme Parts Billing <billing@acme-parts.example>')
        ->and($summary->subject)->toBe('Invoice 10442 — due 2026-11-25')
        ->and($summary->attachmentNames)->toBe(['invoice-10442.pdf', 'lines.csv'])
        ->and($summary->text)->toContain('Invoice 10442 for $1,284.50 is attached.')
        ->and($summary->labels)->toEqualCanonicalizing(['INBOX', 'UNREAD', 'Invoices/Invoice', 'CATEGORY_UPDATES']);

    expect(GmailApi::requests(GmailApi::BASE.'messages/19a2f0c1d2e3f405?*')[0]['format'])->toBe('full')
        ->and(GmailApi::requests('*attachments*'))->toBeEmpty();
});

it('cuts the summary text to the limit by characters', function (): void {
    GmailApi::fake(['messages/*' => Http::response(GmailApi::fixture('message-plain'))]);

    expect($this->provider->summary($this->mailbox, '19a2f0c1d2e3f407', 25)->text)->toBe('Please see the note — Zoë')
        ->and($this->provider->summary($this->mailbox, '19a2f0c1d2e3f407', 24)->text)->toBe('Please see the note — Zo');
});

it('strips tags from html when there is no plain part, dropping styles and scripts', function (): void {
    GmailApi::fake(['messages/*' => Http::response(GmailApi::fixture('message-html-only'))]);

    $summary = $this->provider->summary($this->mailbox, '19a2f0c1d2e3f406', 2500);

    expect($summary->text)->toBe('Statement ready Balance: $0.00 Fish & Chips')
        ->and($summary->from)->toBe('Statements <noreply@bank.example>')
        ->and($summary->subject)->toBe('Your statement is ready');
});

it('reads the full message: headers, both bodies and its attachments', function (): void {
    GmailApi::fake(['messages/19a2f0c1d2e3f405?*' => Http::response(GmailApi::fixture('message-multipart'))]);

    $content = $this->provider->content($this->mailbox, '19a2f0c1d2e3f405');

    expect($content->ref->mailboxId)->toBe($this->mailbox->id)
        ->and($content->ref->providerMessageId)->toBe('19a2f0c1d2e3f405')
        ->and($content->ref->threadId)->toBe('19a2f0c1d2e3f400')
        ->and($content->ref->receivedAt->toIso8601String())->toBe('2026-10-26T16:14:02+00:00')
        ->and($content->from)->toBe('Acme Parts Billing <billing@acme-parts.example>')
        ->and($content->to)->toBe('Info <info@example.com>')
        ->and($content->subject)->toBe('Invoice 10442 — due 2026-11-25')
        ->and($content->headers['Message-ID'])->toBe('<inv-10442@acme-parts.example>')
        ->and($content->headers['Received'])->toStartWith('from mail.acme-parts.example')
        ->and($content->text)->toContain("Invoice 10442 for $1,284.50 is attached.\r\nPayment is due")
        ->and($content->html)->toContain('<b>$1,284.50</b>');

    expect(array_map(fn (Attachment $a): array => [$a->partId, $a->filename, $a->mime, $a->size], $content->attachments))->toBe([
        ['ANGjdJ_invoice_pdf', 'invoice-10442.pdf', 'application/pdf', 224408],
        ['ANGjdJ_lines_csv', 'lines.csv', 'text/csv', 512],
    ]);
});

it('reads a single-part message', function (): void {
    GmailApi::fake(['messages/*' => Http::response(GmailApi::fixture('message-plain'))]);

    $content = $this->provider->content($this->mailbox, '19a2f0c1d2e3f407');

    expect($content->text)->toBe('Please see the note — Zoë')
        ->and($content->html)->toBeNull()
        ->and($content->attachments)->toBe([]);
});

it('finds headers whatever their case, keeping the name as sent', function (): void {
    GmailApi::fake(['messages/*' => Http::response(GmailApi::fixture('message-html-only'))]);

    $content = $this->provider->content($this->mailbox, '19a2f0c1d2e3f406');

    expect($content->from)->toBe('Statements <noreply@bank.example>')
        ->and($content->to)->toBe('info@example.com')
        ->and($content->headers)->toHaveKey('subject')
        ->and($content->text)->toBeNull();
});

it('treats an attached message as one attachment and prefers the inline text', function (): void {
    GmailApi::fake(['messages/*' => Http::response(GmailApi::fixture('message-forwarded'))]);

    $content = $this->provider->content($this->mailbox, '19a2f0c1d2e3f408');

    expect($content->text)->toBe('Forwarding this.')
        ->and(array_map(fn (Attachment $a): string => $a->filename, $content->attachments))->toBe(['original.eml']);
});

it('returns an attachment\'s bytes', function (): void {
    GmailApi::fake(['messages/19a2f0c1d2e3f405/attachments/ANGjdJ_invoice_pdf' => Http::response(GmailApi::fixture('attachment'))]);

    expect($this->provider->attachment($this->mailbox, '19a2f0c1d2e3f405', 'ANGjdJ_invoice_pdf'))
        ->toBe("%PDF-1.7\xff\xfe\x00 fixture bytes");
});

it('reports a message that is gone', function (Closure $call): void {
    GmailApi::fake(['messages/*' => Http::response(GmailApi::fixture('error-not-found'), 404)]);

    $call($this->provider, $this->mailbox);
})->with([
    'summary' => [fn ($provider, $mailbox) => $provider->summary($mailbox, 'gone', 100)],
    'content' => [fn ($provider, $mailbox) => $provider->content($mailbox, 'gone')],
    'attachment' => [fn ($provider, $mailbox) => $provider->attachment($mailbox, 'gone', 'part')],
])->throws(MessageNotFound::class);

it('does not count an image the HTML embeds by Content-ID as an attachment', function (): void {
    $message = new GmailMessage([
        'id' => 'm1',
        'payload' => [
            'mimeType' => 'multipart/related',
            'parts' => [
                ['mimeType' => 'text/html', 'body' => ['data' => rtrim(strtr(base64_encode('<p>Paid</p><img src="cid:logo">'), '+/', '-_'), '=')]],
                ['mimeType' => 'image/png', 'filename' => 'logo.png', 'body' => ['attachmentId' => 'a1', 'size' => 900], 'headers' => [['name' => 'Content-ID', 'value' => '<logo>']]],
                ['mimeType' => 'image/jpeg', 'filename' => 'invoice-photo.jpg', 'body' => ['attachmentId' => 'a2', 'size' => 90000], 'headers' => [['name' => 'Content-Disposition', 'value' => 'attachment']]],
            ],
        ],
    ]);

    expect(array_map(fn (Attachment $a): string => $a->filename, $message->attachments()))->toBe(['invoice-photo.jpg']);
});
