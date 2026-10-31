<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Gmail;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Data\Attachment;
use RobinsonRyan\Mailroom\Support\HtmlText;

/**
 * One users.messages resource read: the part tree walked into body text and
 * attachment metadata. Gmail may hand any part's content inline as body.data or
 * apart from the message behind body.attachmentId, so what a part is for is read
 * from its name and type, never from where its content sits.
 */
final readonly class GmailMessage
{
    /** At this size an image is a document someone attached, not a logo. */
    private const EMBEDDED_IMAGE_LIMIT = 100_000;

    private const BODY_TYPES = ['text/plain', 'text/html'];

    /** @var list<array<string, mixed>> text parts, their content inline or stored apart */
    private array $bodies;

    /** @var list<Attachment> */
    private array $attachments;

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, string>  $stored  attachmentId => data, for the parts in storedBodies()
     */
    public function __construct(private array $resource, private array $stored = [])
    {
        [$this->bodies, $named] = $this->walk((array) ($resource['payload'] ?? []));

        // The html decides which images are embedded, so it has to be read first.
        $referenced = $this->referencedContentIds();
        $attached = array_filter($named, fn (array $part): bool => ! $this->isEmbedded($part, $referenced));
        $this->attachments = array_values(array_map($this->attachment(...), $attached));
    }

    /** base64url, the alphabet Gmail uses for every body and attachment. */
    public static function decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public function threadId(): string
    {
        return (string) $this->resource['threadId'];
    }

    public function receivedAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMsUTC((string) ($this->resource['internalDate'] ?? '0'));
    }

    /** @return list<string> */
    public function labelIds(): array
    {
        return array_values((array) ($this->resource['labelIds'] ?? []));
    }

    /** @return array<string, string> name as sent => value; last one wins */
    public function headers(): array
    {
        return array_column((array) ($this->resource['payload']['headers'] ?? []), 'value', 'name');
    }

    /** The named header, matched in any case, or ''. */
    public function header(string $name): string
    {
        $headers = array_change_key_case($this->headers());

        return $headers[strtolower($name)] ?? '';
    }

    public function text(): ?string
    {
        return $this->body('text/plain');
    }

    public function html(): ?string
    {
        return $this->body('text/html');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * The body of the part with this Gmail partId. Unlike the attachmentId inside
     * it, a partId stays the same across reads. A message that is nothing but its
     * attachment has the part id ''.
     *
     * @return array<string, mixed>|null
     */
    public function partBody(string $partId): ?array
    {
        $part = $this->findPart((array) ($this->resource['payload'] ?? []), $partId);

        return $part === null ? null : (array) ($part['body'] ?? []);
    }

    /**
     * The attachmentIds of the bodies text() and html() read that Gmail stored
     * apart from the message; withBodies() takes their content.
     *
     * @return list<string>
     */
    public function storedBodies(): array
    {
        $ids = [];

        foreach (self::BODY_TYPES as $mime) {
            $part = $this->bodyPart($mime);

            if ($part !== null && ! isset($part['body']['data'])) {
                $ids[] = (string) $part['body']['attachmentId'];
            }
        }

        return $ids;
    }

    /** @param  array<string, string>  $stored  attachmentId => data */
    public function withBodies(array $stored): self
    {
        return new self($this->resource, $stored);
    }

    /** The plain part, or the html reduced to text, cut to $limit characters. */
    public function summaryText(int $limit): string
    {
        return mb_substr($this->text() ?? HtmlText::from((string) $this->html()), 0, $limit);
    }

    /**
     * A named part holding content is an attachment, and what it contains (an
     * attached message's parts) belongs to it. Text that came inline stays
     * readable as a body too, for mail whose only text is a named part.
     *
     * @param  array<string, mixed>  $part
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>} text parts, named parts
     */
    private function walk(array $part): array
    {
        $filename = (string) ($part['filename'] ?? '');
        $body = (array) ($part['body'] ?? []);
        $isText = in_array(strtolower((string) ($part['mimeType'] ?? '')), self::BODY_TYPES, true);
        $hasContent = isset($body['data']) || isset($body['attachmentId']);

        if ($filename !== '' && $hasContent) {
            return [$isText && isset($body['data']) ? [$part] : [], [$part]];
        }

        $bodies = $isText && $hasContent ? [$part] : [];
        $named = [];

        foreach ((array) ($part['parts'] ?? []) as $child) {
            [$childBodies, $childNamed] = $this->walk((array) $child);
            $bodies = [...$bodies, ...$childBodies];
            $named = [...$named, ...$childNamed];
        }

        return [$bodies, $named];
    }

    /**
     * @param  array<string, mixed>  $part
     * @return array<string, mixed>|null
     */
    private function findPart(array $part, string $partId): ?array
    {
        if ((string) ($part['partId'] ?? '') === $partId) {
            return $part;
        }

        foreach ((array) ($part['parts'] ?? []) as $child) {
            $found = $this->findPart((array) $child, $partId);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $part */
    private function attachment(array $part): Attachment
    {
        return new Attachment(
            (string) ($part['partId'] ?? ''),
            (string) $part['filename'],
            strtolower((string) ($part['mimeType'] ?? '')),
            (int) ($part['body']['size'] ?? 0),
        );
    }

    private function body(string $mime): ?string
    {
        $part = $this->bodyPart($mime);

        if ($part === null) {
            return null;
        }

        $data = $part['body']['data'] ?? $this->stored[$part['body']['attachmentId']] ?? '';

        return mb_scrub(self::decode((string) $data), 'UTF-8');
    }

    /**
     * The first part of this type, preferring inline parts over ones marked attachment.
     *
     * @return array<string, mixed>|null
     */
    private function bodyPart(string $mime): ?array
    {
        $parts = array_filter($this->bodies, fn (array $part): bool => strtolower((string) $part['mimeType']) === $mime);
        $inline = array_filter($parts, fn (array $part): bool => ! $this->markedAttachment($part));

        return (reset($inline) ?: reset($parts)) ?: null;
    }

    /** @param  array<string, mixed>  $part */
    private function markedAttachment(array $part): bool
    {
        return str_starts_with(strtolower($this->partHeader($part, 'Content-Disposition')), 'attachment');
    }

    /** @param  array<string, mixed>  $part */
    private function partHeader(array $part, string $name): string
    {
        foreach ((array) ($part['headers'] ?? []) as $header) {
            if (strcasecmp((string) ($header['name'] ?? ''), $name) === 0) {
                return (string) ($header['value'] ?? '');
            }
        }

        return '';
    }

    /**
     * Part of the page, like a vendor's logo, rather than a document. A photo
     * of a receipt often carries a Content-ID too, so that alone proves nothing.
     *
     * @param  array<string, mixed>  $part
     * @param  list<string>  $referenced
     */
    private function isEmbedded(array $part, array $referenced): bool
    {
        return str_starts_with(strtolower((string) ($part['mimeType'] ?? '')), 'image/')
            && ! $this->markedAttachment($part)
            && (int) ($part['body']['size'] ?? 0) < self::EMBEDDED_IMAGE_LIMIT
            && in_array(trim($this->partHeader($part, 'Content-ID'), ' <>'), $referenced, true);
    }

    /**
     * The Content-IDs the html body points at. RFC 2392 percent-encodes them
     * inside a cid: URL.
     *
     * @return list<string>
     */
    private function referencedContentIds(): array
    {
        preg_match_all('/\bcid:([^\s"\'<>()]+)/i', html_entity_decode((string) $this->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches);

        return array_map(rawurldecode(...), $matches[1]);
    }
}
