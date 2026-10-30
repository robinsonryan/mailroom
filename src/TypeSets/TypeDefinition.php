<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\TypeSets;

/**
 * One answer in a type set. $label is the part after the prefix in the label
 * Mailroom writes (`Invoices/Bill ready`); null means the type is recorded but
 * never labelled (e.g. `other`).
 */
final readonly class TypeDefinition
{
    public function __construct(
        public string $key,
        public string $description,
        public ?string $label,
    ) {}
}
