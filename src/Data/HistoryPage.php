<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Data;

/**
 * All changes after a cursor, oldest first, and the cursor to store once they
 * have been handled.
 */
final readonly class HistoryPage
{
    /**
     * @param  list<HistoryChange>  $changes
     */
    public function __construct(
        public array $changes,
        public string $cursor,
    ) {}
}
