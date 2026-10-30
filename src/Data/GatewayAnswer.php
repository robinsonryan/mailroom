<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Data;

/**
 * The decision gateway's answer to a type set's question.
 */
final readonly class GatewayAnswer
{
    public function __construct(
        public string $type,
        public ?float $confidence,
        public ?string $decisionId,
    ) {}
}
