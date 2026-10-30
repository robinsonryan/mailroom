<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Tests\Feature\Typing\Support;

use Closure;
use RobinsonRyan\Mailroom\Contracts\DecisionGateway;
use RobinsonRyan\Mailroom\Data\GatewayAnswer;
use RobinsonRyan\Mailroom\Exceptions\GatewayUnavailable;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;

/**
 * A decision gateway the test scripts: one answer for everything, or a
 * closure, or down. Records what it was asked.
 */
final class FakeGateway implements DecisionGateway
{
    /** @var list<array{type_set: string, state: string, ref: string}> */
    public array $asks = [];

    /** @var list<array{type_set: string, ref: string, type: string}> */
    public array $labels = [];

    public bool $down = false;

    public bool $labelsDown = false;

    /** @var (Closure(TypeSet, string, string): GatewayAnswer)|null */
    public ?Closure $using = null;

    public function __construct(public GatewayAnswer $answer = new GatewayAnswer('invoice', 0.95, 'dec-1')) {}

    public static function install(): self
    {
        $fake = new self;
        app()->instance(DecisionGateway::class, $fake);

        return $fake;
    }

    public function answers(string $type, ?float $confidence = 0.95, ?string $decisionId = 'dec-1'): self
    {
        $this->answer = new GatewayAnswer($type, $confidence, $decisionId);

        return $this;
    }

    public function ask(TypeSet $typeSet, string $state, string $refId): GatewayAnswer
    {
        $this->asks[] = ['type_set' => $typeSet->key, 'state' => $state, 'ref' => $refId];

        if ($this->down) {
            throw new GatewayUnavailable('Gateway is down.');
        }

        return $this->using instanceof Closure ? ($this->using)($typeSet, $state, $refId) : $this->answer;
    }

    public function recordLabel(TypeSet $typeSet, string $refId, string $type): void
    {
        if ($this->labelsDown) {
            throw new GatewayUnavailable('Gateway is down.');
        }

        $this->labels[] = ['type_set' => $typeSet->key, 'ref' => $refId, 'type' => $type];
    }
}
