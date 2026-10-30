<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Data\MessageContent;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;
use RobinsonRyan\Mailroom\Pdf\BrowsershotRenderer;
use RobinsonRyan\Mailroom\Pdf\MessageBodyPdf;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/*
 * Real Chrome. Skipped, with the reason, where it is not installed: `ddev restart`
 * brings the libraries and the post-start hook downloads the browser.
 */

const NO_CHROME = 'Chrome is not installed: run `ddev restart` (post-start installs it into .puppeteer-cache).';

const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function chromeMissing(): bool
{
    return glob(dirname(__DIR__, 3).'/.puppeteer-cache/chrome/*/chrome-linux64/chrome') === [];
}

/**
 * Serves the pixel to anything that asks and logs each request's path. A path
 * starting /hang is logged and never answered.
 *
 * @return array{url: string, hits: Closure(): list<string>, stop: Closure(): void}
 */
function imageServer(string $host = '127.0.0.1'): array
{
    $dir = sys_get_temp_dir().'/mailroom-pdf-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $log = $dir.'/hits.log';
    file_put_contents($log, '');
    file_put_contents($dir.'/router.php', sprintf(
        '<?php file_put_contents(%s, $_SERVER["REQUEST_URI"]."\n", FILE_APPEND); if (str_starts_with($_SERVER["REQUEST_URI"], "/hang")) { sleep(300); } header("Content-Type: image/png"); echo base64_decode(%s);',
        var_export($log, true),
        var_export(ONE_PIXEL_PNG, true),
    ));

    $port = random_int(20000, 40000);
    $server = new Process(['php', '-S', '0.0.0.0:'.$port, $dir.'/router.php']);
    $server->start();
    $url = "http://{$host}:{$port}";

    for ($tries = 0; $tries < 50 && @file_get_contents("http://127.0.0.1:{$port}/ready") === false; $tries++) {
        usleep(100_000);
    }

    file_put_contents($log, '');

    return [
        'url' => $url,
        'hits' => fn (): array => array_values(array_filter(explode("\n", (string) file_get_contents($log)))),
        'stop' => function () use ($server, $dir): void {
            $server->stop(0);
            array_map(unlink(...), glob($dir.'/*') ?: []);
            rmdir($dir);
        },
    ];
}

/**
 * Every process this checkout's Browsershot started that is still running,
 * Chrome's crashpad daemons included. A zombie has no command line.
 *
 * @return list<string>
 */
function renderProcesses(): array
{
    $checkout = dirname(__DIR__, 3);
    $running = array_map(
        fn (string $cmdline): string => str_replace("\0", ' ', (string) @file_get_contents($cmdline)),
        glob('/proc/[0-9]*/cmdline') ?: [],
    );

    return array_values(array_filter($running, fn (string $command): bool => str_contains($command, $checkout.'/.puppeteer-cache')
        || str_contains($command, $checkout.'/vendor/spatie/browsershot')));
}

/**
 * Real node with its own limits pushed out of reach, so that PHP's timeout is
 * what ends the render, as it does when a phase overruns its share.
 */
function nodeThatOutlivesThePhpTimeout(): string
{
    $node = tempnam(sys_get_temp_dir(), 'mailroom-node-');
    file_put_contents($node, <<<'SH'
        #!/bin/sh
        exec node "$1" "$(printf '%s' "$2" | sed -E 's/"(timeout|protocolTimeout)":[0-9]+/"\1":600000/g')"
        SH);
    chmod($node, 0755);

    return $node;
}

beforeEach(function (): void {
    config()->set('mailroom.pdf.timeout', 120);
    $this->date = CarbonImmutable::parse('2026-03-04 05:06:07', 'UTC');
});

it('renders a message to a PDF titled by its subject and dated by the message', function (): void {
    $message = new MessageContent(
        new MessageRef('mailbox-1', 'm1', 't1', $this->date),
        [],
        'Acme Billing <billing@acme.test>',
        'info@example.com',
        'Invoice 7 for March',
        null,
        '<html><head><style>p{color:#333}</style></head><body><p>Total due: $12.00</p><script>document.title="pwned"</script></body></html>',
        [],
    );

    $pdf = app(MessageBodyPdf::class)->render($message);

    expect($pdf)->toStartWith('%PDF')
        ->toContain('/Title (Invoice 7 for March)')
        ->toContain("/CreationDate (D:20260304050607+00'00')")
        ->and(substr_count($pdf, 'CreationDate'))->toBe(1);
})->group('browser')->skip(chromeMissing(...), NO_CHROME);

