<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RobinsonRyan\Mailroom\Contracts\MailProvider;
use RobinsonRyan\Mailroom\Data\GatewayAnswer;
use RobinsonRyan\Mailroom\Data\HistoryChange;
use RobinsonRyan\Mailroom\Data\HistoryPage;
use RobinsonRyan\Mailroom\Enums\ChangeKind;
use RobinsonRyan\Mailroom\Enums\DecidedBy;
use RobinsonRyan\Mailroom\Enums\MailboxStatus;
use RobinsonRyan\Mailroom\Events\GatewayStuck;
use RobinsonRyan\Mailroom\Events\MailboxLostAccess;
use RobinsonRyan\Mailroom\Events\MessageTyped;
use RobinsonRyan\Mailroom\Exceptions\CursorExpired;
use RobinsonRyan\Mailroom\Exceptions\MailboxDisconnected;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Exceptions\ProviderUnavailable;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\LabelWrite;
use RobinsonRyan\Mailroom\Models\Message;
use RobinsonRyan\Mailroom\Models\Typing;
use RobinsonRyan\Mailroom\ProviderRegistry;
use RobinsonRyan\Mailroom\Testing\FakeProvider;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\FakeGateway;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\InvoiceSet;
use RobinsonRyan\Mailroom\Watching\Watcher;

const INFO = 'info@example.com';

beforeEach(function (): void {
    $this->fake = FakeProvider::install();
    $this->gateway = FakeGateway::install();
    InvoiceSet::register();
    $this->mailroom = app(Mailroom::class);
    $this->mailbox = $this->mailroom->connect(INFO, 'afwd-info');
});

function tick(string ...$options): void
{
    test()->artisan('mailroom:watch', $options === [] ? [] : ['--mailbox' => $options[0]])->run();
}

/** A tick that has a cursor to read from. */
function watching(): void
{
    tick();
    test()->mailbox->refresh();
}

