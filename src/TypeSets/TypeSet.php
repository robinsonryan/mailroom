<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\TypeSets;

use InvalidArgumentException;

/**
 * A consuming app's question about mail, with its answers (Contract A).
 * Registered by the consumer; Mailroom asks it of every new message in the
 * mailboxes it names (all mailboxes when $mailboxes is empty).
 */
final readonly class TypeSet
{
    /**
     * @param  list<TypeDefinition>  $types
     * @param  list<Rule>  $rules
     * @param  list<string>  $mailboxes  addresses this set types; empty = every mailbox
     */
    public function __construct(
        public string $key,
        public int $version,
        public string $labelPrefix,
        public string $question,
        public array $types,
        public float $threshold,
        public array $rules = [],
        public array $mailboxes = [],
    ) {
        if (count($types) < 2) {
            throw new InvalidArgumentException("Type set {$key} needs at least two types.");
        }

        if ($threshold < 0.0 || $threshold > 1.0) {
            throw new InvalidArgumentException("Type set {$key} threshold must be between 0 and 1.");
        }

        if ($labelPrefix === '' || str_contains($labelPrefix, '/')) {
            throw new InvalidArgumentException("Type set {$key} label prefix must be one non-empty segment.");
        }
    }

    public function type(string $key): ?TypeDefinition
    {
        foreach ($this->types as $type) {
            if ($type->key === $key) {
                return $type;
            }
        }

        return null;
    }

    /** The full label name for a type, or null when the type is never labelled. */
    public function labelFor(string $typeKey): ?string
    {
        $label = $this->type($typeKey)?->label;

        return $label === null ? null : $this->labelPrefix.'/'.$label;
    }

    /** The type whose label is $labelName, if any. */
    public function typeForLabel(string $labelName): ?TypeDefinition
    {
        foreach ($this->types as $type) {
            if ($type->label !== null && $this->labelPrefix.'/'.$type->label === $labelName) {
                return $type;
            }
        }

        return null;
    }

    public function owns(string $labelName): bool
    {
        return str_starts_with($labelName, $this->labelPrefix.'/');
    }

    public function appliesTo(string $mailboxAddress): bool
    {
        return $this->mailboxes === [] || in_array(strtolower($mailboxAddress), array_map(strtolower(...), $this->mailboxes), true);
    }
}