it('keeps a subject that spells out PDF syntax from moving the real date', function (string $subject): void {
    $html = "<!DOCTYPE html><html><head><title>{$subject}</title></head><body>Hi</body></html>";

    $pdf = app(BrowsershotRenderer::class)->render($html, $this->date);

    expect($pdf)->toContain("\n/CreationDate (D:20260304050607+00'00')");
})->with([
    'a date' => ["/CreationDate (D:20000101000000+00'00')"],
    'the end of the object' => ['Invoice endobj'],
])->group('browser')->skip(chromeMissing(...), NO_CHROME);

it('renders mail that talks about local addresses in its text', function (?string $text, ?string $html): void {
    $message = new MessageContent(
        new MessageRef('mailbox-1', 'm1', 't1', $this->date),
        [],
        'Acme Billing <billing@acme.test>',
        'info@example.com',
        'Invoice 7 for March',
        $text,
        $html,
        [],
    );

    $pdf = app(MessageBodyPdf::class)->render($message);

    expect($pdf)->toStartWith('%PDF')->toContain('/Title (Invoice 7 for March)');
})->with([
    'the text part' => ['please open file://nas/share/invoice.pdf or //localhost:3000', null],
    'the HTML part' => [null, '<p class="preview-source">see file:/C:/invoice.pdf, the review-sources page and //127.0.0.1:8000</p>'],
])->group('browser')->skip(chromeMissing(...), NO_CHROME);

it('never runs JavaScript, even in a document that reaches the renderer with a script in it', function (): void {
    $html = '<!DOCTYPE html><html><head><title>Untouched</title></head><body><script>document.title = "ran";</script></body></html>';

    expect(app(BrowsershotRenderer::class)->render($html, $this->date))->toContain('/Title (Untouched)');
})->group('browser')->skip(chromeMissing(...), NO_CHROME);

it('fetches remote images when they are allowed, and requests nothing when they are not', function (string $how): void {
    // Chrome refuses private addresses from a public-looking page (pinned
    // below); with that off, this local server stands in for a remote host.
    config()->set('mailroom.pdf.chromium_arguments', ['disable-gpu', 'disable-features=LocalNetworkAccessChecks']);
    $server = imageServer($how === 'by name' ? (string) gethostname() : gethostbyname((string) gethostname()));
    $html = "<!DOCTYPE html><html><body><img src=\"{$server['url']}/tag.png\" width=\"20\" height=\"20\">"
        ."<div style=\"width:5px;height:5px;background:url({$server['url']}/css.png)\"></div></body></html>";

    try {
        $allowed = app(BrowsershotRenderer::class)->render($html, $this->date);
        $hitsAllowed = ($server['hits'])();

        $refused = app(BrowsershotRenderer::class)->render($html, $this->date, allowRemoteImages: false);
        $hitsRefused = array_slice(($server['hits'])(), count($hitsAllowed));
    } finally {
        ($server['stop'])();
    }

    expect($hitsAllowed)->toContain('/tag.png', '/css.png')
        ->and($allowed)->toMatch('~/Width 1\b~')
        ->and($hitsRefused)->toBe([])
        ->and($refused)->toStartWith('%PDF')
        ->not->toMatch('~/Width 1\b~');
})->with(['by address', 'by name'])->group('browser')->skip(chromeMissing(...), NO_CHROME);

it('keeps a mail off the private network, even with remote images allowed', function (): void {
    $server = imageServer((string) gethostname());

    try {
        $pdf = app(BrowsershotRenderer::class)->render(
            "<!DOCTYPE html><html><body><img src=\"{$server['url']}/internal.png\" width=\"20\" height=\"20\"></body></html>",
            $this->date,
        );
        $hits = ($server['hits'])();
    } finally {
        ($server['stop'])();
    }

    expect($hits)->toBe([])->and($pdf)->toStartWith('%PDF');
})->group('browser')->skip(chromeMissing(...), NO_CHROME);

it('cannot be made to read the local disk through an absolute path', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'mailroom-pdf-');
    file_put_contents($file, base64_decode(ONE_PIXEL_PNG));

    try {
        $pdf = app(BrowsershotRenderer::class)->render(
            "<!DOCTYPE html><html><body><img src=\"{$file}\" width=\"20\" height=\"20\"></body></html>",
            $this->date,
        );
    } finally {
        unlink($file);
    }

    expect($pdf)->toStartWith('%PDF')->not->toMatch('~/Width 1\b~');
})->group('browser')->skip(chromeMissing(...), NO_CHROME);

