<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RobinsonRyan\Mailroom\Data\HistoryChange;
use RobinsonRyan\Mailroom\Enums\ChangeKind;
use RobinsonRyan\Mailroom\Exceptions\CursorExpired;
use RobinsonRyan\Mailroom\Tests\Feature\Gmail\GmailApi;

beforeEach(function (): void {
    $this->provider = GmailApi::provider();
    $this->mailbox = GmailApi::mailbox();
});

it('asks for the three history types as repeated parameters, from the cursor', function (): void {
    GmailApi::fake(['history*' => Http::response(GmailApi::fixture('history-empty'))]);

    $this->provider->history($this->mailbox, '8842199');

    // PHP's historyTypes[0]=… bracket form is not what the Gmail API reads.
    $url = GmailApi::requests(GmailApi::BASE.'history*')[0]->url();
    expect($url)->toContain('startHistoryId=8842199')
        ->toContain('maxResults=500')
        ->toContain('historyTypes=messageAdded&historyTypes=labelAdded&historyTypes=labelRemoved')
        ->not->toContain('%5B');
});

it('walks every page and reports changes oldest first with label names', function (): void {
    GmailApi::fake([
        'history*pageToken=09483726150*' => Http::response(GmailApi::fixture('history-page-2')),
        'history*' => Http::response(GmailApi::fixture('history-page-1')),
    ]);

    $page = $this->provider->history($this->mailbox, '8842199');

    expect(array_map(fn (HistoryChange $c): array => [$c->kind, $c->providerMessageId, $c->threadId, $c->labels], $page->changes))->toBe([
        [ChangeKind::MessageAdded, 'aaa1', 'taaa', []],
        [ChangeKind::MessageAdded, 'bbb2', 'tbbb', []],
        [ChangeKind::LabelAdded, 'bbb2', 'tbbb', ['Invoices/Invoice']],
        [ChangeKind::LabelAdded, 'aaa1', 'taaa', ['Invoices/Needs you', 'Invoices/Filed']],
        [ChangeKind::LabelRemoved, 'aaa1', 'taaa', ['UNREAD']],
    ])->and($page->cursor)->toBe('8842220');

    expect(GmailApi::requests(GmailApi::BASE.'history*'))->toHaveCount(2);
});

it('skips messages carrying DRAFT, SPAM or TRASH', function (): void {
    GmailApi::fake([
        'history*pageToken=*' => Http::response(GmailApi::fixture('history-page-2')),
        'history*' => Http::response(GmailApi::fixture('history-page-1')),
    ]);

    $ids = array_map(fn (HistoryChange $c): string => $c->providerMessageId, $this->provider->history($this->mailbox, '1')->changes);

    expect($ids)->not->toContain('ccc3')->not->toContain('ddd4')->not->toContain('eee5');
});

it('returns the new cursor when nothing changed', function (): void {
    GmailApi::fake(['history*' => Http::response(GmailApi::fixture('history-empty'))]);

    $page = $this->provider->history($this->mailbox, '8842199');

    expect($page->changes)->toBe([])
        ->and($page->cursor)->toBe('8842230')
        ->and(GmailApi::requests(GmailApi::BASE.'labels'))->toBeEmpty();
});

it('reads an out-of-date cursor as expired', function (): void {
    GmailApi::fake(['history*' => Http::response(GmailApi::fixture('error-not-found'), 404)]);

    $this->provider->history($this->mailbox, '1');
})->throws(CursorExpired::class);

it('keeps the id to name map between polls', function (): void {
    GmailApi::fake(['history*' => Http::response(GmailApi::fixture('history-page-2'))]);

    $this->provider->history($this->mailbox, '1');
    $this->provider->history($this->mailbox, '2');

    expect(GmailApi::requests(GmailApi::BASE.'labels'))->toHaveCount(1);
});

it('refreshes the map when history names a label it has not seen', function (): void {
    GmailApi::fake([
        'history*' => Http::sequence()
            ->push(GmailApi::fixture('history-page-2'))
            ->push(GmailApi::fixture('history-unknown-label')),
        'labels' => Http::sequence()
            ->push(GmailApi::fixture('labels'))
            ->push(['labels' => [['id' => 'Label_99', 'name' => 'Invoices/Payment problem']]]),
    ]);

    $this->provider->history($this->mailbox, '1');
    $page = $this->provider->history($this->mailbox, '2');

    expect($page->changes[0]->labels)->toBe(['Invoices/Payment problem']);
});

it('leaves out labels deleted since, refreshing once per poll', function (): void {
    GmailApi::fake(['history*' => Http::response(GmailApi::fixture('history-deleted-label'))]);

    $page = $this->provider->history($this->mailbox, '1');

    expect($page->changes)->toHaveCount(1)
        ->and($page->changes[0]->providerMessageId)->toBe('hhh8')
        ->and($page->changes[0]->labels)->toBe(['Invoices/Invoice'])
        ->and(GmailApi::requests(GmailApi::BASE.'labels'))->toHaveCount(1);
});

it('keeps each mailbox\'s label map apart', function (): void {
    GmailApi::fake(['history*' => Http::response(GmailApi::fixture('history-page-2'))]);

    $this->provider->history($this->mailbox, '1');
    $this->provider->history(GmailApi::mailbox('other@example.com', 'other-box'), '1');

    expect(GmailApi::requests(GmailApi::BASE.'labels'))->toHaveCount(2);
});
