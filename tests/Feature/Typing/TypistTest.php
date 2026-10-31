<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RobinsonRyan\Mailroom\Data\GatewayAnswer;
use RobinsonRyan\Mailroom\Enums\DecidedBy;
use RobinsonRyan\Mailroom\Enums\LabelAction;
use RobinsonRyan\Mailroom\Events\MessageTyped;
use RobinsonRyan\Mailroom\Exceptions\MessageNotFound;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\LabelWrite;
use RobinsonRyan\Mailroom\Models\Message;
use RobinsonRyan\Mailroom\Models\Typing;
use RobinsonRyan\Mailroom\Testing\FakeProvider;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\FakeGateway;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\InvoiceSet;
use RobinsonRyan\Mailroom\TypeSets\PatternRule;
use RobinsonRyan\Mailroom\TypeSets\TypeDefinition;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;
use RobinsonRyan\Mailroom\TypeSets\TypeSetRegistry;
use RobinsonRyan\Mailroom\Typing\Typist;

beforeEach(function (): void {
    $this->fake = FakeProvider::install();
    $this->gateway = FakeGateway::install();
    $this->mailroom = app(Mailroom::class);
    $this->mailbox = $this->mailroom->connect('info@example.com', 'shop-info');
    $this->typist = app(Typist::class);
});

it('records the message metadata and never the body', function (): void {
    InvoiceSet::register();
    $id = $this->fake->deliver('info@example.com', from: 'Vendor <a@v.com>', subject: 'Invoice 7', text: 'secret body text');

    $this->typist->type($this->mailbox, $id);

    $message = Message::query()->sole();
    expect($message->from)->toBe('Vendor <a@v.com>')
        ->and($message->subject)->toBe('Invoice 7')
        ->and($message->received_at)->not->toBeNull()
        ->and(json_encode($message->getAttributes()))->not->toContain('secret body text');
});

it('fills in the metadata of a row Mailroom recorded without it', function (): void {
    InvoiceSet::register();
    $id = $this->fake->deliver('info@example.com', from: 'Vendor <a@v.com>', subject: 'Invoice 7');
    $this->mailroom->label($this->fake->ref($this->mailbox, $id), 'Invoices/Filed');

    $this->typist->type($this->mailbox, $id);

    expect(Message::query()->sole()->subject)->toBe('Invoice 7');
});

it('uses a matching rule without asking the gateway', function (): void {
    InvoiceSet::register(rules: [new PatternRule('invoice', from: 'starlink')]);
    Event::fake([MessageTyped::class]);
    $id = $this->fake->deliver('info@example.com', from: 'Starlink <billing@starlink.com>');

    $this->typist->type($this->mailbox, $id);

    $typing = Typing::query()->sole();
    expect($this->gateway->asks)->toBe([])
        ->and($typing->decided_by)->toBe(DecidedBy::Rule)
        ->and($typing->confidence)->toBeNull()
        ->and($typing->confident)->toBeTrue()
        ->and($typing->type_set_version)->toBe(3)
        ->and($this->fake->labelsOf('info@example.com', $id))->toContain('Invoices/Invoice');

    Event::assertDispatched(MessageTyped::class, fn (MessageTyped $e): bool => $e->decidedBy === DecidedBy::Rule
        && $e->confidence === null && $e->confident && $e->typingId === $typing->id);
});

it('asks the gateway when no rule matches, with the address in the ref and the state format', function (): void {
    InvoiceSet::register(rules: [new PatternRule('invoice', from: 'starlink')]);
    $at = Carbon\CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC');
    $id = $this->fake->deliver('info@example.com', from: 'V <a@v.com>', subject: 'Bill', text: 'Pay me', receivedAt: $at, attachments: [
        ['name' => 'inv.PDF', 'mime' => 'application/pdf', 'bytes' => 'x'],
        ['name' => 'logo.png', 'mime' => 'image/png', 'bytes' => 'x'],
        ['name' => 'more.pdf', 'mime' => 'application/pdf', 'bytes' => 'x'],
    ]);

    $this->typist->type($this->mailbox, $id);

    expect($this->gateway->asks)->toHaveCount(1)
        ->and($this->gateway->asks[0]['ref'])->toBe("mail:info@example.com:{$id}")
        ->and($this->gateway->asks[0]['type_set'])->toBe('invoices')
        ->and($this->gateway->asks[0]['state'])->toBe(
            "From: V <a@v.com>\nSubject: Bill\nDate: ".$at->toRfc2822String()."\nAttachments: inv.PDF, logo.png, more.pdf\n\nBody:\nPay me",
        );
});

