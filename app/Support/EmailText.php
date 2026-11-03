<?php

namespace App\Support;

/** Small text helpers for email bodies: plain text from HTML (for the text part of a message and the list snippet) and HTML from plain text. */
final class EmailText
{
    /** The readable text of an HTML body: styles and scripts dropped, block ends become line breaks, entities decoded. */
    public static function fromHtml(string $html): string
    {
        $html = (string) preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<\s*(br|/p|/div|/tr|/li|/h[1-6]|/blockquote)\s*/?>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/[ \t]+/u", ' ', (string) preg_replace("/\n{3,}/", "\n\n", $text)));
    }

    /** Plain text shown as HTML: escaped, with line breaks kept. */
    public static function toHtml(string $text): string
    {
        return nl2br(e($text), false);
    }
}