describe('ticks', function (): void {
    it('starts from now: the first tick stores the cursor and handles nothing', function (): void {
        $this->fake->deliver(INFO);

        tick();

        $this->mailbox->refresh();
        expect($this->mailbox->cursor)->toBe($this->fake->currentCursor($this->mailbox))
            ->and($this->mailbox->last_polled_at)->not->toBeNull()
            ->and(Message::query()->count())->toBe(0)
            ->and($this->gateway->asks)->toBe([]);
    });

    it('types what arrived since the cursor, stores the new cursor and the poll time', function (): void {
        watching();
        $this->travel(1)->minutes();
        $id = $this->fake->deliver(INFO);
        $cursor = $this->fake->currentCursor($this->mailbox);

        tick();

        $this->mailbox->refresh();
        expect(Typing::query()->sole()->type)->toBe('invoice')
            ->and(Message::query()->sole()->provider_message_id)->toBe($id)
            ->and($this->mailbox->cursor)->toBe($cursor)
            ->and($this->mailbox->last_polled_at?->greaterThan(Carbon\CarbonImmutable::now()->subSeconds(5)))->toBeTrue();
    });

    it('does not handle a change twice across ticks', function (): void {
        watching();
        $this->fake->deliver(INFO);
        tick();
        tick();
        tick();

        expect($this->gateway->asks)->toHaveCount(1);
    });

    it('handles changes oldest first', function (): void {
        watching();
        $first = $this->fake->deliver(INFO);
        $second = $this->fake->deliver(INFO);

        tick();

        expect(array_column($this->gateway->asks, 'ref'))->toBe(["mail:info@example.com:{$first}", "mail:info@example.com:{$second}"]);
    });

    it('keeps the old cursor when a change could not be handled, and picks up where it stopped', function (): void {
        watching();
        $cursor = $this->mailbox->cursor;
        $first = $this->fake->deliver(INFO);
        $second = $this->fake->deliver(INFO);
        $blow = true;
        Event::listen(MessageTyped::class, function () use (&$blow): void {
            if ($blow) {
                $blow = false;

                throw new RuntimeException('listener failed');
            }
        });

        $this->artisan('mailroom:watch')->assertFailed();

        expect($this->mailbox->refresh()->cursor)->toBe($cursor)
            ->and(Typing::query()->count())->toBe(1);

        tick();

        expect($this->mailbox->refresh()->cursor)->not->toBe($cursor)
            ->and(Typing::query()->count())->toBe(2)
            ->and(Message::query()->pluck('provider_message_id')->sort()->values()->all())->toBe([$first, $second]);
    });

    it('watches only active mailboxes', function (): void {
        $paused = $this->mailroom->connect('paused@example.com', 'x');
        $paused->update(['status' => MailboxStatus::Paused]);
        $gone = $this->mailroom->connect('gone@example.com', 'x');
        $gone->update(['status' => MailboxStatus::Disconnected]);

        tick();

        expect($this->mailbox->refresh()->cursor)->not->toBeNull()
            ->and($paused->refresh()->cursor)->toBeNull()
            ->and($gone->refresh()->cursor)->toBeNull();
    });

    it('limits a tick to one mailbox when asked, matching the address loosely', function (): void {
        $other = $this->mailroom->connect('other@example.com', 'x');

        tick('Other@Example.com');

        expect($other->refresh()->cursor)->not->toBeNull()
            ->and($this->mailbox->refresh()->cursor)->toBeNull();
    });

    it('lets a failing mailbox not stop the others', function (): void {
        $broken = Mockery::mock(MailProvider::class);
        $broken->shouldReceive('currentCursor')->andThrow(new ProviderUnavailable('Rate limited.'));
        app(ProviderRegistry::class)->swap('broken', $broken);
        $first = $this->mailroom->connect('a-first@example.com', 'x', 'broken');
        $unknown = $this->mailroom->connect('b-unknown@example.com', 'x', 'no-such-provider');
        $this->mailbox->update(['address' => 'z-last@example.com']);

        $this->artisan('mailroom:watch')->assertFailed();

        expect($this->mailbox->refresh()->cursor)->not->toBeNull()
            ->and($first->refresh()->cursor)->toBeNull()
            ->and($first->status)->toBe(MailboxStatus::Active)
            ->and($unknown->refresh()->cursor)->toBeNull();
    });

    it('skips a mailbox whose lock another tick holds', function (): void {
        watching();
        $cursor = $this->mailbox->cursor;
        $this->fake->deliver(INFO);
        $held = Cache::lock(Watcher::lockName($this->mailbox), 60);
        expect($held->get())->toBeTrue();

        $this->artisan('mailroom:watch')->assertSuccessful();

        expect($this->gateway->asks)->toBe([])
            ->and($this->mailbox->refresh()->cursor)->toBe($cursor);

        $held->release();
        tick();

        expect($this->gateway->asks)->toHaveCount(1);
    });

    it('releases its lock after a tick, even a failed one', function (): void {
        $broken = Mockery::mock(MailProvider::class);
        $broken->shouldReceive('currentCursor')->andThrow(new ProviderUnavailable('Rate limited.'));
        app(ProviderRegistry::class)->swap('broken', $broken);
        $mailbox = $this->mailroom->connect('broken@example.com', 'x', 'broken');

        tick();

        expect(Cache::lock(Watcher::lockName($mailbox), 60)->get())->toBeTrue();
    });

    it('reads the cursor under the lock, so a tick that waited does not replay the last one', function (): void {
        $this->mailbox->update(['cursor' => '5']);
        $stale = $this->mailbox->fresh();
        $this->mailbox->update(['cursor' => '9']);
        $provider = Mockery::mock(MailProvider::class);
        $provider->shouldReceive('history')->once()->with(Mockery::any(), '9')->andReturn(new HistoryPage([], '9'));
        app(ProviderRegistry::class)->swap('gmail', $provider);

        app(Watcher::class)->tick($stale);

        expect($this->mailbox->refresh()->cursor)->toBe('9');
    });

    it('checks the status under the lock, so a tick that waited does not poll a lost mailbox', function (): void {
        Event::fake([MailboxLostAccess::class]);
        $stale = $this->mailbox->fresh();
        $this->mailbox->update(['status' => MailboxStatus::Disconnected]);

        app(Watcher::class)->tick($stale);

        expect($this->mailbox->refresh()->cursor)->toBeNull();
        Event::assertNotDispatched(MailboxLostAccess::class);
    });

    it('skips a message deleted before it could be read, and moves on', function (): void {
        watching();
        $live = $this->fake->deliver(INFO);
        $vanishing = Mockery::mock(MailProvider::class);
        $vanishing->shouldReceive('history')->andReturn(new HistoryPage([
            new HistoryChange(ChangeKind::MessageAdded, 'deleted', 't'),
            new HistoryChange(ChangeKind::LabelAdded, 'deleted', 't', ['Invoices/Invoice']),
            new HistoryChange(ChangeKind::MessageAdded, $live, 't'.$live),
        ], '999'));
        $vanishing->shouldReceive('summary')->with(Mockery::any(), 'deleted', Mockery::any())->andThrow(new MessageNotFound('Gone.'));
        $vanishing->shouldReceive('summary')->with(Mockery::any(), $live, Mockery::any())->andReturnUsing(
            fn ($mailbox, $id, $limit) => $this->fake->summary($mailbox, $id, $limit),
        );
        $vanishing->shouldReceive('changeLabels')->andReturnUsing(fn ($mailbox, $id, $add, $remove) => $this->fake->changeLabels($mailbox, $id, $add, $remove));
        app(ProviderRegistry::class)->swap('gmail', $vanishing);

        tick();

        expect($this->mailbox->refresh()->cursor)->toBe('999')
            ->and(Message::query()->sole()->provider_message_id)->toBe($live);
    });
});

