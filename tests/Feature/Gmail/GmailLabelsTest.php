<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Exceptions\ProviderUnavailable;
use RobinsonRyan\Mailroom\Tests\Feature\Gmail\GmailApi;

beforeEach(function (): void {
    $this->provider = GmailApi::provider();
    $this->mailbox = GmailApi::mailbox();
    $this->modified = ['id' => 'm1', 'threadId' => 't1', 'labelIds' => ['INBOX']];
});

it('adds and removes existing labels by name in one call', function (): void {
    GmailApi::fake(['messages/m1/modify' => Http::response($this->modified)]);

    $this->provider->changeLabels($this->mailbox, 'm1', ['Invoices/Filed'], ['Invoices/Needs you', 'UNREAD']);

    $call = GmailApi::requests(GmailApi::BASE.'messages/m1/modify')[0];
    expect($call->method())->toBe('POST')
        ->and($call['addLabelIds'])->toBe(['Label_14'])
        ->and($call['removeLabelIds'])->toBe(['Label_13', 'UNREAD']);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === GmailApi::BASE.'labels');
});

it('creates a missing label, literally named and visible, then applies it', function (): void {
    GmailApi::fake([
        'labels' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['id' => 'Label_50', 'name' => $request['name'], 'type' => 'user'])
            : Http::response(GmailApi::fixture('labels')),
        'messages/m1/modify' => Http::response($this->modified),
    ]);

    $this->provider->changeLabels($this->mailbox, 'm1', ['Invoices/Statement'], []);

    $created = array_values(array_filter(GmailApi::requests(GmailApi::BASE.'labels'), fn (Request $r): bool => $r->method() === 'POST'));
    expect($created)->toHaveCount(1)
        ->and($created[0]->data())->toBe(['name' => 'Invoices/Statement', 'labelListVisibility' => 'labelShow', 'messageListVisibility' => 'show'])
        ->and(GmailApi::requests(GmailApi::BASE.'messages/m1/modify')[0]['addLabelIds'])->toBe(['Label_50']);
});

it('remembers a label it created', function (): void {
    GmailApi::fake([
        'labels' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['id' => 'Label_50', 'name' => $request['name'], 'type' => 'user'])
            : Http::response(GmailApi::fixture('labels')),
        'messages/*/modify' => Http::response($this->modified),
    ]);

    $this->provider->changeLabels($this->mailbox, 'm1', ['Invoices/Statement'], []);
    $this->provider->changeLabels($this->mailbox, 'm2', ['Invoices/Statement'], []);

    expect(GmailApi::requests(GmailApi::BASE.'messages/m2/modify')[0]['addLabelIds'])->toBe(['Label_50'])
        ->and(GmailApi::requests(GmailApi::BASE.'labels'))->toHaveCount(2);
});

it('sees a label made elsewhere rather than creating it again', function (): void {
    GmailApi::fake([
        'labels' => Http::sequence()
            ->push(GmailApi::fixture('labels'))
            ->push(['labels' => [['id' => 'Label_77', 'name' => 'Invoices/Other', 'type' => 'user']]]),
        'messages/*/modify' => Http::response($this->modified),
    ]);

    $this->provider->changeLabels($this->mailbox, 'm1', ['Invoices/Filed'], []);
    $this->provider->changeLabels($this->mailbox, 'm2', ['Invoices/Other'], []);

    expect(GmailApi::requests(GmailApi::BASE.'messages/m2/modify')[0]['addLabelIds'])->toBe(['Label_77']);
});

it('uses the winner when another worker creates the label first', function (): void {
    $lists = 0;
    GmailApi::fake([
        'labels' => function (Request $request) use (&$lists) {
            if ($request->method() === 'POST') {
                return Http::response(GmailApi::fixture('error-conflict'), 409);
            }

            return ++$lists === 1
                ? Http::response(['labels' => []])
                : Http::response(['labels' => [['id' => 'Label_88', 'name' => 'Invoices/Filed', 'type' => 'user']]]);
        },
        'messages/m1/modify' => Http::response($this->modified),
    ]);

    $this->provider->changeLabels($this->mailbox, 'm1', ['Invoices/Filed'], []);

    expect(GmailApi::requests(GmailApi::BASE.'messages/m1/modify')[0]['addLabelIds'])->toBe(['Label_88']);
});

it('ignores removing a label that does not exist', function (): void {
    GmailApi::fake(['messages/m1/modify' => Http::response($this->modified)]);

    $this->provider->changeLabels($this->mailbox, 'm1', ['Invoices/Filed'], ['Invoices/Never made']);

    $call = GmailApi::requests(GmailApi::BASE.'messages/m1/modify')[0];
    expect($call['addLabelIds'])->toBe(['Label_14'])
        ->and($call['removeLabelIds'])->toBe([]);
});

it('skips the modify call when nothing is left to change', function (): void {
    GmailApi::fake();

    $this->provider->changeLabels($this->mailbox, 'm1', [], ['Invoices/Never made']);

    expect(GmailApi::requests(GmailApi::BASE.'messages/*'))->toBeEmpty();
});

it('archives by removing INBOX, without reading labels', function (): void {
    GmailApi::fake(['messages/m1/modify' => Http::response($this->modified)]);

    $this->provider->archive($this->mailbox, 'm1');

    expect(GmailApi::requests(GmailApi::BASE.'messages/m1/modify')[0]->data())->toBe(['removeLabelIds' => ['INBOX']])
        ->and(GmailApi::requests(GmailApi::BASE.'labels'))->toBeEmpty();
});

it('reports a message that is gone when labelling or archiving', function (Closure $call): void {
    GmailApi::fake(['messages/*' => Http::response(GmailApi::fixture('error-not-found'), 404)]);

    $call($this->provider, $this->mailbox);
})->with([
    'labels' => [fn ($provider, $mailbox) => $provider->changeLabels($mailbox, 'gone', ['Invoices/Filed'], [])],
    'archive' => [fn ($provider, $mailbox) => $provider->archive($mailbox, 'gone')],
])->throws(MessageNotFound::class);

it('keeps a 400 on a message that message\'s failure, without retrying or giving up the mailbox', function (): void {
    GmailApi::fake(['messages/m1/modify' => Http::response(['error' => ['code' => 400, 'errors' => [['reason' => 'invalidArgument']]]], 400)]);

    expect(fn () => $this->provider->archive($this->mailbox, 'm1'))->toThrow(ProviderUnavailable::class);

    expect(GmailApi::requests(GmailApi::BASE.'messages/m1/modify'))->toHaveCount(1);
});

it('forgets the label map when Gmail refuses a write, so a deleted label is not sent again', function (): void {
    GmailApi::fake([
        'labels' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['id' => 'Label_50', 'name' => $request['name'], 'type' => 'user'])
            : Http::response(count(GmailApi::requests(GmailApi::BASE.'labels')) === 0 ? GmailApi::fixture('labels') : ['labels' => []]),
        'messages/m1/modify' => Http::sequence()
            ->push(['error' => ['code' => 400, 'errors' => [['reason' => 'invalidArgument']]]], 400)
            ->push($this->modified),
    ]);

    expect(fn () => $this->provider->changeLabels($this->mailbox, 'm1', ['Invoices/Filed'], []))->toThrow(ProviderUnavailable::class);

    $this->provider->changeLabels($this->mailbox, 'm1', ['Invoices/Filed'], []);

    expect(GmailApi::requests(GmailApi::BASE.'messages/m1/modify')[1]['addLabelIds'])->toBe(['Label_50']);
});
