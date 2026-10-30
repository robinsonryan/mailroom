<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;
use RobinsonRyan\Mailroom\Pdf\BrowsershotRenderer;
use RobinsonRyan\Mailroom\Tests\Feature\Pdf\ChromeShapedPdf;

/**
 * Stands in for node: writes down the command Browsershot built, answers with
 * a PDF. So what reaches Chrome is asserted where no Chrome is installed.
 *
 * @return array{node: string, command: Closure(): array<string, mixed>, stop: Closure(): void}
 */
function fakeNode(): array
{
    $dir = sys_get_temp_dir().'/mailroom-node-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $node = $dir.'/node';
    $answer = json_encode(['result' => base64_encode(ChromeShapedPdf::bytes())], JSON_THROW_ON_ERROR);
    file_put_contents($node, "#!/bin/sh\nprintf '%s' \"\$2\" > {$dir}/command.json\nprintf '%s' '{$answer}'\n");
    chmod($node, 0755);

    return [
        'node' => $node,
        'command' => fn (): array => json_decode((string) file_get_contents($dir.'/command.json'), true, flags: JSON_THROW_ON_ERROR),
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

it('gives navigation less time than the process, so node closes Chrome itself on a hung image', function (): void {
    config()->set('mailroom.pdf.timeout', 60);

    expect(renderThroughFakeNode()['options']['timeout'])->toBe(40000);
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

it('turns Browsershot refusing the HTML into RenderFailed', function (): void {
    config()->set('mailroom.pdf.node_binary', '/bin/false');

    app(BrowsershotRenderer::class)->render('<a href="file:///etc/passwd">x</a>', CarbonImmutable::now());
})->throws(RenderFailed::class);
