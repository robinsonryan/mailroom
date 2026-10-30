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
        ['1', 'invoice-10442.pdf', 'application/pdf', 224408],
        ['2', 'lines.csv', 'text/csv', 512],
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
    GmailApi::fake([
        'messages/19a2f0c1d2e3f405/attachments/ANGjdJ_invoice_pdf' => Http::response(GmailApi::fixture('attachment')),
        'messages/19a2f0c1d2e3f405?*' => Http::response(GmailApi::fixture('message-multipart')),
    ]);

    expect($this->provider->attachment($this->mailbox, '19a2f0c1d2e3f405', '1'))
        ->toBe("%PDF-1.7\xff\xfe\x00 fixture bytes");
});

it('names an attachment by its part, which outlives the attachment id Gmail hands out per read', function (): void {
    $reread = GmailApi::fixture('message-multipart');
    $reread['payload']['parts'][1]['body']['attachmentId'] = 'ANGjdJ_reissued';
    GmailApi::fake([
        'messages/19a2f0c1d2e3f405/attachments/ANGjdJ_reissued' => Http::response(GmailApi::fixture('attachment')),
        'messages/19a2f0c1d2e3f405?*' => Http::sequence()
            ->push(GmailApi::fixture('message-multipart'))
            ->push($reread)
            ->push($reread),
    ]);

    $first = $this->provider->content($this->mailbox, '19a2f0c1d2e3f405')->attachments[0]->partId;
    $second = $this->provider->content($this->mailbox, '19a2f0c1d2e3f405')->attachments[0]->partId;

    expect($second)->toBe($first)
        ->and($this->provider->attachment($this->mailbox, '19a2f0c1d2e3f405', $first))->toBe("%PDF-1.7\xff\xfe\x00 fixture bytes");
});

it('reports a part the message does not hold as not found', function (): void {
    GmailApi::fake(['messages/19a2f0c1d2e3f405?*' => Http::response(GmailApi::fixture('message-multipart'))]);

    expect(fn () => $this->provider->attachment($this->mailbox, '19a2f0c1d2e3f405', '9'))
        ->toThrow(MessageNotFound::class, 'No part 9');
});

it('fetches a message that is nothing but its attachment, whose part id is empty', function (): void {
    $message = ['id' => 'm1', 'threadId' => 't1', 'payload' => [
        'partId' => '', 'mimeType' => 'application/pdf', 'filename' => 'scan.pdf',
        'body' => ['attachmentId' => 'a1', 'size' => 2048],
    ]];
    GmailApi::fake([
        'messages/m1/attachments/a1' => Http::response(GmailApi::fixture('attachment')),
        'messages/m1?*' => Http::response($message),
    ]);

    $partId = $this->provider->content($this->mailbox, 'm1')->attachments[0]->partId;

    expect($partId)->toBe('')
        ->and($this->provider->attachment($this->mailbox, 'm1', $partId))->toBe("%PDF-1.7\xff\xfe\x00 fixture bytes");
});

it('lists and fetches an attachment Gmail delivered inline, with no attachmentId', function (): void {
    $bytes = "%PDF-1.7\xff\xfe\x00 inline bytes";
    $message = ['id' => 'm1', 'threadId' => 't1', 'payload' => ['partId' => '', 'mimeType' => 'multipart/mixed', 'parts' => [
        ['partId' => '0', 'mimeType' => 'text/plain', 'body' => ['size' => 4, 'data' => GmailApi::encode('Body')]],
        ['partId' => '1', 'mimeType' => 'application/pdf', 'filename' => 'invoice.pdf', 'body' => ['size' => strlen($bytes), 'data' => GmailApi::encode($bytes)]],
    ]]];
    GmailApi::fake(['messages/m1?*' => Http::response($message)]);

    $attachments = $this->provider->content($this->mailbox, 'm1')->attachments;

    expect(array_map(fn (Attachment $a): array => [$a->partId, $a->filename, $a->mime, $a->size], $attachments))->toBe([['1', 'invoice.pdf', 'application/pdf', strlen($bytes)]])
        ->and($this->provider->attachment($this->mailbox, 'm1', '1'))->toBe($bytes)
        ->and(GmailApi::requests('*attachments*'))->toBeEmpty();
});

