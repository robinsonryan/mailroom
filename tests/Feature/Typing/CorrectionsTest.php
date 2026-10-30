<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RobinsonRyan\Mailroom\Contracts\Corrections;
use RobinsonRyan\Mailroom\Contracts\MailProvider;
use RobinsonRyan\Mailroom\Enums\DecidedBy;
use RobinsonRyan\Mailroom\Enums\LabelAction;
use RobinsonRyan\Mailroom\Events\MessageTyped;
use RobinsonRyan\Mailroom\Exceptions\UnknownTypeSet;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\LabelWrite;
use RobinsonRyan\Mailroom\Models\Message;
use RobinsonRyan\Mailroom\Models\Typing;
use RobinsonRyan\Mailroom\ProviderRegistry;
use RobinsonRyan\Mailroom\Testing\FakeProvider;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\FakeGateway;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\InvoiceSet;
use RobinsonRyan\Mailroom\Typing\Typist;

beforeEach(function (): void {
    $this->fake = FakeProvider::install();
    $this->gateway = FakeGateway::install();
    InvoiceSet::register();
    $this->mailroom = app(Mailroom::class);
    $this->mailbox = $this->mailroom->connect('info@example.com', 'afwd-info');
    $this->id = $this->fake->deliver('info@example.com');
    $this->ref = $this->fake->ref($this->mailbox, $this->id);
});

function typeFirst(): Typing
{
    $test = test();
    app(Typist::class)->type($test->mailbox, $test->id);

    return Typing::query()->sole();
}

it('binds the real corrections, reachable through Mailroom without a resolution loop', function (): void {
    typeFirst();

    expect($this->mailroom->correct($this->ref, 'invoices', 'bill_ready')->decided_by)->toBe(DecidedBy::Person);
});

it('writes a person typing, supersedes the old one, moves the label and reports it', function (): void {
    $old = typeFirst();
    Event::fake([MessageTyped::class]);

    $new = app(Corrections::class)->correct($this->ref, 'invoices', 'bill_ready');

    expect($new->decided_by)->toBe(DecidedBy::Person)
        ->and($new->type)->toBe('bill_ready')
        ->and($new->confidence)->toBe(1.0)
        ->and($new->confident)->toBeTrue()
        ->and($new->type_set_version)->toBe(3)
        ->and($old->refresh()->superseded_by_id)->toBe($new->id)
        ->and(Message::query()->sole()->currentTyping('invoices')?->id)->toBe($new->id)
        ->and($this->fake->labelsOf('info@example.com', $this->id))->toContain('Invoices/Bill ready')
        ->and($this->fake->labelsOf('info@example.com', $this->id))->not->toContain('Invoices/Invoice')
        ->and(LabelWrite::query()->where('label', 'Invoices/Invoice')->where('action', LabelAction::Remove)->count())->toBe(1)
        ->and(LabelWrite::query()->where('label', 'Invoices/Bill ready')->where('action', LabelAction::Add)->count())->toBe(1);

    Event::assertDispatched(MessageTyped::class, fn (MessageTyped $e): bool => $e->decidedBy === DecidedBy::Person
        && $e->type === 'bill_ready' && $e->confidence === 1.0 && $e->confident && $e->typingId === $new->id);

    expect($this->gateway->labels)->toBe([
        ['type_set' => 'invoices', 'ref' => "mail:info@example.com:{$this->id}", 'type' => 'bill_ready'],
    ]);
});

it('writes no labels when the person already changed them in the mailbox', function (): void {
    typeFirst();
    $this->fake->personLabels('info@example.com', $this->id, add: ['Invoices/Bill ready'], remove: ['Invoices/Invoice']);
    $writes = LabelWrite::query()->count();

    app(Corrections::class)->correct($this->ref, 'invoices', 'bill_ready', fromLabel: true);

    expect(LabelWrite::query()->count())->toBe($writes)
        ->and(Typing::query()->count())->toBe(2)
        ->and($this->gateway->labels)->toHaveCount(1);
});

it('removes the old label and adds nothing for a type that is never labelled', function (): void {
    typeFirst();

    $this->mailroom->correct($this->ref, 'invoices', 'other');

    expect($this->fake->labelsOf('info@example.com', $this->id))->toBe(['INBOX']);
});

it('does not try to remove a label a below-threshold typing never wrote', function (): void {
    $this->gateway->answers('invoice', 0.5);
    typeFirst();

    $this->mailroom->correct($this->ref, 'invoices', 'bill_ready');

    expect(LabelWrite::query()->where('action', LabelAction::Remove)->count())->toBe(0)
        ->and($this->fake->labelsOf('info@example.com', $this->id))->toContain('Invoices/Bill ready');
});