describe('resync', function (): void {
    it('types every untyped message in the window, leaves typed ones alone and stores a cursor', function (): void {
        watching();
        $typed = $this->fake->deliver(INFO);
        tick();
        $this->gateway->asks = [];
        $missed = $this->fake->deliver(INFO);
        $this->fake->expireHistory(INFO);

        tick();

        expect(array_column($this->gateway->asks, 'ref'))->toBe(["mail:info@example.com:{$missed}"])
            ->and(Typing::query()->count())->toBe(2)
            ->and(Message::query()->where('provider_message_id', $typed)->sole()->typings()->count())->toBe(1)
            ->and($this->mailbox->refresh()->last_polled_at)->not->toBeNull();

        tick();
        expect($this->gateway->asks)->toHaveCount(1);
    });

    it('searches the configured window', function (): void {
        config(['mailroom.watch.resync_days' => 3]);
        $this->mailbox->update(['cursor' => '5']);
        $provider = Mockery::mock(MailProvider::class);
        $provider->shouldReceive('history')->andThrow(new CursorExpired('Gone.'));
        $provider->shouldReceive('currentCursor')->andReturn('77');
        $provider->shouldReceive('search')->once()->with(Mockery::any(), 'newer_than:3d')->andReturn([]);
        app(ProviderRegistry::class)->swap('gmail', $provider);

        tick();

        expect($this->mailbox->refresh()->cursor)->toBe('77');
    });

    it('takes the new cursor before searching, so what lands during the resync still comes through the history', function (): void {
        $this->mailbox->update(['cursor' => '5']);
        $provider = Mockery::mock(MailProvider::class);
        $provider->shouldReceive('history')->andThrow(new CursorExpired('Gone.'));
        $provider->shouldReceive('currentCursor')->once()->ordered()->andReturn('77');
        $provider->shouldReceive('search')->once()->ordered()->andReturn([]);
        app(ProviderRegistry::class)->swap('gmail', $provider);

        tick();

        expect($this->mailbox->refresh()->cursor)->toBe('77');
    });

    it('skips a message that vanished between the search and the read', function (): void {
        $this->mailbox->update(['cursor' => '5']);
        $live = $this->fake->deliver(INFO);
        $provider = Mockery::mock(MailProvider::class);
        $provider->shouldReceive('history')->andThrow(new CursorExpired('Gone.'));
        $provider->shouldReceive('currentCursor')->andReturn('77');
        $provider->shouldReceive('search')->andReturn(['deleted', $live]);
        $provider->shouldReceive('summary')->with(Mockery::any(), 'deleted', Mockery::any())->andThrow(new MessageNotFound('Gone.'));
        $provider->shouldReceive('summary')->with(Mockery::any(), $live, Mockery::any())->andReturnUsing(
            fn ($mailbox, $id, $limit) => $this->fake->summary($mailbox, $id, $limit),
        );
        $provider->shouldReceive('changeLabels');
        app(ProviderRegistry::class)->swap('gmail', $provider);

        tick();

        expect($this->mailbox->refresh()->cursor)->toBe('77')
            ->and(Typing::query()->count())->toBe(1);
    });

    it('leaves messages for the retry queue when the gateway is down, and still stores the cursor', function (): void {
        watching();
        $this->fake->deliver(INFO);
        $this->fake->deliver(INFO);
        $this->fake->expireHistory(INFO);
        $this->gateway->down = true;

        tick();

        expect(Message::query()->count())->toBe(2)
            ->and($this->gateway->asks)->toHaveCount(1)
            ->and($this->mailbox->refresh()->cursor)->toBe($this->fake->currentCursor($this->mailbox));

        $this->gateway->down = false;
        tick();

        expect(Typing::query()->count())->toBe(2);
    });
});