it('refuses a file: URL at Chrome, the only thing between the page and the disk', function (string $url): void {
    $image = tempnam(sys_get_temp_dir(), 'mailroom-pdf-');
    $css = tempnam(sys_get_temp_dir(), 'mailroom-pdf-');
    $frame = tempnam(sys_get_temp_dir(), 'mailroom-pdf-');
    $dataPixel = 'data:image/png;base64,'.ONE_PIXEL_PNG;
    file_put_contents($image, base64_decode(ONE_PIXEL_PNG));
    file_put_contents($css, "body{background:url({$dataPixel})}");
    file_put_contents($frame, "<img src=\"{$dataPixel}\" width=\"20\" height=\"20\">");
    $to = fn (string $file): string => str_replace('%s', $file, $url);

    try {
        $pdf = app(BrowsershotRenderer::class)->render(
            '<!DOCTYPE html><html><head>'
            ."<link rel=\"stylesheet\" href=\"{$to($css)}\">"
            ."<style>div{width:9px;height:9px;background:url({$to($image)})}</style>"
            .'</head><body>'
            ."<img src=\"{$to($image)}\" width=\"20\" height=\"20\">"
            .'<div></div>'
            ."<iframe src=\"{$to($frame)}\"></iframe>"
            ."<object data=\"{$to($frame)}\"></object>"
            ."<embed src=\"{$to($frame)}\">"
            .'</body></html>',
            $this->date,
        );
    } finally {
        array_map(unlink(...), [$image, $css, $frame]);
    }

    expect($pdf)->toStartWith('%PDF')->not->toMatch('~/Width 1\b~');
})->with([
    'three slashes' => ['file://%s'],
    'one slash' => ['file:%s'],
    'upper case' => ['FILE://%s'],
    'view-source' => ['view-source:file://%s'],
])->group('browser')->skip(chromeMissing(...), NO_CHROME);

it('resolves no relative URL against a file: base', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'mailroom-pdf-');
    file_put_contents($file, base64_decode(ONE_PIXEL_PNG));

    try {
        $pdf = app(BrowsershotRenderer::class)->render(
            '<!DOCTYPE html><html><head><base href="file://'.dirname($file).'/"></head>'
            .'<body><img src="'.basename($file).'" width="20" height="20"></body></html>',
            $this->date,
        );
    } finally {
        unlink($file);
    }

    expect($pdf)->toStartWith('%PDF')->not->toMatch('~/Width 1\b~');
})->group('browser')->skip(chromeMissing(...), NO_CHROME);

it('keeps a mail off this machine however it spells the loopback address', function (): void {
    $server = imageServer();
    $port = (string) parse_url($server['url'], PHP_URL_PORT);
    $urls = array_map(fn (string $host): string => "//{$host}:{$port}/{$host}.png", ['localhost', '127.0.0.1', '0.0.0.0', '[::1]']);
    $html = '<!DOCTYPE html><html><head><style>'
        .implode('', array_map(fn (int $at, string $url): string => ".n{$at}{background:url({$url}?css)}", array_keys($urls), $urls))
        .'</style></head><body>'
        .implode('', array_map(fn (int $at, string $url): string => "<img src=\"{$url}\"><img src=\"http:{$url}\"><p class=\"n{$at}\">x</p>", array_keys($urls), $urls))
        .'</body></html>';

    try {
        $pdf = app(BrowsershotRenderer::class)->render($html, $this->date);
        $hits = ($server['hits'])();
    } finally {
        ($server['stop'])();
    }

    expect($hits)->toBe([])->and($pdf)->toStartWith('%PDF');
})->group('browser')->skip(chromeMissing(...), NO_CHROME);

it('leaves no node or Chrome behind when PHP\'s timeout ends the render', function (): void {
    $node = nodeThatOutlivesThePhpTimeout();
    $server = imageServer(gethostbyname((string) gethostname()));
    config()->set('mailroom.pdf.node_binary', $node);
    config()->set('mailroom.pdf.timeout', 20);
    config()->set('mailroom.pdf.chromium_arguments', ['disable-gpu', 'disable-features=LocalNetworkAccessChecks']);
    $chromeTemp = fn (): array => glob(sys_get_temp_dir().'/org.chromium.Chromium.*') ?: [];
    $tempBefore = $chromeTemp();
    $failure = null;

    try {
        app(BrowsershotRenderer::class)->render(
            "<!DOCTYPE html><html><body><img src=\"{$server['url']}/hang.png\"></body></html>",
            $this->date,
        );
    } catch (RenderFailed $failed) {
        $failure = $failed;
    } finally {
        $left = renderProcesses();
        $hits = ($server['hits'])();
        ($server['stop'])();
        unlink($node);
    }

    expect($hits)->toBe(['/hang.png'])
        ->and($failure?->getPrevious())->toBeInstanceOf(ProcessTimedOutException::class)
        ->and($left)->toBe([])
        ->and($chromeTemp())->toBe($tempBefore);
})->group('browser')->skip(chromeMissing(...), NO_CHROME);
