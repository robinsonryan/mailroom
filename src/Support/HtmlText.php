<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Support;

/** An html body reduced to its words, for typing a message that has no plain part. */
final class HtmlText
{
    /**
     * Style and script contents are not tags, so strip_tags alone would leave
     * them as the "text"; a template's CSS can fill the whole typing limit.
     */
    public static function from(string $html): string
    {
        $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
