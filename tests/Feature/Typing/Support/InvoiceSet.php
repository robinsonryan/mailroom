<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Tests\Feature\Typing\Support;

use RobinsonRyan\Mailroom\TypeSets\Rule;
use RobinsonRyan\Mailroom\TypeSets\TypeDefinition;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;
use RobinsonRyan\Mailroom\TypeSets\TypeSetRegistry;

final class InvoiceSet
{
    /**
     * @param  list<Rule>  $rules
     * @param  list<string>  $mailboxes
     */
    public static function register(array $rules = [], array $mailboxes = [], string $key = 'invoices', string $prefix = 'Invoices'): TypeSet
    {
        $set = new TypeSet(
            key: $key,
            version: 3,
            labelPrefix: $prefix,
            question: 'What is this email about money?',
            types: [
                new TypeDefinition('invoice', 'an invoice', 'Invoice'),
                new TypeDefinition('bill_ready', 'a bill is ready to fetch', 'Bill ready'),
                new TypeDefinition('other', 'anything else', null),
            ],
            threshold: 0.8,
            rules: $rules,
            mailboxes: $mailboxes,
        );
        app(TypeSetRegistry::class)->register($set);

        return $set;
    }
}
