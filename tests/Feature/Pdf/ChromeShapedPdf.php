<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Tests\Feature\Pdf;

/**
 * The shape Chrome (Skia/PDF) writes: a classic xref table, and the Info object
 * in the clear, with parens in strings escaped.
 */
final class ChromeShapedPdf
{
    public static function bytes(string $title = '(Invoice 7)', string $date = "D:20261030154503+00'00'"): string
    {
        $body = "%PDF-1.4\n%\xD3\xEB\xE9\xE1\n";
        $info = strlen($body);
        $body .= "1 0 obj\n<</Title {$title}\n/Creator (Chromium)\n/Producer (Skia/PDF m156)\n"
            ."/CreationDate ({$date})\n/ModDate ({$date})>>\nendobj\n";
        $root = strlen($body);
        $body .= "2 0 obj\n<</Type /Catalog>>\nendobj\n";
        $xref = strlen($body);

        return $body.sprintf(
            "xref\n0 3\n0000000000 65535 f \n%010d 00000 n \n%010d 00000 n \ntrailer\n<</Size 3\n/Root 2 0 R\n/Info 1 0 R>>\nstartxref\n%d\n%%%%EOF",
            $info,
            $root,
            $xref,
        );
    }
}