it('does nothing when the type matches the current typing', function (): void {
    $old = typeFirst();
    Event::fake([MessageTyped::class]);

    $result = $this->mailroom->correct($this->ref, 'invoices', 'invoice');

    expect($result->id)->toBe($old->id)
        ->and(Typing::query()->count())->toBe(1)
        ->and($this->gateway->labels)->toBe([]);

    Event::assertNotDispatched(MessageTyped::class);
});

it('records a person confirming a below-threshold guess, so the message moves on', function (): void {
    $this->gateway->answers('invoice', 0.5);
    $old = typeFirst();
    Event::fake([MessageTyped::class]);

    $new = $this->mailroom->correct($this->ref, 'invoices', 'invoice');

    expect($new->decided_by)->toBe(DecidedBy::Person)
        ->and($old->refresh()->superseded_by_id)->toBe($new->id)
        ->and($this->fake->labelsOf('info@example.com', $this->id))->toContain('Invoices/Invoice');

    Event::assertDispatched(MessageTyped::class, fn (MessageTyped $e): bool => $e->confident);
});

it('types a message that has no typing yet', function (): void {
    $typing = $this->mailroom->correct($this->ref, 'invoices', 'invoice');

    expect($typing->decided_by)->toBe(DecidedBy::Person)
        ->and($this->fake->labelsOf('info@example.com', $this->id))->toContain('Invoices/Invoice');
});

it('logs a gateway failure and still completes the correction', function (): void {
    typeFirst();
    $this->gateway->labelsDown = true;
    Log::spy();

    $typing = $this->mailroom->correct($this->ref, 'invoices', 'bill_ready');

    expect($typing->decided_by)->toBe(DecidedBy::Person)
        ->and(Message::query()->sole()->currentTyping('invoices')?->id)->toBe($typing->id);

    Log::shouldHaveReceived('warning')->once();
});

it('reports to the gateway only after consumers have heard', function (): void {
    typeFirst();
    $labelsSeen = null;
    Event::listen(MessageTyped::class, function () use (&$labelsSeen): void {
        $labelsSeen = count($this->gateway->labels);
    });

    $this->mailroom->correct($this->ref, 'invoices', 'bill_ready');

    expect($labelsSeen)->toBe(0)
        ->and($this->gateway->labels)->toHaveCount(1);
});

it('leaves one current typing however many times a message is corrected, and can still be deleted', function (): void {
    typeFirst();

    $this->mailroom->correct($this->ref, 'invoices', 'bill_ready');
    $last = $this->mailroom->correct($this->ref, 'invoices', 'other');

    expect(Typing::query()->count())->toBe(3)
        ->and(Typing::query()->whereNull('superseded_by_id')->sole()->id)->toBe($last->id);

    Message::query()->sole()->delete();

    expect(Typing::query()->count())->toBe(0);
});

it('puts a person\'s word on top of a typing that landed while it was being recorded', function (): void {
    $message = $this->mailroom->record($this->ref);
    $provider = Mockery::mock(MailProvider::class);
    $provider->shouldReceive('changeLabels')->andReturnUsing(function () use ($message): void {
        if (Typing::query()->doesntExist()) {
            Typing::query()->create([
                'message_id' => $message->id, 'type_set' => 'invoices', 'type_set_version' => 3,
                'type' => 'invoice', 'decided_by' => DecidedBy::Gateway, 'confident' => true,
            ]);
        }
    });
    app(ProviderRegistry::class)->swap('gmail', $provider);
    Event::fake([MessageTyped::class]);

    $typing = $this->mailroom->correct($this->ref, 'invoices', 'bill_ready');

    expect(Typing::query()->whereNull('superseded_by_id')->sole()->id)->toBe($typing->id)
        ->and(Typing::query()->where('type', 'invoice')->sole()->superseded_by_id)->toBe($typing->id)
        ->and(LabelWrite::query()->where('label', 'Invoices/Invoice')->where('action', LabelAction::Remove)->count())->toBe(1);

    Event::assertDispatchedTimes(MessageTyped::class, 1);
});

it('keeps a correction and still reports it when a listener throws', function (): void {
    typeFirst();
    Event::listen(MessageTyped::class, function (): never {
        throw new RuntimeException('listener failed');
    });
    Log::spy();

    $typing = $this->mailroom->correct($this->ref, 'invoices', 'bill_ready');

    expect(Message::query()->sole()->currentTyping('invoices')?->id)->toBe($typing->id)
        ->and($this->gateway->labels)->toHaveCount(1);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['typing_id'] === $typing->id)->once();
});

it('refuses an unknown type set', function (): void {
    $this->mailroom->correct($this->ref, 'nope', 'invoice');
})->throws(UnknownTypeSet::class);

it('refuses a type the set does not define', function (): void {
    $this->mailroom->correct($this->ref, 'invoices', 'made_up');
})->throws(InvalidArgumentException::class);
