<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Data\MessageSummary;
use RobinsonRyan\Mailroom\TypeSets\PatternRule;
use RobinsonRyan\Mailroom\TypeSets\TypeDefinition;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;
use RobinsonRyan\Mailroom\TypeSets\TypeSetRegistry;

function invoiceSet(string $key = 'invoices', string $prefix = 'Invoices'): TypeSet
{
    return new TypeSet($key, 1, $prefix, 'Q?', [
        new TypeDefinition('invoice', 'an invoice', 'Invoice'),
        new TypeDefinition('bill_ready', 'bill ready', 'Bill ready'),
        new TypeDefinition('other', 'other', null),
    ], 0.8);
}

it('maps types to labels and labels back to types', function (): void {
    $set = invoiceSet();

    expect($set->labelFor('bill_ready'))->toBe('Invoices/Bill ready')
        ->and($set->labelFor('other'))->toBeNull()
        ->and($set->typeForLabel('Invoices/Invoice')?->key)->toBe('invoice')
        ->and($set->owns('Invoices/Filed'))->toBeTrue()
        ->and($set->owns('Invoicesx/Filed'))->toBeFalse();
});

it('rejects a nested or empty prefix, a bad threshold and fewer than two types', function (array $args): void {
    new TypeSet(...$args);
})->with([
    'nested prefix' => [['k', 1, 'A/B', 'Q', [new TypeDefinition('a', 'a', 'A'), new TypeDefinition('b', 'b', 'B')], 0.5]],
    'threshold' => [['k', 1, 'A', 'Q', [new TypeDefinition('a', 'a', 'A'), new TypeDefinition('b', 'b', 'B')], 1.5]],
    'one type' => [['k', 1, 'A', 'Q', [new TypeDefinition('a', 'a', 'A')], 0.5]],
])->throws(InvalidArgumentException::class);

it('refuses two type sets sharing one prefix', function (): void {
    $registry = new TypeSetRegistry;
    $registry->register(invoiceSet('a'));
    $registry->register(invoiceSet('b'));
})->throws(InvalidArgumentException::class);

it('matches a pattern rule only when every given pattern matches', function (): void {
    $rule = new PatternRule('invoice', from: 'starlink\.com', subject: 'Automatic Payment Reminder', attachment: '^INV-.*\.pdf$');
    $summary = fn (array $names): MessageSummary => new MessageSummary('m', 't', CarbonImmutable::now(), 'Starlink <billing@starlink.com>', 'Automatic Payment Reminder', $names, '', []);

    expect($rule->match($summary(['INV-123.pdf'])))->toBe('invoice')
        ->and($rule->match($summary(['receipt.pdf'])))->toBeNull();
});