it('names an image attachment, which is fileable too', function (): void {
    InvoiceSet::register();
    $id = $this->fake->deliver('info@example.com', attachments: [['name' => 'invoice-2026-10.jpg', 'mime' => 'image/jpeg', 'bytes' => 'x']]);

    $this->typist->type($this->mailbox, $id);

    expect($this->gateway->asks[0]['state'])->toContain("Attachments: invoice-2026-10.jpg\n");
});

it('says none when there are no attachments and cuts the text to the limit', function (): void {
    config(['mailroom.typing.text_limit' => 4]);
    InvoiceSet::register();
    $id = $this->fake->deliver('info@example.com', text: 'abcdefgh');

    $this->typist->type($this->mailbox, $id);

    expect($this->gateway->asks[0]['state'])->toContain("Attachments: none\n\nBody:\nabcd")
        ->and($this->gateway->asks[0]['state'])->not->toContain('abcde');
});

it('labels a confident gateway answer through Mailroom so the write is recorded', function (): void {
    InvoiceSet::register();
    Event::fake([MessageTyped::class]);
    $this->gateway->answers('bill_ready', 0.8, 'dec-9');
    $id = $this->fake->deliver('info@example.com');

    $this->typist->type($this->mailbox, $id);

    $typing = Typing::query()->sole();
    expect($typing->decided_by)->toBe(DecidedBy::Gateway)
        ->and($typing->type)->toBe('bill_ready')
        ->and($typing->confidence)->toBe(0.8)
        ->and($typing->confident)->toBeTrue()
        ->and($typing->decision_id)->toBe('dec-9')
        ->and($this->fake->labelsOf('info@example.com', $id))->toContain('Invoices/Bill ready')
        ->and(LabelWrite::query()->where('label', 'Invoices/Bill ready')->where('action', LabelAction::Add)->whereNull('echoed_at')->count())->toBe(1);

    Event::assertDispatched(MessageTyped::class, fn (MessageTyped $e): bool => $e->type === 'bill_ready'
        && $e->typeSet === 'invoices' && $e->confident && $e->confidence === 0.8
        && $e->decidedBy === DecidedBy::Gateway && $e->ref->providerMessageId === $id);
});

it('writes no label below the threshold but still saves the typing and fires', function (): void {
    InvoiceSet::register();
    Event::fake([MessageTyped::class]);
    $this->gateway->answers('invoice', 0.79);
    $id = $this->fake->deliver('info@example.com');

    $this->typist->type($this->mailbox, $id);

    expect(Typing::query()->sole()->confident)->toBeFalse()
        ->and($this->fake->labelsOf('info@example.com', $id))->toBe(['INBOX'])
        ->and(LabelWrite::query()->count())->toBe(0);

    Event::assertDispatched(MessageTyped::class, fn (MessageTyped $e): bool => ! $e->confident && $e->confidence === 0.79);
});

it('counts a null gateway confidence as not confident', function (): void {
    InvoiceSet::register();
    $this->gateway->answers('invoice', null);
    $id = $this->fake->deliver('info@example.com');

    $this->typist->type($this->mailbox, $id);

    expect(Typing::query()->sole()->confident)->toBeFalse()
        ->and($this->fake->labelsOf('info@example.com', $id))->toBe(['INBOX']);
});

it('writes no label for a confident answer whose type is never labelled', function (): void {
    InvoiceSet::register();
    Event::fake([MessageTyped::class]);
    $this->gateway->answers('other', 0.99);
    $id = $this->fake->deliver('info@example.com');

    $this->typist->type($this->mailbox, $id);

    expect(Typing::query()->sole()->type)->toBe('other')
        ->and($this->fake->labelsOf('info@example.com', $id))->toBe(['INBOX']);

    Event::assertDispatched(MessageTyped::class, fn (MessageTyped $e): bool => $e->confident);
});

it('writes no label the message already carries, which would never echo', function (): void {
    InvoiceSet::register();
    $id = $this->fake->deliver('info@example.com', labels: ['Invoices/Invoice']);

    $this->typist->type($this->mailbox, $id);

    expect(Typing::query()->count())->toBe(1)
        ->and(LabelWrite::query()->count())->toBe(0);
});

it('types one message for every set that applies to the mailbox', function (): void {
    InvoiceSet::register();
    InvoiceSet::register(key: 'legal', prefix: 'Legal');
    InvoiceSet::register(mailboxes: ['other@example.com'], key: 'elsewhere', prefix: 'Elsewhere');
    Event::fake([MessageTyped::class]);
    $id = $this->fake->deliver('info@example.com');

    $this->typist->type($this->mailbox, $id);

    expect(Typing::query()->pluck('type_set')->sort()->values()->all())->toBe(['invoices', 'legal'])
        ->and($this->fake->labelsOf('info@example.com', $id))->toContain('Invoices/Invoice', 'Legal/Invoice');

    Event::assertDispatchedTimes(MessageTyped::class, 2);
});

