<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Pdf;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use RobinsonRyan\Mailroom\Contracts\PdfRenderer;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * Real HTML to PDF through puppeteer's pinned Chrome-for-Testing. The document
 * is untrusted mail, so JavaScript is off and the page is served from an
 * unresolvable http origin rather than Browsershot's temporary file: on a
 * file:// page a mail's `<img src="/etc/...">` resolves to the local disk. That
 * origin also makes Chrome refuse private addresses, keeping mail off the LAN.
 * MailBrowsershot makes the timeout stop everything the render started.
 */
final readonly class BrowsershotRenderer implements PdfRenderer
{
    private const PAGE_URL = 'http://mailroom.invalid/';

    /** Relative URLs land on the page's host: fail those at once, not at the DNS timeout. */
    private const PAGE_HOST_ONLY = 'MAP mailroom.invalid ~NOTFOUND';

    /** Fails every lookup; the page itself is answered without touching the network. */
    private const NO_NETWORK = 'MAP * ~NOTFOUND';

    public function __construct(private Repository $config) {}

    public function render(string $html, CarbonImmutable $createdAt, bool $allowRemoteImages = true): string
    {
        try {
            return PdfCreationDate::stamp($this->browsershot($html, $allowRemoteImages)->pdf(), $createdAt);
        } catch (RenderFailed $failed) {
            throw $failed;
        } catch (Throwable $failure) {
            throw new RenderFailed('Chrome could not render the PDF.', $failure->getCode(), previous: $failure);
        }
    }

    private function browsershot(string $html, bool $allowRemoteImages): Browsershot
    {
        $seconds = max(1, (int) $this->config->get('mailroom.pdf.timeout', 60));

        // The timeout is the whole budget, and past it everything is killed.
        // Start-up, navigation, printing and closing the browser get a quarter
        // each, so a hung image or a slow print fails inside node, which says
        // why, and leaves the kill for what hangs node itself. The protocol
        // limit is both the print's and the close's.
        $share = intdiv($seconds * 1000, 4);

        $browsershot = MailBrowsershot::html($html)
            ->setContentUrl(self::PAGE_URL)
            ->disableJavascript()
            ->format('Letter')
            ->showBackground()
            ->margins(12.7, 12.7, 12.7, 12.7)
            ->usePipe()
            ->timeout($seconds)
            ->setOption('timeout', $share)
            ->setOption('protocolTimeout', $share);

        $this->applyPaths($browsershot);

        if ((bool) $this->config->get('mailroom.pdf.no_sandbox', true)) {
            $browsershot->noSandbox();
        }

        /** @var array<int, string> $arguments */
        $arguments = $this->config->get('mailroom.pdf.chromium_arguments', ['disable-gpu']);

        return $browsershot
            ->addChromiumArguments($arguments)
            ->addChromiumArguments(['host-resolver-rules' => $allowRemoteImages ? self::PAGE_HOST_ONLY : self::NO_NETWORK]);
    }

    private function applyPaths(Browsershot $browsershot): void
    {
        $chromePath = $this->chromePath();
        if ($chromePath !== null) {
            $browsershot->setChromePath($chromePath);
        }

        $nodeBinary = $this->configured('node_binary');
        if ($nodeBinary !== null) {
            $browsershot->setNodeBinary($nodeBinary);
        }

        $npmBinary = $this->configured('npm_binary');
        if ($npmBinary !== null) {
            $browsershot->setNpmBinary($npmBinary);
        }

        $nodeModules = $this->configured('node_modules_path');
        if ($nodeModules !== null) {
            $browsershot->setNodeModulePath($nodeModules);
        }
    }

    /**
     * Browsershot does not read .puppeteerrc.cjs, so the project-cached Chrome
     * has to be handed to it by path.
     */
    private function chromePath(): ?string
    {
        return $this->configured('chrome_path')
            ?? (glob(dirname(__DIR__, 2).'/.puppeteer-cache/chrome/*/chrome-linux64/chrome') ?: [null])[0];
    }

    private function configured(string $key): ?string
    {
        $value = $this->config->get('mailroom.pdf.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
