<?php

declare(strict_types=1);

use RobinsonRyan\Mailroom\Enums\LabelAction;
use RobinsonRyan\Mailroom\Exceptions\LabelOutsidePrefix;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\LabelWrite;
use RobinsonRyan\Mailroom\Models\Mailbox;
use RobinsonRyan\Mailroom\Testing\FakeProvider;
use RobinsonRyan\Mailroom\TypeSets\TypeDefinition;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;
use RobinsonRyan\Mailroom\TypeSets\TypeSetRegistry;

beforeEach(function (): void {
    $this->fake = FakeProvider::install();
    app(TypeSetRegistry::class)->register(new TypeSet(
        key: 'invoices',
        version: 1,
        labelPrefix: 'Invoices',
        question: 'What is this email about money?',
        types: [new TypeDefinition('invoice', 'an invoice', 'Invoice'), new TypeDefinition('other', 'anything else', null)],
        threshold: 0.8,
    ));
    $this->mailroom = app(Mailroom::class);
    $this->mailbox = $this->mailroom->connect('Info@Example.com', 'afwd-info');
});

it('connects a mailbox with a database-generated-style uuid v7 key and a lowercased address', function (): void {
    expect($this->mailbox->address)->toBe('info@example.com')
        ->and($this->mailbox->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/')
        ->and(Mailbox::query()->count())->toBe(1);
});

it('labels inside a registered prefix and records the write so its echo is recognisable', function (): void {
    $id = $this->fake->deliver('info@example.com', subject: 'Invoice 7');
    $ref = $this->fake->ref($this->mailbox, $id);

    $this->mailroom->label($ref, 'Invoices/Filed');

    expect($this->fake->labelsOf('info@example.com', $id))->toContain('Invoices/Filed')
        ->and(LabelWrite::query()->where('label', 'Invoices/Filed')->where('action', LabelAction::Add)->count())->toBe(1);
});

it('refuses to write a label outside every registered prefix', function (): void {
    $id = $this->fake->deliver('info@example.com');
    $ref = $this->fake->ref($this->mailbox, $id);

    $this->mailroom->label($ref, 'Personal/Stuff');
})->throws(LabelOutsidePrefix::class);

it('archives by removing the message from the inbox only', function (): void {
    $id = $this->fake->deliver('info@example.com', labels: ['Invoices/Invoice']);
    $this->mailroom->archive($this->fake->ref($this->mailbox, $id));

    expect($this->fake->isArchived('info@example.com', $id))->toBeTrue()
        ->and($this->fake->labelsOf('info@example.com', $id))->toContain('Invoices/Invoice');
});

it('returns attachment bytes and full content through the provider', function (): void {
    $id = $this->fake->deliver('info@example.com', attachments: [['name' => 'inv.pdf', 'mime' => 'application/pdf', 'bytes' => '%PDF-1.4 x']]);
    $ref = $this->fake->ref($this->mailbox, $id);

    $content = $this->mailroom->message($ref);

    expect($content->attachments)->toHaveCount(1)
        ->and($content->attachments[0]->filename)->toBe('inv.pdf')
        ->and($this->mailroom->attachment($ref, $content->attachments[0]->partId))->toBe('%PDF-1.4 x');
});
