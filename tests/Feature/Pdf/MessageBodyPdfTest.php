<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Data\MessageContent;
use RobinsonRyan\Mailroom\Data\MessageRef;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;
use RobinsonRyan\Mailroom\Pdf\MessageBodyPdf;
use RobinsonRyan\Mailroom\Tests\Feature\Pdf\RecordingRenderer;
use Spatie\Browsershot\Browsershot;

function pdfMessage(
    ?string $text = 'Plain body',
    ?string $html = null,
    string $from = 'Acme Billing <billing@acme.test>',
    string $to = 'info@example.com',
    string $subject = 'Invoice 7',
): MessageContent {
    return new MessageContent(
        new MessageRef('mailbox-1', 'm1', 't1', CarbonImmutable::parse('2026-03-04 05:06:07', 'UTC')),
        [],
        $from,
        $to,
        $subject,
        $text,
        $html,
        [],
    );
}

function composedHtml(MessageContent $message): string
{
    $renderer = new RecordingRenderer;
    (new MessageBodyPdf($renderer))->render($message);

    return $renderer->calls[0]['html'];
}

it('puts a From, To, Date, Subject block above the body, dated by the message', function (): void {
    $html = composedHtml(pdfMessage(html: '<p>The body</p>'));

    $from = strpos($html, 'billing@acme.test');
    $to = strpos($html, 'info@example.com');
    $date = strpos($html, 'Wed, 04 Mar 2026 05:06:07 +0000');
    $subject = strpos($html, 'Invoice 7</td>');
    $body = strpos($html, 'The body');

    expect($from)->toBeInt()
        ->and($to)->toBeGreaterThan($from)
        ->and($date)->toBeGreaterThan($to)
        ->and($subject)->toBeGreaterThan($date)
        ->and($body)->toBeGreaterThan($subject)
        ->and($html)->toContain('>From<', '>To<', '>Date<', '>Subject<');
});

it('styles each header cell inline, so a mail\'s own th and td rules cannot reach it', function (): void {
    $html = composedHtml(pdfMessage());

    preg_match_all('/<(?:th|td) style="([^"]*)"/', $html, $cells);

    expect($cells[1])->toHaveCount(8)
        ->each->toContain('color:#000', 'background:#fff', 'font:13px');
});

it('escapes every header value', function (): void {
    $html = composedHtml(pdfMessage(
        from: '"Acme" <billing@acme.test>',
        to: '<b>info@example.com</b>',
        subject: '</td><script>alert(1)</script> & co',
    ));

    expect($html)->toContain('&lt;billing@acme.test&gt;')
        ->toContain('&lt;b&gt;info@example.com&lt;/b&gt;')
        ->toContain('&lt;/td&gt;&lt;script&gt;alert(1)&lt;/script&gt; &amp; co')
        ->not->toContain('<script>')
        ->not->toContain('<b>info');
});

it('titles the document with the escaped subject, not the mail\'s own title', function (): void {
    $html = composedHtml(pdfMessage(
        html: '<html><head><title>Mail title</title></head><body>Hi</body></html>',
        subject: 'A <b>subject</b>',
    ));

    expect($html)->toContain('<title>A &lt;b&gt;subject&lt;/b&gt;</title>')
        ->not->toContain('Mail title');
});

it('renders the HTML part when there is one, not the text part', function (): void {
    $html = composedHtml(pdfMessage(text: 'The text part', html: '<p class="x">The <em>html</em> part</p>'));

    expect($html)->toContain('<p class="x">The <em>html</em> part</p>')
        ->not->toContain('The text part');
});

it('keeps the mail\'s own styles and drops its document shell', function (): void {
    $html = composedHtml(pdfMessage(html: '<!DOCTYPE html><html><head><style>.x{color:red}</style></head><body><p class="x">Hi</p></body></html>'));

    expect($html)->toContain('<style>.x{color:red}</style>')
        ->and(substr_count(strtolower($html), '<body'))->toBe(1)
        ->and(substr_count(strtolower($html), '<html'))->toBe(1)
        ->and(substr_count(strtolower($html), '<!doctype'))->toBe(1);
});

it('keeps non-ASCII text intact through the HTML clean-up', function (string $mail): void {
    $html = composedHtml(pdfMessage(html: $mail));

    expect(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))->toContain('Société — €12 日本語');
})->with([
    'a fragment' => ['<p>Société — €12 日本語</p>'],
    'a document declaring another charset' => ['<html><head><meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"></head><body><p>Société — €12 日本語</p></body></html>'],
]);

it('falls back to the text part, escaped in a wrapping pre, when there is no HTML', function (): void {
    $html = composedHtml(pdfMessage(text: "Line one\n<b>not markup</b> & more"));

    expect($html)->toContain("<pre style=\"white-space:pre-wrap\">Line one\n&lt;b&gt;not markup&lt;/b&gt; &amp; more</pre>");
});

it('treats a blank HTML part as no HTML', function (): void {
    $html = composedHtml(pdfMessage(text: 'The text part', html: "  \n "));

    expect($html)->toContain('>The text part</pre>');
});

it('still renders the header block for a message with no body at all', function (): void {
    $html = composedHtml(pdfMessage(text: null));

    expect($html)->toContain('Invoice 7', '<pre style="white-space:pre-wrap"></pre>');
});

