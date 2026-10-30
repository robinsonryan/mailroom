<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Data;

/**
 * One attachment's metadata. Bytes are fetched on demand with
 * Mailroom::attachment(); they are never stored.
 */
final readonly class Attachment
{
    public function __construct(
        public string $partId,
        public string $filename,
        public string $mime,
        public int $size,
    ) {}
}
