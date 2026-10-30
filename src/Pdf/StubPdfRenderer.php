<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Pdf;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Contracts\PdfRenderer;

/**
 * Text stand-in for {@see BrowsershotRenderer}, so a consumer's tests can set
 * `mailroom.pdf.driver` to `stub` and never need Chrome. Not a real PDF: the
 * document's text and CreationDate in the clear, to assert on.
 */
final class StubPdfRenderer implements PdfRenderer
{
    public function render(string $html, CarbonImmutable $createdAt, bool $allowRemoteImages = true): string
    {
        $visible = (string) preg_replace('#<(script|style)\b.*?</\1\s*>#is', '', $html);
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $visible)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return "%PDF-1.4 stub\n/CreationDate (".PdfCreationDate::format($createdAt).")\n{$text}\n%%EOF";
    }
}
