<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\TypeSets;

use InvalidArgumentException;
use RobinsonRyan\Mailroom\Exceptions\UnknownTypeSet;

/**
 * Every type set registered by the host's consumers (a singleton). Consumers
 * register in a service provider's boot().
 */
final class TypeSetRegistry
{
    /** @var array<string, TypeSet> */
    private array $sets = [];

    public function register(TypeSet $typeSet): void
    {
        foreach ($this->sets as $other) {
            if ($other->key !== $typeSet->key && $other->labelPrefix === $typeSet->labelPrefix) {
                throw new InvalidArgumentException("Label prefix {$typeSet->labelPrefix} is already owned by type set {$other->key}.");
            }
        }

        $this->sets[$typeSet->key] = $typeSet;
    }

    /** @return list<TypeSet> */
    public function all(): array
    {
        return array_values($this->sets);
    }

    /** @return list<TypeSet> */
    public function forMailbox(string $address): array
    {
        return array_values(array_filter($this->sets, fn (TypeSet $set): bool => $set->appliesTo($address)));
    }

    public function get(string $key): TypeSet
    {
        return $this->sets[$key] ?? throw new UnknownTypeSet("No type set {$key}.");
    }

    /** The type set whose prefix owns $labelName, if any. */
    public function owning(string $labelName): ?TypeSet
    {
        foreach ($this->sets as $set) {
            if ($set->owns($labelName)) {
                return $set;
            }
        }

        return null;
    }
}
