<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RobinsonRyan\Mailroom\Data\OutgoingMessage;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Tests\Feature\Gmail\GmailApi;

beforeEach(function (): void {
    $this->provider = GmailApi::provider();
    $this->mailbox = GmailApi::mailbox();
    $this->sent = ['id' => 'sent1', 'threadId' => 't1', 'labelIds' => ['SENT']];
    $this->drafted = ['id' => 'r-1234', 'message' => ['id' => 'draftmsg', 'threadId' => 't1', 'labelIds' => ['DRAFT']]];
});

it('inserts raw mail with its date header and labels', function (): void {
    $raw = "From: a@vendor.example\r\nSubject: =?UTF-8?B?w6k=?=\r\n\r\n?>?>?> body\r\n";
    GmailApi::fake(['messages?*' => Http::response(['id' => 'ins1', 'threadId' => 't9', 'labelIds' => ['INBOX', 'Label_14']])]);

    $id = $this->provider->insert($this->mailbox, $raw, ['INBOX', 'Invoices/Filed']);

    $call = GmailApi::requests(GmailApi::BASE.'messages?*')[0];
    expect($id)->toBe('ins1')
        ->and($call->method())->toBe('POST')
        ->and($call->url())->toContain('internalDateSource=dateHeader')
        ->and(GmailApi::decodeRaw($call['raw']))->toBe($raw)
        ->and($call['labelIds'])->toBe(['INBOX', 'Label_14']);
});

it('sends a plain UTF-8 message', function (): void {
    GmailApi::fake(['messages/send' => Http::response($this->sent)]);

    $id = $this->provider->send($this->mailbox, new OutgoingMessage(
        to: ['a@vendor.example', 'Bee <b@vendor.example>'],
        subject: 'Receipt',
        text: "Thanks — Zoë\nLine two",
        cc: ['c@vendor.example'],
    ));

    [$headers, $body] = GmailApi::split(GmailApi::decodeRaw(GmailApi::requests(GmailApi::BASE.'messages/send')[0]['raw']));

    expect($id)->toBe('sent1')
        ->and($headers['from'])->toBe('info@example.com')
        ->and($headers['to'])->toBe('a@vendor.example, Bee <b@vendor.example>')
        ->and($headers['cc'])->toBe('c@vendor.example')
        ->and($headers['subject'])->toBe('Receipt')
        ->and($headers['mime-version'])->toBe('1.0')
        ->and(strtolower($headers['content-type']))->toBe('text/plain; charset=utf-8')
        ->and($headers['content-transfer-encoding'])->toBe('quoted-printable')
        ->and(quoted_printable_decode($body))->toBe("Thanks — Zoë\r\nLine two");
});

it('wraps long lines so no line passes 76 characters', function (): void {
    GmailApi::fake(['messages/send' => Http::response($this->sent)]);
    $text = str_repeat('é long line ', 40);

    $this->provider->send($this->mailbox, new OutgoingMessage(['a@vendor.example'], 'Long', $text));

    [, $body] = GmailApi::split(GmailApi::decodeRaw(GmailApi::requests(GmailApi::BASE.'messages/send')[0]['raw']));

    expect(max(array_map(strlen(...), explode("\r\n", $body))))->toBeLessThanOrEqual(76)
        ->and(quoted_printable_decode($body))->toBe($text);
});

it('encodes a non-ASCII subject as encoded words', function (): void {
    GmailApi::fake(['messages/send' => Http::response($this->sent)]);
    $subject = 'Rechnung für Zoë —'.str_repeat(' Überweisung', 12);

    $this->provider->send($this->mailbox, new OutgoingMessage(['a@vendor.example'], $subject, 'Body'));

    $raw = GmailApi::decodeRaw(GmailApi::requests(GmailApi::BASE.'messages/send')[0]['raw']);
    [$headers] = GmailApi::split($raw);

    expect(strtolower($raw))->toContain('=?utf-8?')
        ->and(mb_check_encoding($headers['subject'], 'ASCII'))->toBeTrue()
        ->and(iconv_mime_decode($headers['subject'], 0, 'UTF-8'))->toBe($subject);
});