it('keeps a named text part with inline data readable as well as attached', function (): void {
    $message = ['id' => 'm1', 'threadId' => 't1', 'payload' => [
        'partId' => '', 'mimeType' => 'text/plain', 'filename' => 'note.txt',
        'body' => ['size' => 9, 'data' => GmailApi::encode('Pay this.')],
    ]];
    GmailApi::fake(['messages/m1?*' => Http::response($message)]);

    $content = $this->provider->content($this->mailbox, 'm1');

    expect($content->text)->toBe('Pay this.')
        ->and($content->attachments[0]->filename)->toBe('note.txt');
});

it('leaves out a named part with no bytes at all', function (): void {
    $message = ['id' => 'm1', 'threadId' => 't1', 'payload' => ['partId' => '', 'mimeType' => 'multipart/mixed', 'parts' => [
        ['partId' => '0', 'mimeType' => 'application/pdf', 'filename' => 'empty.pdf', 'body' => ['size' => 0]],
    ]]];
    GmailApi::fake(['messages/m1?*' => Http::response($message)]);

    expect($this->provider->content($this->mailbox, 'm1')->attachments)->toBe([])
        ->and(fn () => $this->provider->attachment($this->mailbox, 'm1', '0'))->toThrow(MessageNotFound::class);
});

/**
 * A message whose text parts Gmail stored apart from it.
 *
 * @return array<string, mixed>
 */
function messageWithStoredBodies(): array
{
    return ['id' => 'm1', 'threadId' => 't1', 'payload' => ['partId' => '', 'mimeType' => 'multipart/related', 'parts' => [
        ['partId' => '0', 'mimeType' => 'multipart/alternative', 'parts' => [
            ['partId' => '0.0', 'mimeType' => 'text/plain', 'body' => ['size' => 11, 'attachmentId' => 'text-token']],
            ['partId' => '0.1', 'mimeType' => 'text/html', 'body' => ['size' => 26, 'attachmentId' => 'html-token']],
        ]],
        ['partId' => '1', 'mimeType' => 'image/png', 'filename' => 'logo.png', 'body' => ['size' => 900, 'attachmentId' => 'logo-token'],
            'headers' => [['name' => 'Content-ID', 'value' => '<logo>']]],
    ]]];
}

function fakeStoredBodies(array $message): void
{
    GmailApi::fake([
        'messages/m1/attachments/text-token' => Http::response(['size' => 11, 'data' => GmailApi::encode('Stored text')]),
        'messages/m1/attachments/html-token' => Http::response(['size' => 26, 'data' => GmailApi::encode('<img src="cid:logo">Stored')]),
        'messages/m1?*' => Http::response($message),
    ]);
}

it('reads text parts Gmail stored apart from the message', function (): void {
    fakeStoredBodies(messageWithStoredBodies());

    $content = $this->provider->content($this->mailbox, 'm1');

    expect($content->text)->toBe('Stored text')
        ->and($content->html)->toBe('<img src="cid:logo">Stored');
});

it('summarises from a stored text part', function (): void {
    fakeStoredBodies(messageWithStoredBodies());

    expect($this->provider->summary($this->mailbox, 'm1', 100)->text)->toBe('Stored text');
});

it('judges embedded images against a stored html part', function (): void {
    fakeStoredBodies(messageWithStoredBodies());

    expect($this->provider->content($this->mailbox, 'm1')->attachments)->toBe([]);
});

it('fetches a stored part only when it is the body that will be read', function (): void {
    $message = messageWithStoredBodies();
    $message['payload']['parts'][0]['parts'][] = ['partId' => '0.2', 'mimeType' => 'text/plain', 'body' => ['size' => 5, 'attachmentId' => 'second-text-token']];
    fakeStoredBodies($message);

    $this->provider->content($this->mailbox, 'm1');

    expect(GmailApi::requests('*attachments*'))->toHaveCount(2);
});

it('does not fetch a stored part when the same kind of body is inline', function (): void {
    $message = messageWithStoredBodies();
    array_unshift($message['payload']['parts'][0]['parts'], ['partId' => '0.3', 'mimeType' => 'text/plain', 'body' => ['size' => 6, 'data' => GmailApi::encode('Inline')]]);
    fakeStoredBodies($message);

    expect($this->provider->content($this->mailbox, 'm1')->text)->toBe('Inline')
        ->and(GmailApi::requests('*attachments/text-token'))->toBeEmpty();
});

it('reports a message that is gone', function (Closure $call): void {
    GmailApi::fake(['messages/*' => Http::response(GmailApi::fixture('error-not-found'), 404)]);

    $call($this->provider, $this->mailbox);
})->with([
    'summary' => [fn ($provider, $mailbox) => $provider->summary($mailbox, 'gone', 100)],
    'content' => [fn ($provider, $mailbox) => $provider->content($mailbox, 'gone')],
    'attachment' => [fn ($provider, $mailbox) => $provider->attachment($mailbox, 'gone', 'part')],
])->throws(MessageNotFound::class);

