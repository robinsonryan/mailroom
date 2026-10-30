<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Data\MessageContent;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;
use RobinsonRyan\Mailroom\Pdf\BrowsershotRenderer;
use RobinsonRyan\Mailroom\Pdf\MessageBodyPdf;
use RobinsonRyan\Mailroom\Tests\Feature\Pdf\ChromeShapedPdf;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Stands in for node: writes down the command Browsershot built and the page it
 * was pointed at, answers with a PDF. So what reaches Chrome is asserted where
 * no Chrome is installed.
 *
 * @return array{node: string, command: Closure(): array<string, mixed>, page: Closure(): string, stop: Closure(): void}
 */
function fakeNode(?string $pdf = null): array
{
    $dir = sys_get_temp_dir().'/mailroom-node-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $node = $dir.'/node';
    $answer = json_encode(['result' => base64_encode($pdf ?? ChromeShapedPdf::bytes())], JSON_THROW_ON_ERROR);
    $pagePath = 'printf \'%s\' "$2" | sed -E \'s/.*"url":"file:([^"]*)".*/\\1/; s#\\\\/#/#g\'';
    file_put_contents($node, "#!/bin/sh\nprintf '%s' \"\$2\" > {$dir}/command.json\ncp \"\$({$pagePath})\" {$dir}/page.html\nprintf '%s' '{$answer}'\n");
    chmod($node, 0755);

    return [
        'node' => $node,
        'command' => fn (): array => json_decode((string) file_get_contents($dir.'/command.json'), true, flags: JSON_THROW_ON_ERROR),
        'page' => fn (): string => (string) file_get_contents($dir.'/page.html'),
        'stop' => function () use ($dir): void {
            array_map(unlink(...), glob($dir.'/*') ?: []);
            rmdir($dir);
        },
    ];
}

function renderThroughFakeNode(bool $allowRemoteImages = true): array
{
    $fake = fakeNode();
    config()->set('mailroom.pdf.node_binary', $fake['node']);
    config()->set('mailroom.pdf.node_modules_path', '/opt/node_modules');

    try {
        app(BrowsershotRenderer::class)->render(
            '<p>Hi</p>',
            CarbonImmutable::parse('2026-03-04 05:06:07', 'UTC'),
            $allowRemoteImages,
        );

        return ($fake['command'])();
    } finally {
        ($fake['stop'])();
    }
}

/**
 * Stands in for a node that never finishes and ignores SIGTERM. It starts one
 * child in its own process group and one in a session of its own, which is how
 * puppeteer starts Chrome, and notes whether anything of an earlier run is
 * still alive when a new one begins.
 *
 * @return array{node: string, pids: Closure(): list<int>, attempts: Closure(): list<string>, command: Closure(): array<string, mixed>, stop: Closure(): void}
 */
function hangingNode(): array
{
    $dir = sys_get_temp_dir().'/mailroom-node-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/pids', '');
    file_put_contents($dir.'/attempts', '');
    file_put_contents($dir.'/node', <<<SH
        #!/bin/sh
        trap '' TERM
        state=started
        for pid in \$(cat {$dir}/pids); do
            if [ -e /proc/\$pid/stat ] && ! grep -q ') Z ' /proc/\$pid/stat; then state=overlapped; fi
        done
        echo \$state >> {$dir}/attempts
        printf '%s' "\$2" > {$dir}/command.json
        echo \$\$ >> {$dir}/pids
        sleep 300 &
        echo \$! >> {$dir}/pids
        setsid sh -c 'echo \$\$ >> {$dir}/pids; sleep 300; :' &
        wait
        SH);
    chmod($dir.'/node', 0755);

    return [
        'node' => $dir.'/node',
        'pids' => fn (): array => array_map(intval(...), array_values(array_filter(explode("\n", (string) file_get_contents($dir.'/pids'))))),
        'attempts' => fn (): array => array_values(array_filter(explode("\n", (string) file_get_contents($dir.'/attempts')))),
        'command' => fn (): array => json_decode((string) file_get_contents($dir.'/command.json'), true, flags: JSON_THROW_ON_ERROR),
        'stop' => function () use ($dir): void {
            array_map(unlink(...), glob($dir.'/*') ?: []);
            rmdir($dir);
        },
    ];
}

/** A zombie is dead: it only waits for PID 1 to collect it. */
function processIsAlive(int $pid): bool
{
    $stat = @file_get_contents("/proc/{$pid}/stat");

    return $stat !== false && ! str_contains($stat, ') Z ');
}

