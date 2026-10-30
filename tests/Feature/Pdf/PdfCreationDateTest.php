<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;
use RobinsonRyan\Mailroom\Pdf\PdfCreationDate;
use RobinsonRyan\Mailroom\Tests\Feature\Pdf\ChromeShapedPdf;

it('formats a date the way PDF writes them', function (string $date, string $formatted): void {
    expect(PdfCreationDate::format(CarbonImmutable::parse($date)))->toBe($formatted);
})->with([
    'utc' => ['2026-03-04T05:06:07+00:00', "D:20260304050607+00'00'"],
    'behind utc' => ['2026-03-04T05:06:07-05:00', "D:20260304050607-05'00'"],
    'half-hour zone' => ['2026-03-04T05:06:07+05:30', "D:20260304050607+05'30'"],
]);

it('rewrites CreationDate and nothing else', function (): void {
    $pdf = ChromeShapedPdf::bytes();

    $stamped = PdfCreationDate::stamp($pdf, CarbonImmutable::parse('2026-03-04T05:06:07+00:00'));

    expect($stamped)->toContain("/CreationDate (D:20260304050607+00'00')")
        ->toContain("/ModDate (D:20261030154503+00'00')")
        ->and(str_replace("CreationDate (D:20260304050607+00'00')", "CreationDate (D:20261030154503+00'00')", $stamped))->toBe($pdf);
});

it('keeps every byte offset valid, so the xref table still points at each object', function (): void {
    $pdf = ChromeShapedPdf::bytes();
    $stamped = PdfCreationDate::stamp($pdf, CarbonImmutable::parse('2026-03-04T05:06:07+05:30'));

    preg_match_all('/^(\d{10}) 00000 n /m', $stamped, $offsets);

    expect(strlen($stamped))->toBe(strlen($pdf))
        ->and(substr($stamped, (int) $offsets[1][0], 7))->toBe('1 0 obj')
        ->and(substr($stamped, (int) $offsets[1][1], 7))->toBe('2 0 obj');
});

it('is not fooled by a subject that spells out a CreationDate', function (): void {
    $pdf = ChromeShapedPdf::bytes(title: "(/CreationDate \\(D:20000101000000+00'00'\\))");

    $stamped = PdfCreationDate::stamp($pdf, CarbonImmutable::parse('2026-03-04T05:06:07+00:00'));

    expect($stamped)->toContain("/Title (/CreationDate \\(D:20000101000000+00'00'\\))")
        ->toContain("\n/CreationDate (D:20260304050607+00'00')");
});

it('leaves a CreationDate-shaped string outside the Info object alone', function (): void {
    $pdf = ChromeShapedPdf::bytes();
    $decoy = "/CreationDate (D:20000101000000+00'00')";
    $withDecoy = str_replace('<</Type /Catalog>>', "<</Type /Catalog {$decoy}>>", $pdf);

    expect(PdfCreationDate::stamp($withDecoy, CarbonImmutable::parse('2026-03-04T05:06:07+00:00')))->toContain($decoy);
});

it('refuses, rather than ship the wrong date, when it cannot rewrite in place', function (string $pdf, string $why): void {
    expect(fn (): string => PdfCreationDate::stamp($pdf, CarbonImmutable::now()))->toThrow(RenderFailed::class, $why);
})->with([
    'not a pdf' => ['', 'no Info dictionary'],
    'no trailer Info' => [str_replace('/Info 1 0 R', '', ChromeShapedPdf::bytes()), 'no Info dictionary'],
    'Info object missing' => [str_replace('1 0 obj', '9 0 obj', ChromeShapedPdf::bytes()), 'could not be found'],
    'no CreationDate' => [str_replace('/CreationDate', '/CreatedOn', ChromeShapedPdf::bytes()), 'no CreationDate'],
    'a date of another length' => [ChromeShapedPdf::bytes(date: 'D:20261030154503Z'), 'same length'],
]);
