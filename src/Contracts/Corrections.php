<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Contracts;

use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Models\Typing;

/**
 * Records a person's typing of a message, supersedes the earlier one, moves
 * the type label, fires MessageTyped and reports it to the gateway.
 */
interface Corrections
{
    /**
     * @param  bool  $fromLabel  true when the person already changed the label in the mailbox (do not write labels again)
     */
    public function correct(MessageRef $ref, string $typeSet, string $type, bool $fromLabel = false): Typing;
}
