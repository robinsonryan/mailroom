<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Pdf;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;

/**
 * Chrome stamps PDFs with the render time; asPdf promises the message date.
 * The Info dictionary is plain text and its xref offsets are absolute, so the
 * value is overwritten in place at exactly its own length: every byte offset
 * stays valid and no PDF library is needed.
 */
final class PdfCreationDate
{
    /** The PDF date format: D:YYYYMMDDHHmmSS+HH'mm' */
    public static function format(CarbonImmutable $date): string
    {
        return 'D:'.$date->format('YmdHis').str_replace(':', "'", $date->format('P'))."'";
    }

    /**
     * @throws RenderFailed when the file has no Info dictionary we can rewrite in place
     */
    public static function stamp(string $pdf, CarbonImmutable $date): string
    {
        [$start, $end] = self::infoObject($pdf);

        // The last match: Info lists the dates after Title, which a subject
        // containing this very text could otherwise spoof.
        $matched = preg_match_all(
            '~/CreationDate\s*\((D:[^()\\\\]*)\)~',
            substr($pdf, $start, $end - $start),
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        if ($matched === 0 || $matched === false) {
            throw new RenderFailed('The rendered PDF has no CreationDate to rewrite.');
        }

        [$current, $offset] = $matches[1][$matched - 1];
        $stamp = self::format($date);

        if (strlen($current) !== strlen($stamp)) {
            throw new RenderFailed('The rendered PDF\'s CreationDate cannot be rewritten at the same length.');
        }

        return substr_replace($pdf, $stamp, $start + $offset, strlen($stamp));
    }

    /**
     * Byte range of the object the last trailer names as /Info.
     *
     * @return array{int, int}
     *
     * @throws RenderFailed
     */
    private static function infoObject(string $pdf): array
    {
        $trailer = strrpos($pdf, 'trailer');

        if ($trailer === false || preg_match('~/Info\s+(\d+)\s+(\d+)\s+R~', substr($pdf, $trailer), $info) !== 1) {
            throw new RenderFailed('The rendered PDF has no Info dictionary.');
        }

        $start = strrpos(substr($pdf, 0, $trailer), "\n{$info[1]} {$info[2]} obj");
        $end = $start === false ? false : strpos($pdf, 'endobj', $start);

        if ($start === false || $end === false) {
            throw new RenderFailed('The rendered PDF\'s Info object could not be found.');
        }

        return [$start, $end];
    }
}
