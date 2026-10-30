<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\TypeSets;

use RobinsonRyan\Mailroom\Data\MessageSummary;

/**
 * A deterministic typing that runs before the gateway is asked. Returns the
 * type key, or null when it does not apply.
 */
interface Rule
{
    public function match(MessageSummary $message): ?string;
}
