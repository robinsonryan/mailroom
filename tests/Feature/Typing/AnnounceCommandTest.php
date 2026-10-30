<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RobinsonRyan\Mailroom\Enums\DecidedBy;
use RobinsonRyan\Mailroom\Events\MessageTyped;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\Typing;
use RobinsonRyan\Mailroom\Testing\FakeProvider;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\FakeGateway;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\InvoiceSet;
use RobinsonRyan\Mailroom\Typing\Typist;

beforeEach(function (): void {
    $this->fake = FakeProvider::install();
    FakeGateway::install();
    InvoiceSet::register();
    $this->mailroom = app(Mailroom::class);
    $this->mailbox = $this->mailroom->connect('info@example.com', 'afwd-info');
    $this->id = $this->fake->deliver('info@example.com');
    app(Typist::class)->type($this->mailbox, $this->id);
    $this->typing = Typing::query()->sole();
});

it('fires MessageTyped again with what the typing recorded', function (): void {
    Event::fake([MessageTyped::class]);

    $this->artisan('mailroom:announce', ['typing' => $this->typing->id])->assertSuccessful();

    Event::assertDispatchedTimes(MessageTyped::class, 1);
    Event::assertDispatched(MessageTyped::class, fn (MessageTyped $e): bool => $e->typingId === $this->typing->id
        && $e->ref->providerMessageId === $this->id && $e->typeSet === 'invoices' && $e->type === 'invoice'
        && $e->confidence === 0.95 && $e->confident && $e->decidedBy === DecidedBy::Gateway);
});

it('fails when a listener throws again', function (): void {
    Event::listen(MessageTyped::class, function (): never {
        throw new RuntimeException('listener failed');
    });
    Log::spy();

    $this->artisan('mailroom:announce', ['typing' => $this->typing->id])->assertFailed();

    Log::shouldHaveReceived('error')->once();
});

it('will not fire a typing a person has since replaced', function (): void {
    $this->mailroom->correct($this->fake->ref($this->mailbox, $this->id), 'invoices', 'bill_ready');
    Event::fake([MessageTyped::class]);

    $this->artisan('mailroom:announce', ['typing' => $this->typing->id])->assertFailed();

    Event::assertNotDispatched(MessageTyped::class);
});

it('fails on a typing it cannot find', function (): void {
    $this->artisan('mailroom:announce', ['typing' => '019a0000-0000-7000-8000-000000000000'])->assertFailed();
});
