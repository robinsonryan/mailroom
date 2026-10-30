<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Data;

use RobinsonRyan\Mailroom\Enums\ChangeKind;

/**
 * One event from a provider's change history. For label events, $labels holds
 * the label names that were added or removed (not ids).
 */
final readonly class HistoryChange
{
    /**
     * @param  list<string>  $labels
     */
    public function __construct(
        public ChangeKind $kind,
        public string $providerMessageId,
        public string $threadId,
        public array $labels = [],
    ) {}
}