describe('disconnection', function (): void {
    it('marks the mailbox disconnected, fires once and stops watching it', function (): void {
        watching();
        Event::fake([MailboxLostAccess::class]);
        $this->fake->disconnect(INFO);

        tick();
        tick();

        $this->mailbox->refresh();
        expect($this->mailbox->status)->toBe(MailboxStatus::Disconnected)
            ->and($this->mailbox->disconnected_at)->not->toBeNull();

        Event::assertDispatchedTimes(MailboxLostAccess::class, 1);
    });

    it('is not a failed run: the other mailboxes are fine', function (): void {
        watching();
        $this->fake->disconnect(INFO);

        $this->artisan('mailroom:watch')->assertSuccessful();
    });

    it('catches a disconnection on the very first tick', function (): void {
        Event::fake([MailboxLostAccess::class]);
        $this->fake->disconnect(INFO);

        tick();

        expect($this->mailbox->refresh()->status)->toBe(MailboxStatus::Disconnected);
        Event::assertDispatchedTimes(MailboxLostAccess::class, 1);
    });

    it('catches a disconnection partway through handling a change', function (): void {
        watching();
        Event::fake([MailboxLostAccess::class]);
        $this->fake->deliver(INFO);
        $cursor = $this->mailbox->cursor;
        $provider = Mockery::mock(MailProvider::class);
        $provider->shouldReceive('history')->andReturnUsing(fn ($mailbox, $from) => $this->fake->history($mailbox, $from));
        $provider->shouldReceive('summary')->andThrow(new MailboxDisconnected('Refused.'));
        app(ProviderRegistry::class)->swap('gmail', $provider);

        tick();

        expect($this->mailbox->refresh()->status)->toBe(MailboxStatus::Disconnected)
            ->and($this->mailbox->cursor)->toBe($cursor);
        Event::assertDispatchedTimes(MailboxLostAccess::class, 1);
    });

    it('resumes from its cursor once reconnected', function (): void {
        watching();
        $this->fake->disconnect(INFO);
        tick();

        $fresh = FakeProvider::install();
        $this->mailbox->refresh();
        expect($this->mailbox->isWatched())->toBeFalse();

        $this->mailroom->connect(INFO, 'afwd-info');
        $id = $fresh->deliver(INFO);
        tick();

        expect($this->mailbox->refresh()->isWatched())->toBeTrue()
            ->and(Message::query()->where('provider_message_id', $id)->exists())->toBeTrue();
    });
});