it('stamps the message date on what Chrome returns', function (): void {
    $fake = fakeNode();
    config()->set('mailroom.pdf.node_binary', $fake['node']);
    config()->set('mailroom.pdf.node_modules_path', '/opt/node_modules');

    $pdf = app(BrowsershotRenderer::class)->render('<p>Hi</p>', CarbonImmutable::parse('2026-03-04 05:06:07', 'UTC'));
    ($fake['stop'])();

    expect($pdf)->toStartWith('%PDF')->toContain("/CreationDate (D:20260304050607+00'00')");
});

it('renders Letter with backgrounds and JavaScript off', function (): void {
    $options = renderThroughFakeNode()['options'];

    expect($options['format'])->toBe('Letter')
        ->and($options['printBackground'])->toBeTrue()
        ->and($options['disableJavascript'])->toBeTrue()
        ->and($options['margin']['top'])->toContain('12.7');
});

it('serves the page from an http origin, never Browsershot\'s temporary file', function (): void {
    expect(renderThroughFakeNode()['options']['contentUrl'])->toBe('http://mailroom.invalid/');
});

it('hands Chrome the configured path, sandbox setting and arguments', function (): void {
    config()->set('mailroom.pdf.chrome_path', '/opt/chrome/chrome');
    config()->set('mailroom.pdf.no_sandbox', true);
    config()->set('mailroom.pdf.chromium_arguments', ['disable-gpu', 'font-render-hinting=none']);

    $options = renderThroughFakeNode()['options'];

    expect($options['executablePath'])->toBe('/opt/chrome/chrome')
        ->and($options['args'])->toContain('--no-sandbox', '--disable-gpu', '--font-render-hinting=none');
});

it('keeps the sandbox when configured to', function (): void {
    config()->set('mailroom.pdf.no_sandbox', false);

    expect(renderThroughFakeNode()['options']['args'])->not->toContain('--no-sandbox');
});

it('fits node\'s own limits inside the process timeout, leaving room for start-up', function (int $seconds): void {
    config()->set('mailroom.pdf.timeout', $seconds);

    $options = renderThroughFakeNode()['options'];

    // Navigation, then printToPDF and browser close: one protocol limit each.
    $worstCase = $options['timeout'] + 2 * $options['protocolTimeout'];

    expect($worstCase)->toBeLessThanOrEqual($seconds * 1000 * 3 / 4)
        ->and($options['timeout'])->toBeGreaterThan(0)
        ->and($options['protocolTimeout'])->toBeGreaterThan(0);
})->with([12, 60, 120]);

it('talks to Chrome over a pipe, so Chrome goes when node goes', function (): void {
    expect(renderThroughFakeNode()['options']['pipe'])->toBeTrue();
});

it('gives Chrome a profile of its own and removes it after the render', function (): void {
    $fake = fakeNode();
    config()->set('mailroom.pdf.node_binary', $fake['node']);
    config()->set('mailroom.pdf.node_modules_path', '/opt/node_modules');

    app(BrowsershotRenderer::class)->render('<p>Hi</p>', CarbonImmutable::now());
    $profiles = preg_grep('/^--user-data-dir=/', ($fake['command'])()['options']['args']);
    ($fake['stop'])();

    expect($profiles)->toHaveCount(1)
        ->and(is_dir(substr((string) reset($profiles), strlen('--user-data-dir='))))->toBeFalse();
});

it('kills everything the render started when the timeout fires, not only its shell', function (): void {
    $fake = hangingNode();
    config()->set('mailroom.pdf.node_binary', $fake['node']);
    config()->set('mailroom.pdf.node_modules_path', '/opt/node_modules');
    config()->set('mailroom.pdf.timeout', 1);
    $started = microtime(true);

    try {
        app(BrowsershotRenderer::class)->render('<p>Hi</p>', CarbonImmutable::now());
        $this->fail('Expected RenderFailed.');
    } catch (RenderFailed $failed) {
        $elapsed = microtime(true) - $started;
        $alive = array_values(array_filter(($fake['pids'])(), processIsAlive(...)));
        $pids = ($fake['pids'])();
        $profiles = preg_grep('/^--user-data-dir=/', ($fake['command'])()['options']['args']) ?: [];
        array_map(fn (int $pid): bool => posix_kill($pid, SIGKILL), $alive);
        ($fake['stop'])();

        expect($failed->getPrevious())->toBeInstanceOf(ProcessTimedOutException::class)
            ->and($pids)->toHaveCount(3)
            ->and($alive)->toBe([])
            ->and($elapsed)->toBeLessThan(5.0)
            ->and($profiles)->toHaveCount(1)
            ->and(is_dir(substr((string) reset($profiles), strlen('--user-data-dir='))))->toBeFalse();
    }
});