it('skips a set the message is already typed for, without touching the provider or the gateway', function (): void {
    InvoiceSet::register();
    $id = $this->fake->deliver('info@example.com');
    $this->typist->type($this->mailbox, $id);
    $this->gateway->asks = [];

    Event::fake([MessageTyped::class]);
    $this->typist->type($this->mailbox, $id);

    expect($this->gateway->asks)->toBe([])
        ->and(Typing::query()->count())->toBe(1);

    Event::assertNotDispatched(MessageTyped::class);
});

it('types only the missing set when a message is typed for one of two', function (): void {
    InvoiceSet::register();
    $id = $this->fake->deliver('info@example.com');
    $this->typist->type($this->mailbox, $id);
    InvoiceSet::register(key: 'legal', prefix: 'Legal');
    $this->gateway->asks = [];

    $this->typist->type($this->mailbox, $id);

    expect(array_column($this->gateway->asks, 'type_set'))->toBe(['legal'])
        ->and(Typing::query()->count())->toBe(2);
});

it('records the message and saves no typing when the gateway is down', function (): void {
    InvoiceSet::register();
    Event::fake([MessageTyped::class]);
    $this->gateway->down = true;
    $id = $this->fake->deliver('info@example.com');

    $stillUp = $this->typist->type($this->mailbox, $id);

    expect($stillUp)->toBeFalse()
        ->and(Message::query()->count())->toBe(1)
        ->and(Typing::query()->count())->toBe(0)
        ->and($this->fake->labelsOf('info@example.com', $id))->toBe(['INBOX']);

    Event::assertNotDispatched(MessageTyped::class);
});

describe('an answer the set cannot use', function (): void {
    it('saves nothing for the first two, so the message is asked about again', function (): void {
        InvoiceSet::register();
        Event::fake([MessageTyped::class]);
        $this->gateway->answers('made_up', 0.99);
        $id = $this->fake->deliver('info@example.com');

        $stillUp = $this->typist->type($this->mailbox, $id);
        $this->typist->type($this->mailbox, $id);

        expect($stillUp)->toBeTrue()
            ->and(Message::query()->count())->toBe(1)
            ->and(Typing::query()->count())->toBe(0)
            ->and($this->fake->labelsOf('info@example.com', $id))->toBe(['INBOX']);

        Event::assertNotDispatched(MessageTyped::class);
    });

    it('hands the message to a person on the third: not confident, unlabelled, the set\'s fallback type', function (): void {
        InvoiceSet::register();
        Event::fake([MessageTyped::class]);
        $this->gateway->answers('made_up', 0.99, 'dec-7');
        $id = $this->fake->deliver('info@example.com');

        foreach (range(1, 3) as $attempt) {
            $this->typist->type($this->mailbox, $id);
        }

        $typing = Typing::query()->sole();
        expect($typing->type)->toBe('other')
            ->and($typing->confident)->toBeFalse()
            ->and($typing->confidence)->toBeNull()
            ->and($typing->decided_by)->toBe(DecidedBy::Gateway)
            ->and($typing->decision_id)->toBe('dec-7')
            ->and($this->fake->labelsOf('info@example.com', $id))->toBe(['INBOX'])
            ->and($this->typist->untyped($this->mailbox, 10))->toBeEmpty();

        Event::assertDispatched(MessageTyped::class, fn (MessageTyped $e): bool => $e->type === 'other' && ! $e->confident && $e->typingId === $typing->id);
    });

    it('falls back to the last type when every type carries a label', function (): void {
        app(TypeSetRegistry::class)->register(new TypeSet('labelled', 1, 'Labelled', 'Which?', [
            new TypeDefinition('first', 'first', 'First'),
            new TypeDefinition('last', 'last', 'Last'),
        ], 0.8));
        $this->gateway->answers('made_up', 0.99);
        $id = $this->fake->deliver('info@example.com');

        foreach (range(1, 3) as $attempt) {
            $this->typist->type($this->mailbox, $id);
        }

        expect(Typing::query()->sole()->type)->toBe('last')
            ->and($this->fake->labelsOf('info@example.com', $id))->toBe(['INBOX']);
    });

    it('counts each set on its own', function (): void {
        InvoiceSet::register();
        InvoiceSet::register(key: 'legal', prefix: 'Legal');
        $id = $this->fake->deliver('info@example.com');
        $this->gateway->using = fn (TypeSet $set): GatewayAnswer => new GatewayAnswer($set->key === 'legal' ? 'made_up' : 'invoice', 0.9, null);
        $this->typist->type($this->mailbox, $id);
        $this->typist->type($this->mailbox, $id);

        $this->gateway->using = fn (): GatewayAnswer => new GatewayAnswer('made_up', 0.9, null);
        InvoiceSet::register(key: 'tax', prefix: 'Tax');
        $this->typist->type($this->mailbox, $id);

        expect(Typing::query()->pluck('type_set')->sort()->values()->all())->toBe(['invoices', 'legal']);
    });

    it('logs it without the answer, which can echo the mail', function (): void {
        InvoiceSet::register();
        Log::spy();
        $this->gateway->answers('the sender says: secret body text', 0.99);
        $id = $this->fake->deliver('info@example.com');

        $this->typist->type($this->mailbox, $id);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ! str_contains($message.json_encode($context), 'secret'))->once();
    });
});

