<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Contracts;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;

/**
 * Turns an HTML document into PDF bytes whose CreationDate is $createdAt.
 */
interface PdfRenderer
{
    /**
     * @throws RenderFailed
     */
    public function render(string $html, CarbonImmutable $createdAt, bool $allowRemoteImages = true): string;
}
