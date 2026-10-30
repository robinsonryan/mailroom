<?php

declare(strict_types=1);

use RobinsonRyan\Mailroom\Data\GatewayAnswer;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Models\Message;
use RobinsonRyan\Mailroom\Models\Typing;
use RobinsonRyan\Mailroom\Testing\FakeProvider;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\FakeGateway;
use RobinsonRyan\Mailroom\Tests\Feature\Typing\Support\InvoiceSet;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;

beforeEach(function (): void {
    $this->fake = FakeProvider::install();
    $this->gateway = FakeGateway::install();
    $this->set = InvoiceSet::register();
    app(Mailroom::class)->connect('info@example.com', 'afwd-info');
    $this->file = tempnam(sys_get_temp_dir(), 'replay');
});

afterEach(fn (): bool => @unlink($this->file));

function corpus(string $file, array $rows): void
{
    file_put_contents($file, implode("\n", array_map(json_encode(...), $rows))."\n");
}

it('reports agreement on invoice-or-not, skips absent mail and changes nothing', function (): void {
    $invoice = $this->fake->deliver('info@example.com', subject: 'Invoice');
    $notice = $this->fake->deliver('info@example.com', subject: 'Notice');
    $this->gateway->using = fn (TypeSet $set, string $state): GatewayAnswer => new GatewayAnswer(str_contains($state, 'Subject: Invoice') ? 'invoice' : 'other', 0.9, 'd');
    corpus($this->file, [
        ['id' => $invoice, 'expected' => 'invoice'],
        ['id' => $notice, 'expected' => 'other'],
        ['id' => 'elsewhere', 'expected' => 'other'],
    ]);

    $this->artisan('mailroom:replay', ['type_set' => $this->set->key, 'mailbox' => 'info@example.com', 'file' => $this->file])
        ->expectsOutputToContain('2 of 2 agree (100%); 0 below the threshold would go to a person; 1 not in this mailbox.')
        ->assertSuccessful();

    expect(Typing::query()->count())->toBe(0)
        ->and(Message::query()->count())->toBe(0)
        ->and($this->fake->labelsOf('info@example.com', $invoice))->toBe(['INBOX']);
});

it('fails below the minimum and lists each disagreement', function (): void {
    $id = $this->fake->deliver('info@example.com', subject: 'Invoice');
    $this->gateway->answer = new GatewayAnswer('other', 0.9, 'd');
    corpus($this->file, [['id' => $id, 'expected' => 'invoice']]);

    $this->artisan('mailroom:replay', ['type_set' => $this->set->key, 'mailbox' => 'info@example.com', 'file' => $this->file])
        ->expectsOutputToContain('DISAGREE '.$id)
        ->assertFailed();
});