it('leaves another worker\'s render alone when its own timeout fires', function (): void {
    $fake = hangingNode();
    config()->set('mailroom.pdf.node_binary', $fake['node']);
    config()->set('mailroom.pdf.node_modules_path', '/opt/node_modules');
    config()->set('mailroom.pdf.timeout', 1);
    $neighbour = Process::fromShellCommandline('exec env MAILROOM_PDF_RUN=a-neighbouring-render setsid sleep 300');
    $neighbour->start();

    try {
        app(BrowsershotRenderer::class)->render('<p>Hi</p>', CarbonImmutable::now());
        $this->fail('Expected RenderFailed.');
    } catch (RenderFailed) {
        $survived = $neighbour->isRunning();
        $neighbour->stop(0);
        ($fake['stop'])();

        expect($survived)->toBeTrue();
    }
});

it('starts the retry only once everything from the first attempt is dead', function (): void {
    $fake = hangingNode();
    config()->set('mailroom.pdf.node_binary', $fake['node']);
    config()->set('mailroom.pdf.node_modules_path', '/opt/node_modules');
    config()->set('mailroom.pdf.timeout', 1);
    $message = new MessageContent(
        new MessageRef('mailbox-1', 'm1', 't1', CarbonImmutable::now()),
        [],
        'billing@acme.test',
        'info@example.com',
        'Invoice 7',
        'Plain body',
        null,
        [],
    );

    try {
        (new MessageBodyPdf(app(BrowsershotRenderer::class)))->render($message);
        $this->fail('Expected RenderFailed.');
    } catch (RenderFailed) {
        $attempts = ($fake['attempts'])();
        $alive = array_values(array_filter(($fake['pids'])(), processIsAlive(...)));
        array_map(fn (int $pid): bool => posix_kill($pid, SIGKILL), $alive);
        ($fake['stop'])();

        expect($attempts)->toBe(['started', 'started'])->and($alive)->toBe([]);
    }
});

it('fails every lookup when remote images are refused, and only the page\'s own host when allowed', function (): void {
    $refused = renderThroughFakeNode(allowRemoteImages: false)['options']['args'];
    $allowed = renderThroughFakeNode(allowRemoteImages: true)['options']['args'];

    expect($refused)->toContain('--host-resolver-rules=MAP * ~NOTFOUND')
        ->and($allowed)->toContain('--host-resolver-rules=MAP mailroom.invalid ~NOTFOUND')
        ->not->toContain('--host-resolver-rules=MAP * ~NOTFOUND');
});

it('turns a browser failure into RenderFailed, keeping the cause', function (): void {
    config()->set('mailroom.pdf.node_binary', '/bin/false');

    try {
        app(BrowsershotRenderer::class)->render('<p>Hi</p>', CarbonImmutable::now());
        $this->fail('Expected RenderFailed.');
    } catch (RenderFailed $failed) {
        expect($failed->getMessage())->toBe('Chrome could not render the PDF.')
            ->and($failed->getPrevious())->toBeInstanceOf(Throwable::class);
    }
});

it('hands Chrome a document that mentions a local address, unaltered, rather than refusing it', function (string $mention): void {
    $fake = fakeNode();
    config()->set('mailroom.pdf.node_binary', $fake['node']);
    config()->set('mailroom.pdf.node_modules_path', '/opt/node_modules');
    $html = "<!DOCTYPE html><html><body><p>{$mention}</p></body></html>";

    $pdf = app(BrowsershotRenderer::class)->render($html, CarbonImmutable::now());
    $page = ($fake['page'])();
    ($fake['stop'])();

    expect($pdf)->toStartWith('%PDF')->and($page)->toBe($html);
})->with([
    'a file url' => ['please open file://nas/share/invoice.pdf'],
    'a single slash' => ['saved to file:/C:/Users/ryan/invoice.pdf'],
    'backslashes' => ['saved to file:\\\\nas\\share'],
    'upper case' => ['FILE:///etc/passwd'],
    'entity-encoded' => ['&#102;ile:///etc/passwd'],
    'view-source' => ['see the preview-source and review-sources sections'],
    'scheme-relative localhost' => ['listening on //localhost:3000'],
    'scheme-relative loopback' => ['listening on //127.0.0.1:8000'],
    'unc localhost' => ['share at \\\\localhost\\c$'],
]);

it('lets the date stamp\'s own refusal through, naming what it refused', function (): void {
    $fake = fakeNode(pdf: 'not a pdf');
    config()->set('mailroom.pdf.node_binary', $fake['node']);
    config()->set('mailroom.pdf.node_modules_path', '/opt/node_modules');

    try {
        app(BrowsershotRenderer::class)->render('<p>Hi</p>', CarbonImmutable::now());
        $this->fail('Expected RenderFailed.');
    } catch (RenderFailed $failed) {
        expect($failed->getMessage())->toBe('The rendered PDF has no Info dictionary.');
    } finally {
        ($fake['stop'])();
    }
});