describe('retry queue', function (): void {
    it('keeps an untyped message and types it on a later tick, recovering the outage', function (): void {
        Event::fake([GatewayStuck::class]);
        watching();
        $this->gateway->down = true;
        $id = $this->fake->deliver(INFO);
        tick();

        expect(Message::query()->sole()->provider_message_id)->toBe($id)
            ->and(Typing::query()->count())->toBe(0);

        $this->gateway->down = false;
        tick();

        expect(Typing::query()->sole()->type)->toBe('invoice');
    });

    it('stops asking the gateway for the rest of a tick once it has failed', function (): void {
        watching();
        $this->gateway->down = true;
        $this->fake->deliver(INFO);
        $this->fake->deliver(INFO);
        $this->fake->deliver(INFO);

        tick();

        expect($this->gateway->asks)->toHaveCount(1)
            ->and(Message::query()->count())->toBe(3);

        tick();

        expect($this->gateway->asks)->toHaveCount(2);
    });

    it('retries oldest first and only so many a tick', function (): void {
        watching();
        $this->gateway->down = true;
        $now = Carbon\CarbonImmutable::now();
        $ids = [];
        foreach (range(1, Watcher::RETRIES_PER_TICK + 2) as $age) {
            $ids[] = $this->fake->deliver(INFO, receivedAt: $now->subMinutes($age));
        }
        tick();
        $this->gateway->down = false;
        $this->gateway->asks = [];

        tick();

        expect($this->gateway->asks)->toHaveCount(Watcher::RETRIES_PER_TICK)
            ->and($this->gateway->asks[0]['ref'])->toBe('mail:info@example.com:'.end($ids))
            ->and(Typing::query()->count())->toBe(Watcher::RETRIES_PER_TICK);

        tick();

        expect(Typing::query()->count())->toBe(count($ids));
    });

    it('drops a waiting message that has since been deleted, so it cannot clog the queue', function (): void {
        watching();
        $this->gateway->down = true;
        $deleted = $this->fake->deliver(INFO);
        tick();
        $this->gateway->down = false;
        $provider = Mockery::mock(MailProvider::class);
        $provider->shouldReceive('history')->andReturnUsing(fn ($mailbox, $from) => $this->fake->history($mailbox, $from));
        $provider->shouldReceive('summary')->andThrow(new MessageNotFound('Gone.'));
        app(ProviderRegistry::class)->swap('gmail', $provider);

        tick();

        expect(Message::query()->where('provider_message_id', $deleted)->exists())->toBeFalse();
    });

    it('fires GatewayStuck once after the configured minutes of failed ticks', function (): void {
        Event::fake([GatewayStuck::class]);
        watching();
        $this->gateway->down = true;
        $this->fake->deliver(INFO);
        tick();

        $this->travel(29)->minutes();
        tick();
        Event::assertNotDispatched(GatewayStuck::class);

        $this->travel(2)->minutes();
        tick();
        tick();
        Event::assertDispatchedTimes(GatewayStuck::class, 1);
    });

    it('counts a new outage from scratch once a typing has got through', function (): void {
        Event::fake([GatewayStuck::class]);
        watching();
        $this->gateway->down = true;
        $this->fake->deliver(INFO);
        tick();
        $this->travel(31)->minutes();
        tick();
        Event::assertDispatchedTimes(GatewayStuck::class, 1);

        $this->gateway->down = false;
        tick();

        $this->gateway->down = true;
        $this->fake->deliver(INFO);
        tick();
        $this->travel(29)->minutes();
        tick();
        Event::assertDispatchedTimes(GatewayStuck::class, 1);

        $this->travel(2)->minutes();
        tick();
        Event::assertDispatchedTimes(GatewayStuck::class, 2);
    });

    it('survives an unknown type on one message without giving up on the next', function (): void {
        watching();
        $bad = $this->fake->deliver(INFO, subject: 'bad');
        $good = $this->fake->deliver(INFO, subject: 'good');
        $this->gateway->using = fn ($set, string $state): GatewayAnswer => str_contains($state, 'bad')
            ? new GatewayAnswer('made_up', 0.9, null)
            : new GatewayAnswer('invoice', 0.9, null);

        tick();

        expect(Typing::query()->sole()->message->provider_message_id)->toBe($good)
            ->and(Message::query()->where('provider_message_id', $bad)->exists())->toBeTrue();
    });
});

