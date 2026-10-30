<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Contracts;

use RobinsonRyan\Mailroom\Data\GatewayAnswer;
use RobinsonRyan\Mailroom\Exceptions\GatewayUnavailable;
use RobinsonRyan\Mailroom\TypeSets\TypeSet;

/**
 * The typed-decision service (the harness's decide gateway).
 */
interface DecisionGateway
{
    /**
     * Ask $typeSet's question about $state; $refId identifies the message
     * (`mail:<mailbox address>:<provider message id>`).
     *
     * @throws GatewayUnavailable
     */
    public function ask(TypeSet $typeSet, string $state, string $refId): GatewayAnswer;

    /**
     * Record a person's answer as ground truth for that ref and type set.
     *
     * @throws GatewayUnavailable
     */
    public function recordLabel(TypeSet $typeSet, string $refId, string $type): void;
}