/**
 * A message with this html (or plain text, when $html is null) and this image.
 *
 * @param  array<int, array{name: string, value: string}>  $headers
 */
function messageWithImage(?string $html, array $headers, int $size = 900, string $mime = 'image/png'): GmailMessage
{
    return new GmailMessage(['id' => 'm1', 'payload' => ['mimeType' => 'multipart/related', 'parts' => [
        ['mimeType' => $html === null ? 'text/plain' : 'text/html', 'body' => ['data' => GmailApi::encode($html ?? 'See attached')]],
        ['mimeType' => $mime, 'filename' => 'image.png', 'body' => ['attachmentId' => 'a1', 'size' => $size], 'headers' => $headers],
    ]]]);
}

function contentId(string $id = '<logo>'): array
{
    return ['name' => 'Content-ID', 'value' => $id];
}

function disposition(string $value): array
{
    return ['name' => 'Content-Disposition', 'value' => $value];
}

it('embeds an image only when the HTML references it, it is not marked attachment, and it is small', function (GmailMessage $message): void {
    expect($message->attachments())->toBe([]);
})->with([
    'no disposition' => fn (): GmailMessage => messageWithImage('<img src="cid:logo">', [contentId()]),
    'inline' => fn (): GmailMessage => messageWithImage('<img src="cid:logo">', [contentId(), disposition('inline; filename=image.png')]),
    'header name in any case' => fn (): GmailMessage => messageWithImage('<img src="cid:logo">', [contentId() + ['name' => 'content-id']]),
    'just under the limit' => fn (): GmailMessage => messageWithImage('<img src="cid:logo">', [contentId()], 99_999),
    'single quotes, upper case scheme' => fn (): GmailMessage => messageWithImage("<img src='CID:logo'>", [contentId()]),
    'a CSS url' => fn (): GmailMessage => messageWithImage('<td style="background:url(&quot;cid:logo&quot;)">', [contentId()]),
    'percent-encoded, as RFC 2392 writes it' => fn (): GmailMessage => messageWithImage('<img src="cid:image001.png%4001DA.ABCD">', [contentId('<image001.png@01DA.ABCD>')]),
    'an entity in the reference' => fn (): GmailMessage => messageWithImage('<img src="cid:image001.png&#64;01DA.ABCD">', [contentId('<image001.png@01DA.ABCD>')]),
]);

it('keeps every other named image as an attachment', function (GmailMessage $message): void {
    expect(array_map(fn (Attachment $a): string => $a->filename, $message->attachments()))->toBe(['image.png']);
})->with([
    'referenced but marked attachment (Outlook)' => fn (): GmailMessage => messageWithImage('<img src="cid:logo">', [contentId(), disposition('attachment; filename=image.png')]),
    'marked Attachment in another case' => fn (): GmailMessage => messageWithImage('<img src="cid:logo">', [contentId(), disposition('Attachment; filename=image.png')]),
    'inline with a plain body (Apple Mail photo)' => fn (): GmailMessage => messageWithImage(null, [contentId(), disposition('inline; filename=image.png')]),
    'inline, html does not reference it' => fn (): GmailMessage => messageWithImage('<p>Photo attached</p>', [contentId(), disposition('inline; filename=image.png')]),
    'another id starting the same' => fn (): GmailMessage => messageWithImage('<img src="cid:logo2">', [contentId()]),
    'referenced but at the limit' => fn (): GmailMessage => messageWithImage('<img src="cid:logo">', [contentId()], 100_000),
    'no Content-ID' => fn (): GmailMessage => messageWithImage('<img src="cid:logo">', []),
]);

it('never reads a non-image with a Content-ID as embedded', function (): void {
    $message = messageWithImage('<a href="cid:logo">Invoice</a>', [contentId()], mime: 'application/pdf');

    expect($message->attachments())->toHaveCount(1);
});

it('does not read a nameless inline image as an attachment', function (): void {
    GmailApi::fake(['messages/*' => Http::response(GmailApi::fixture('message-multipart'))]);

    expect(array_map(fn (Attachment $a): string => $a->filename, $this->provider->content($this->mailbox, 'm')->attachments))
        ->toBe(['invoice-10442.pdf', 'lines.csv']);
});