describe('an answer the set cannot use', function (): void {
    it('stops asking after three and never calls the gateway stuck', function (): void {
        Event::fake([GatewayStuck::class]);
        watching();
        $this->gateway->answers('made_up', 0.9);
        $this->fake->deliver(INFO);

        foreach (range(1, 5) as $tick) {
            tick();
            $this->travel(10)->minutes();
        }

        $typing = Typing::query()->sole();
        expect($this->gateway->asks)->toHaveCount(3)
            ->and($typing->type)->toBe('other')
            ->and($typing->confident)->toBeFalse();

        Event::assertNotDispatched(GatewayStuck::class);
    });

    it('takes it as the gateway being back, ending an outage', function (): void {
        Event::fake([GatewayStuck::class]);
        watching();
        $this->gateway->down = true;
        $this->fake->deliver(INFO);
        tick();
        $this->gateway->down = false;
        $this->gateway->answers('made_up', 0.9);
        tick();

        $this->travel(31)->minutes();
        $this->gateway->down = true;
        tick();

        Event::assertNotDispatched(GatewayStuck::class);
    });
});

describe('label changes', function (): void {
    it('recognises its own label write as an echo, not a correction', function (): void {
        watching();
        Event::fake([MessageTyped::class]);
        $this->fake->deliver(INFO);

        tick();
        tick();

        expect(Typing::query()->count())->toBe(1)
            ->and(LabelWrite::query()->sole()->echoed_at)->not->toBeNull();

        Event::assertDispatchedTimes(MessageTyped::class, 1);
    });

    it('recognises the echoes of a correction Mailroom made itself', function (): void {
        watching();
        $id = $this->fake->deliver(INFO);
        tick();
        tick();
        $this->mailroom->correct($this->fake->ref($this->mailbox, $id), 'invoices', 'bill_ready');

        tick();

        expect(Typing::query()->count())->toBe(2)
            ->and(LabelWrite::query()->whereNull('echoed_at')->count())->toBe(0)
            ->and(Message::query()->sole()->currentTyping('invoices')?->type)->toBe('bill_ready');
    });

    it('turns a person\'s label into a correction', function (): void {
        watching();
        $id = $this->fake->deliver(INFO);
        tick();
        tick();
        Event::fake([MessageTyped::class]);

        $this->fake->personLabels(INFO, $id, add: ['Invoices/Bill ready'], remove: ['Invoices/Invoice']);
        tick();

        $current = Message::query()->sole()->currentTyping('invoices');
        expect($current?->decided_by)->toBe(DecidedBy::Person)
            ->and($current?->type)->toBe('bill_ready')
            ->and(Typing::query()->whereNotNull('superseded_by_id')->count())->toBe(1)
            ->and($this->gateway->labels)->toHaveCount(1);

        Event::assertDispatchedTimes(MessageTyped::class, 1);
    });

    it('adds a person\'s label alongside the old one as a correction too, leaving both in place', function (): void {
        watching();
        $id = $this->fake->deliver(INFO);
        tick();
        tick();

        $this->fake->personLabels(INFO, $id, add: ['Invoices/Bill ready']);
        tick();

        expect(Message::query()->sole()->currentTyping('invoices')?->type)->toBe('bill_ready')
            ->and($this->fake->labelsOf(INFO, $id))->toContain('Invoices/Invoice', 'Invoices/Bill ready');
    });

    it('confirms a below-threshold guess when a person applies its label', function (): void {
        watching();
        $this->gateway->answers('invoice', 0.5);
        $id = $this->fake->deliver(INFO);
        tick();

        $this->fake->personLabels(INFO, $id, add: ['Invoices/Invoice']);
        tick();

        $current = Message::query()->sole()->currentTyping('invoices');
        expect($current?->decided_by)->toBe(DecidedBy::Person)
            ->and($current?->confident)->toBeTrue();
    });

    it('does not count a person removing a type label as a correction', function (): void {
        watching();
        $id = $this->fake->deliver(INFO);
        tick();
        tick();

        $this->fake->personLabels(INFO, $id, remove: ['Invoices/Invoice']);
        tick();

        expect(Typing::query()->sole()->decided_by)->toBe(DecidedBy::Gateway)
            ->and($this->gateway->labels)->toBe([]);
    });

    it('ignores labels in the prefix that are not types, and labels outside every prefix', function (): void {
        watching();
        $id = $this->fake->deliver(INFO);
        tick();
        tick();

        $this->fake->personLabels(INFO, $id, add: ['Invoices/Filed', 'Personal/Stuff', 'STARRED']);
        tick();

        expect(Typing::query()->count())->toBe(1)
            ->and($this->gateway->labels)->toBe([]);
    });

    it('takes a person\'s label on a waiting message as its typing, ending the retry', function (): void {
        watching();
        $this->gateway->down = true;
        $id = $this->fake->deliver(INFO);
        tick();
        $this->fake->personLabels(INFO, $id, add: ['Invoices/Invoice']);
        $this->gateway->down = false;
        $this->gateway->asks = [];

        tick();

        expect(Typing::query()->sole()->decided_by)->toBe(DecidedBy::Person)
            ->and($this->gateway->asks)->toBe([]);
    });

    it('accepts a person labelling an older message Mailroom has never seen', function (): void {
        $id = $this->fake->deliver(INFO, from: 'Old <a@v.com>', subject: 'Old invoice');
        watching();

        $this->fake->personLabels(INFO, $id, add: ['Invoices/Invoice']);
        tick();

        $message = Message::query()->sole();
        expect($message->subject)->toBe('Old invoice')
            ->and($message->currentTyping('invoices')?->decided_by)->toBe(DecidedBy::Person);
    });

    it('ignores a type label from a set that does not apply to the mailbox', function (): void {
        InvoiceSet::register(mailboxes: ['other@example.com'], key: 'elsewhere', prefix: 'Elsewhere');
        watching();
        $id = $this->fake->deliver(INFO);
        tick();

        $this->fake->personLabels(INFO, $id, add: ['Elsewhere/Invoice']);
        tick();

        expect(Typing::query()->sole()->type_set)->toBe('invoices');
    });

    it('takes a person putting an earlier label back as a new correction', function (): void {
        watching();
        $id = $this->fake->deliver(INFO);
        tick();
        $this->fake->personLabels(INFO, $id, remove: ['Invoices/Invoice']);
        tick();
        $this->fake->personLabels(INFO, $id, add: ['Invoices/Bill ready']);
        tick();
        $this->fake->personLabels(INFO, $id, add: ['Invoices/Invoice']);
        tick();

        expect(Message::query()->sole()->currentTyping('invoices')?->type)->toBe('invoice')
            ->and(Message::query()->sole()->currentTyping('invoices')?->decided_by)->toBe(DecidedBy::Person);
    });
});
