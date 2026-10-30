<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Pdf;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RobinsonRyan\Mailroom\Contracts\BodyPdf;
use RobinsonRyan\Mailroom\Contracts\PdfRenderer;

/**
 * Binds asPdf. Registered by MailroomServiceProvider when this class exists.
 */
final class PdfServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BodyPdf::class, MessageBodyPdf::class);

        // Read when resolving, not here, so a consumer's test can set the driver.
        $this->app->bind(PdfRenderer::class, fn (Application $app): PdfRenderer => config('mailroom.pdf.driver', 'browsershot') === 'stub'
            ? $app->make(StubPdfRenderer::class)
            : $app->make(BrowsershotRenderer::class));
    }
}