it('still applies rules once the tick has stopped asking the gateway', function (): void {
    InvoiceSet::register(rules: [new PatternRule('invoice', from: 'starlink')]);
    InvoiceSet::register(key: 'legal', prefix: 'Legal');
    $id = $this->fake->deliver('info@example.com', from: 'billing@starlink.com');

    $stillUp = $this->typist->type($this->mailbox, $id, askGateway: false);

    expect($stillUp)->toBeFalse()
        ->and($this->gateway->asks)->toBe([])
        ->and(Typing::query()->pluck('type_set')->all())->toBe(['invoices']);
});

it('lets a message that is gone surface to the caller', function (): void {
    InvoiceSet::register();

    $this->typist->type($this->mailbox, 'missing');
})->throws(MessageNotFound::class);

it('finds untyped messages oldest first, per set, up to the limit', function (): void {
    InvoiceSet::register();
    $now = Carbon\CarbonImmutable::now();
    $this->gateway->down = true;
    $newest = $this->fake->deliver('info@example.com', receivedAt: $now);
    $oldest = $this->fake->deliver('info@example.com', receivedAt: $now->subHours(3));
    $middle = $this->fake->deliver('info@example.com', receivedAt: $now->subHours(2));
    foreach ([$newest, $oldest, $middle] as $id) {
        $this->typist->type($this->mailbox, $id);
    }
    $this->gateway->down = false;
    $this->typist->type($this->mailbox, $middle);

    expect($this->typist->untyped($this->mailbox, 1)->pluck('provider_message_id')->all())->toBe([$oldest])
        ->and($this->typist->untyped($this->mailbox, 10)->pluck('provider_message_id')->all())->toBe([$oldest, $newest]);

    InvoiceSet::register(key: 'legal', prefix: 'Legal');

    expect($this->typist->untyped($this->mailbox, 10)->pluck('provider_message_id')->all())->toBe([$oldest, $middle, $newest]);
});

it('does not count a superseded typing as missing', function (): void {
    InvoiceSet::register();
    $id = $this->fake->deliver('info@example.com');
    $this->typist->type($this->mailbox, $id);

    $this->mailroom->correct($this->fake->ref($this->mailbox, $id), 'invoices', 'bill_ready');

    expect($this->typist->untyped($this->mailbox, 10))->toBeEmpty();
});

it('keeps the first typing and fires nothing when another tick typed the message first', function (): void {
    InvoiceSet::register();
    Event::fake([MessageTyped::class]);
    $id = $this->fake->deliver('info@example.com');
    $this->gateway->using = function (): GatewayAnswer {
        // The overlapping tick saves its typing while this one waits on the gateway.
        Typing::query()->create([
            'message_id' => Message::query()->sole()->id, 'type_set' => 'invoices', 'type_set_version' => 3,
            'type' => 'invoice', 'decided_by' => DecidedBy::Gateway, 'confident' => true,
        ]);

        return new GatewayAnswer('bill_ready', 0.9, null);
    };

    $this->typist->type($this->mailbox, $id);

    expect(Typing::query()->sole()->type)->toBe('invoice');

    Event::assertNotDispatched(MessageTyped::class);
});

it('refuses a second current typing for one message and set', function (): void {
    InvoiceSet::register();
    $id = $this->fake->deliver('info@example.com');
    $this->typist->type($this->mailbox, $id);

    Typing::query()->create([
        'message_id' => Message::query()->sole()->id, 'type_set' => 'invoices', 'type_set_version' => 3,
        'type' => 'bill_ready', 'decided_by' => DecidedBy::Gateway, 'confident' => true,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('keeps a typing and carries on when a listener throws', function (): void {
    InvoiceSet::register();
    InvoiceSet::register(key: 'legal', prefix: 'Legal');
    Event::listen(MessageTyped::class, function (): never {
        throw new RuntimeException('listener failed');
    });
    Log::spy();
    $id = $this->fake->deliver('info@example.com');

    $this->typist->type($this->mailbox, $id);

    $typings = Typing::query()->pluck('id')->sort()->values()->all();
    expect($typings)->toHaveCount(2);

    foreach ($typings as $typing) {
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['typing_id'] === $typing)->once();
    }
});
