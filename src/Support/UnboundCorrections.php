<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Support;

use LogicException;
use RobinsonRyan\Mailroom\Contracts\Corrections;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Models\Typing;

/** Placeholder until the typing module binds the real one. */
final class UnboundCorrections implements Corrections
{
    public function correct(MessageRef $ref, string $typeSet, string $type, bool $fromLabel = false): Typing
    {
        throw new LogicException('No Corrections implementation is bound.');
    }
}