it('encodes a non-ASCII display name in an address', function (): void {
    GmailApi::fake(['messages/send' => Http::response($this->sent)]);

    $this->provider->send($this->mailbox, new OutgoingMessage(['Zoë Bee <z@vendor.example>'], 'Hi', 'Body', cc: ['Jürgen <j@vendor.example>']));

    [$headers] = GmailApi::split(GmailApi::decodeRaw(GmailApi::requests(GmailApi::BASE.'messages/send')[0]['raw']));

    expect(mb_check_encoding($headers['to'], 'ASCII'))->toBeTrue()
        ->and(mb_check_encoding($headers['cc'], 'ASCII'))->toBeTrue()
        ->and(iconv_mime_decode($headers['to'], 0, 'UTF-8'))->toBe('Zoë Bee <z@vendor.example>')
        ->and(iconv_mime_decode($headers['cc'], 0, 'UTF-8'))->toBe('Jürgen <j@vendor.example>');
});

it('threads a reply onto a Message-ID that is not strictly RFC-shaped, on one line', function (): void {
    $original = ['id' => 'o1', 'threadId' => 't1', 'payload' => ['headers' => [
        ['name' => 'Message-ID', 'value' => '<not an addr-spec>'],
        ['name' => 'References', 'value' => "<a@vendor.example>\r\n <b@vendor.example>"],
    ]]];
    GmailApi::fake(['messages/o1?*' => Http::response($original), 'messages/send' => Http::response($this->sent)]);

    $this->provider->send($this->mailbox, new OutgoingMessage(['a@vendor.example'], 'Re', 'Body', inReplyTo: 'o1'));

    [$headers] = GmailApi::split(GmailApi::decodeRaw(GmailApi::requests(GmailApi::BASE.'messages/send')[0]['raw']));

    expect($headers['in-reply-to'])->toBe('<not an addr-spec>')
        ->and($headers['references'])->toBe('<a@vendor.example> <b@vendor.example> <not an addr-spec>');
});

it('leaves out threading headers the original gives nothing for', function (): void {
    GmailApi::fake([
        'messages/o1?*' => Http::response(['id' => 'o1', 'threadId' => 't1', 'payload' => ['headers' => []]]),
        'messages/send' => Http::response($this->sent),
    ]);

    $this->provider->send($this->mailbox, new OutgoingMessage(['a@vendor.example'], 'Re', 'Body', inReplyTo: 'o1'));

    [$headers] = GmailApi::split(GmailApi::decodeRaw(GmailApi::requests(GmailApi::BASE.'messages/send')[0]['raw']));

    expect($headers)->not->toHaveKey('in-reply-to')->not->toHaveKey('references');
});

it('cannot have a header injected through a subject', function (): void {
    GmailApi::fake(['messages/send' => Http::response($this->sent)]);

    $this->provider->send($this->mailbox, new OutgoingMessage(['a@vendor.example'], "Hello\r\nBcc: evil@attacker.example", 'Body'));

    [$headers] = GmailApi::split(GmailApi::decodeRaw(GmailApi::requests(GmailApi::BASE.'messages/send')[0]['raw']));

    expect($headers)->not->toHaveKey('bcc');
});

it('refuses an address carrying a header, and sends nothing', function (): void {
    GmailApi::fake(['messages/send' => Http::response($this->sent)]);

    expect(fn () => $this->provider->send($this->mailbox, new OutgoingMessage(["a@vendor.example\r\nBcc: evil@attacker.example"], 'Hi', 'Body')))
        ->toThrow(InvalidArgumentException::class);

    expect(GmailApi::requests(GmailApi::BASE.'messages/send'))->toBeEmpty();
});