it('strips script elements wherever they hide', function (string $hostile): void {
    $html = composedHtml(pdfMessage(html: "<p>Keep me</p>{$hostile}"));

    expect(strtolower($html))->not->toContain('<script')
        ->not->toContain('alert(')
        ->and($html)->toContain('Keep me');
})->with([
    'plain' => ['<script>alert(1)</script>'],
    'upper case' => ['<SCRIPT SRC="https://evil.test/x.js">alert(1)</SCRIPT>'],
    'odd spacing' => ['<script  type="text/javascript" >alert(1)</script  >'],
    'in svg' => ['<svg><script>alert(1)</script></svg>'],
    'in the head' => ['<html><head><script>alert(1)</script></head><body></body></html>'],
]);

it('does not reassemble a script tag split to survive a single pass', function (): void {
    $html = composedHtml(pdfMessage(html: '<p>Keep me</p><scr<script>ipt>alert(1)</scr</script>ipt>'));

    expect(strtolower($html))->not->toContain('<script')
        ->and($html)->toContain('Keep me');
});

it('strips event-handler attributes and keeps the element', function (string $hostile, string $kept): void {
    $html = composedHtml(pdfMessage(html: $hostile));

    expect(strtolower($html))->not->toMatch('/\son[a-z]+\s*=/')
        ->not->toContain('alert(')
        ->and($html)->toContain($kept);
})->with([
    'double quoted' => ['<img src="https://cdn.test/a.png" onerror="alert(1)">', 'https://cdn.test/a.png'],
    'unquoted' => ['<img src=https://cdn.test/b.png onerror=alert(1)>', 'https://cdn.test/b.png'],
    'upper case' => ['<div ONCLICK="alert(1)">Kept text</div>', 'Kept text'],
    'on the body' => ['<body onload="alert(1)"><p>Kept text</p></body>', 'Kept text'],
    'on svg' => ['<svg onload="alert(1)"></svg><p>Kept text</p>', 'Kept text'],
]);

it('strips everything else that could load active content or navigate the page', function (string $hostile): void {
    $html = strtolower(composedHtml(pdfMessage(html: "<p>Keep me</p>{$hostile}")));

    expect($html)->not->toContain('javascript:')
        ->not->toContain('vbscript:')
        ->not->toContain('<iframe')
        ->not->toContain('<object')
        ->not->toContain('<embed')
        ->not->toContain('http-equiv')
        ->toContain('keep me');
})->with([
    'javascript link' => ['<a href="javascript:alert(1)">x</a>'],
    'javascript link, obscured' => ['<a href=" JaVaScRiPt:alert(1)">x</a>'],
    'javascript link, entity-encoded' => ['<a href="&#106;avascript:alert(1)">x</a>'],
    'vbscript link' => ['<a href="vbscript:msgbox(1)">x</a>'],
    'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
    'object' => ['<object data="https://evil.test/x"></object>'],
    'embed' => ['<embed src="https://evil.test/x">'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test/">'],
]);

it('survives what Word pastes into a mail, so Browsershot does not refuse the document', function (): void {
    $html = composedHtml(pdfMessage(html: <<<'HTML'
        <html><head>
        <link rel=File-List href="file:///C:/Users/x/AppData/Local/Temp/msohtmlclip1/01/clip_filelist.xml">
        <!--[if gte mso 9]><xml><o:OfficeDocumentSettings>file:///C:/x</o:OfficeDocumentSettings></xml><![endif]-->
        </head><body><p>Total due</p><img src="file:///C:/Users/x/image001.png"></body></html>
        HTML));

    expect($html)->toContain('Total due')
        ->not->toContain('file:')
        ->and(Browsershot::html($html))->toBeInstanceOf(Browsershot::class);
});

it('renders once, with remote images, when the first attempt works', function (): void {
    $renderer = new RecordingRenderer;
    $message = pdfMessage();

    $pdf = (new MessageBodyPdf($renderer))->render($message);

    expect($pdf)->toBe('%PDF-recorded')
        ->and($renderer->calls)->toHaveCount(1)
        ->and($renderer->calls[0]['allowRemoteImages'])->toBeTrue()
        ->and($renderer->calls[0]['createdAt']->equalTo($message->ref->receivedAt))->toBeTrue();
});

it('retries once with images off when the first attempt fails', function (): void {
    $renderer = new RecordingRenderer(failures: 1);
    $message = pdfMessage(html: '<img src="https://cdn.test/huge.png">');

    $pdf = (new MessageBodyPdf($renderer))->render($message);

    expect($pdf)->toBe('%PDF-recorded')
        ->and($renderer->calls)->toHaveCount(2)
        ->and($renderer->calls[0]['allowRemoteImages'])->toBeTrue()
        ->and($renderer->calls[1]['allowRemoteImages'])->toBeFalse()
        ->and($renderer->calls[1]['html'])->toBe($renderer->calls[0]['html'])
        ->and($renderer->calls[1]['createdAt']->equalTo($message->ref->receivedAt))->toBeTrue();
});

it('lets a second failure propagate without a third attempt', function (): void {
    $renderer = new RecordingRenderer(failures: 2);

    expect(fn (): string => (new MessageBodyPdf($renderer))->render(pdfMessage()))->toThrow(RenderFailed::class)
        ->and($renderer->calls)->toHaveCount(2);
});
