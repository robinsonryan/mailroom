<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Tests\Feature\Pdf;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Contracts\PdfRenderer;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;

/**
 * Records what the composer asked for, failing the first $failures calls.
 */
final class RecordingRenderer implements PdfRenderer
{
    /** @var list<array{html: string, createdAt: CarbonImmutable, allowRemoteImages: bool}> */
    public array $calls = [];

    public function __construct(private readonly int $failures = 0) {}

    public function render(string $html, CarbonImmutable $createdAt, bool $allowRemoteImages = true): string
    {
        $this->calls[] = ['html' => $html, 'createdAt' => $createdAt, 'allowRemoteImages' => $allowRemoteImages];

        if (count($this->calls) <= $this->failures) {
            throw new RenderFailed('Chrome fell over.');
        }

        return '%PDF-recorded';
    }
}