it('sends multipart/alternative when html is present', function (): void {
    GmailApi::fake(['messages/send' => Http::response($this->sent)]);

    $this->provider->send($this->mailbox, new OutgoingMessage(['a@vendor.example'], 'Both', 'Plain — text', '<p>Html — body</p>'));

    [$headers, $body] = GmailApi::split(GmailApi::decodeRaw(GmailApi::requests(GmailApi::BASE.'messages/send')[0]['raw']));
    preg_match('/^multipart\/alternative; boundary="?([^";]+)"?$/', $headers['content-type'], $match);
    $boundary = $match[1];
    $parts = explode("--{$boundary}", $body);

    expect($parts)->toHaveCount(4)
        ->and(trim($parts[3]))->toBe('--');

    [$plainHeaders, $plain] = GmailApi::split(ltrim($parts[1]));
    [$htmlHeaders, $html] = GmailApi::split(ltrim($parts[2]));

    expect(strtolower($plainHeaders['content-type']))->toBe('text/plain; charset=utf-8')
        ->and(quoted_printable_decode(rtrim($plain)))->toBe('Plain — text')
        ->and(strtolower($htmlHeaders['content-type']))->toBe('text/html; charset=utf-8')
        ->and($htmlHeaders['content-transfer-encoding'])->toBe('quoted-printable')
        ->and(quoted_printable_decode(rtrim($html)))->toBe('<p>Html — body</p>');
});

it('drafts through the drafts endpoint and returns the draft id', function (): void {
    GmailApi::fake(['drafts' => Http::response($this->drafted)]);

    $id = $this->provider->draft($this->mailbox, new OutgoingMessage(['a@vendor.example'], 'Draft', 'Body'));

    $call = GmailApi::requests(GmailApi::BASE.'drafts')[0];
    [$headers] = GmailApi::split(GmailApi::decodeRaw($call['message']['raw']));

    expect($id)->toBe('r-1234')
        ->and($headers['subject'])->toBe('Draft')
        ->and($call['message'])->not->toHaveKey('threadId');
});

it('threads a reply onto the original', function (Closure $write, string $url, Closure $body): void {
    GmailApi::fake([
        'messages/19a2f0c1d2e3f407?*' => Http::response(GmailApi::fixture('message-plain')),
        'messages/send' => Http::response($this->sent),
        'drafts' => Http::response($this->drafted),
    ]);

    $write($this->provider, $this->mailbox, new OutgoingMessage(['zoe@vendor.example'], 'Re: Quote', 'Thanks', inReplyTo: '19a2f0c1d2e3f407'));

    $call = GmailApi::requests(GmailApi::BASE.$url)[0];
    $message = $body($call);
    [$headers] = GmailApi::split(GmailApi::decodeRaw($message['raw']));

    expect($message['threadId'])->toBe('19a2f0c1d2e3f300')
        ->and($headers['in-reply-to'])->toBe('<quote-77@vendor.example>')
        ->and($headers['references'])->toBe('<quote-75@vendor.example> <quote-76@vendor.example> <quote-77@vendor.example>');

    expect(GmailApi::requests(GmailApi::BASE.'messages/19a2f0c1d2e3f407?*')[0]['format'])->toBe('metadata');
})->with([
    'send' => [fn ($provider, $mailbox, $message) => $provider->send($mailbox, $message), 'messages/send', fn ($call) => $call->data()],
    'draft' => [fn ($provider, $mailbox, $message) => $provider->draft($mailbox, $message), 'drafts', fn ($call) => $call['message']],
]);

it('refuses to reply to a message that is gone', function (): void {
    GmailApi::fake(['messages/gone?*' => Http::response(GmailApi::fixture('error-not-found'), 404)]);

    $this->provider->send($this->mailbox, new OutgoingMessage(['a@vendor.example'], 'Re', 'Body', inReplyTo: 'gone'));
})->throws(MessageNotFound::class);
