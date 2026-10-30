<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Gmail;

use Carbon\CarbonImmutable;
use RobinsonRyan\Mailroom\Data\Attachment;

/**
 * One users.messages resource read: the part tree walked into body text and
 * attachment metadata. Only ever holds what messages.get returned, which for
 * attachments is an id, never the bytes.
 */
final readonly class GmailMessage
{
    /** @var list<array<string, mixed>> text parts with their data inline */
    private array $bodies;

    /** @var list<Attachment> */
    private array $attachments;

    /**
     * @param  array<string, mixed>  $resource
     */
    public function __construct(private array $resource)
    {
        [$this->bodies, $this->attachments] = $this->walk((array) ($resource['payload'] ?? []));
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

    /** The plain part, or the html reduced to text, cut to $limit characters. */
    public function summaryText(int $limit): string
    {
        return mb_substr($this->text() ?? $this->stripHtml((string) $this->html()), 0, $limit);
    }

    /**
     * A part with a filename and an attachmentId is an attachment, and what it
     * contains (an attached message's parts) belongs to it. Inline images have
     * no filename, so they count as neither body nor attachment.
     *
     * @param  array<string, mixed>  $part
     * @return array{list<array<string, mixed>>, list<Attachment>}
     */
    private function walk(array $part): array
    {
        $filename = (string) ($part['filename'] ?? '');
        $body = (array) ($part['body'] ?? []);
        $mime = strtolower((string) ($part['mimeType'] ?? ''));

        if ($filename !== '' && isset($body['attachmentId'])) {
            return [[], [new Attachment((string) $body['attachmentId'], $filename, $mime, (int) ($body['size'] ?? 0))]];
        }

        $bodies = in_array($mime, ['text/plain', 'text/html'], true) && isset($body['data']) ? [$part] : [];
        $attachments = [];

        foreach ((array) ($part['parts'] ?? []) as $child) {
            [$childBodies, $childAttachments] = $this->walk((array) $child);
            $bodies = [...$bodies, ...$childBodies];
            $attachments = [...$attachments, ...$childAttachments];
        }

        return [$bodies, $attachments];
    }

    /** The first part of this type, preferring inline parts over ones marked attachment. */
    private function body(string $mime): ?string
    {
        $parts = array_filter($this->bodies, fn (array $part): bool => strtolower((string) $part['mimeType']) === $mime);
        $inline = array_filter($parts, fn (array $part): bool => ! $this->markedAttachment($part));
        $part = reset($inline) ?: reset($parts);

        return $part === false ? null : mb_scrub(self::decode((string) $part['body']['data']), 'UTF-8');
    }

    /** @param  array<string, mixed>  $part */
    private function markedAttachment(array $part): bool
    {
        foreach ((array) ($part['headers'] ?? []) as $header) {
            if (strcasecmp((string) $header['name'], 'Content-Disposition') === 0) {
                return str_starts_with(strtolower((string) $header['value']), 'attachment');
            }
        }

        return false;
    }

    /**
     * Style and script contents are not tags, so strip_tags alone would leave
     * them as the "text"; a template's CSS can fill the whole typing limit.
     */
    private function stripHtml(string $html): string
    {
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
