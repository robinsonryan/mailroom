<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Data;

/**
 * One attachment's metadata. Bytes are fetched on demand with
 * Mailroom::attachment(); they are never stored. $partId names the part within
 * its message and stays the same across reads, so it may be kept; it can be ''.
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
