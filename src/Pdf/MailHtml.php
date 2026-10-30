<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Pdf;

use DOMAttr;
use DOMDocument;
use DOMNode;
use DOMXPath;
use RobinsonRyan\Mailroom\Exceptions\RenderFailed;

/**
 * A message's HTML part with everything active taken out, split into what goes
 * in the composed document's head and body. Parsed rather than pattern-matched
 * so that malformed markup cannot slip past; the page also renders with
 * JavaScript off, so this is the second lock, not the only one.
 */
final readonly class MailHtml
{
    /** Elements that run code, navigate the page or belong to the mail's own document shell. */
    private const STRIPPED = '//script|//iframe|//frame|//frameset|//object|//embed|//applet|//meta|//title|//comment()';

    /** URL schemes that run code or reach the local disk. */
    private const UNSAFE_URL = '/^(javascript|vbscript|file|view-source):/i';

    private function __construct(
        public string $head,
        public string $body,
    ) {}

    /**
     * @throws RenderFailed
     */
    public static function clean(string $html): self
    {
        // Non-ASCII goes in as numeric entities so a mail's own charset
        // declaration cannot make the parser misread UTF-8 bytes.
        $dom = new DOMDocument;
        $ascii = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        if (! $dom->loadHTML($ascii, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RenderFailed('The message HTML could not be parsed.');
        }

        $xpath = new DOMXPath($dom);

        foreach (self::matches($xpath, self::STRIPPED) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (self::matches($xpath, '//@*') as $attribute) {
            if ($attribute instanceof DOMAttr && self::isUnsafe($attribute)) {
                $attribute->ownerElement?->removeAttributeNode($attribute);
            }
        }

        return new self(self::inner($dom, 'head'), self::inner($dom, 'body'));
    }

    private static function isUnsafe(DOMAttr $attribute): bool
    {
        // Browsers ignore whitespace and control characters inside a scheme.
        $url = (string) preg_replace('/[\s\x00-\x1f]+/', '', $attribute->value);

        return str_starts_with(strtolower($attribute->name), 'on') || preg_match(self::UNSAFE_URL, $url) === 1;
    }

    /**
     * Materialised, because removing nodes during a live walk skips some.
     *
     * @return list<DOMNode>
     */
    private static function matches(DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);

        return $nodes === false ? [] : array_values(array_filter(
            iterator_to_array($nodes),
            fn (mixed $node): bool => $node instanceof DOMNode,
        ));
    }

    private static function inner(DOMDocument $dom, string $tag): string
    {
        $html = '';

        foreach ($dom->getElementsByTagName($tag)->item(0)->childNodes ?? [] as $child) {
            $html .= $dom->saveHTML($child);
        }

        return $html;
    }
}
