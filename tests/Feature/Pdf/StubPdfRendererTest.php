<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Pdf\StubPdfRenderer;

it('returns a %PDF-1.4 string holding the document\'s text', function (): void {
    $pdf = (new StubPdfRenderer)->render(
        "<html><body><h1>Invoice 7</h1>\n\n<p>Total  due</p></body></html>",
        CarbonImmutable::parse('2026-03-04 05:06:07', 'UTC'),
    );

    expect($pdf)->toStartWith('%PDF-1.4')
        ->toContain('Invoice 7 Total due')
        ->toEndWith('%%EOF')
        ->not->toContain('<h1>');
});

it('decodes entities, so consumers can assert on the text as written', function (): void {
    $pdf = (new StubPdfRenderer)->render('<p>Fish &amp; chips &lt;3</p>', CarbonImmutable::now());

    expect($pdf)->toContain('Fish & chips <3');
});

it('leaves out style and script text, which is not the document\'s text', function (): void {
    $pdf = (new StubPdfRenderer)->render('<style>.x{color:red}</style><script>alert(1)</script><p>Kept</p>', CarbonImmutable::now());

    expect($pdf)->toContain('Kept')
        ->not->toContain('color:red')
        ->not->toContain('alert');
});

it('carries the CreationDate the contract promises', function (): void {
    $pdf = (new StubPdfRenderer)->render('<p>Hi</p>', CarbonImmutable::parse('2026-03-04 05:06:07', '-05:00'));

    expect($pdf)->toContain("/CreationDate (D:20260304050607-05'00')");
});

it('ignores whether remote images are allowed', function (): void {
    $date = CarbonImmutable::parse('2026-03-04 05:06:07', 'UTC');

    expect((new StubPdfRenderer)->render('<p>Hi</p>', $date, allowRemoteImages: false))
        ->toBe((new StubPdfRenderer)->render('<p>Hi</p>', $date));
});
