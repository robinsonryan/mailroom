<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Contracts\BodyPdf;
use RobinsonRyan\Mailroom\Contracts\PdfRenderer;
use RobinsonRyan\Mailroom\Mailroom;
use RobinsonRyan\Mailroom\Pdf\BrowsershotRenderer;
use RobinsonRyan\Mailroom\Pdf\MessageBodyPdf;
use RobinsonRyan\Mailroom\Pdf\StubPdfRenderer;
use RobinsonRyan\Mailroom\Testing\FakeProvider;

it('binds the composer over the core placeholder', function (): void {
    expect(app(BodyPdf::class))->toBeInstanceOf(MessageBodyPdf::class);
});

it('renders through Browsershot by default', function (): void {
    expect(app(PdfRenderer::class))->toBeInstanceOf(BrowsershotRenderer::class);
});

it('reads the driver when resolving, so a consumer\'s test config can pick the stub', function (): void {
    config()->set('mailroom.pdf.driver', 'stub');

    expect(app(PdfRenderer::class))->toBeInstanceOf(StubPdfRenderer::class);
});

it('gives consumers a working asPdf on the stub driver', function (): void {
    config()->set('mailroom.pdf.driver', 'stub');
    $fake = FakeProvider::install();
    $mailroom = app(Mailroom::class);
    $mailbox = $mailroom->connect('info@example.com', 'afwd-info');
    $id = $fake->deliver(
        'info@example.com',
        subject: 'Invoice & receipt',
        text: 'Pay by Friday',
        receivedAt: CarbonImmutable::parse('2026-03-04 05:06:07', 'UTC'),
    );

    $pdf = $mailroom->asPdf($fake->ref($mailbox, $id));

    expect($pdf)->toStartWith('%PDF-1.4')
        ->toContain('Invoice & receipt')
        ->toContain('Pay by Friday')
        ->toContain("/CreationDate (D:20260304050607+00'00')");
});
