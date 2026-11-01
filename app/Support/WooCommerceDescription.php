<?php

namespace App\Support;

use Dom\HTMLDocument;
use Dom\Node;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Throwable;

/**
 * Turns a WooCommerce product description into the HTML the product form's
 * rich editor stores and the storefront renders, so headings, lists, bold,
 * links, tables, images and line breaks survive the import.
 *
 * The REST API returns the raw post content: WordPress adds the paragraph
 * tags only when it displays the page (wpautop), so text separated by blank
 * lines arrives without any <p>. This adds them back, then runs the result
 * through the same sanitising renderer the storefront uses (scripts, event
 * handlers and tags the editor does not know are dropped).
 */
final class WooCommerceDescription
{
    private const BLOCK_TAGS = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'div', 'dl', 'dt', 'fieldset',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr',
        'iframe', 'li', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot',
        'th', 'thead', 'tr', 'ul',
    ];

    public static function toHtml(?string $raw): ?string
    {
        $html = str_replace(["\r\n", "\r"], "\n", trim((string) $raw));

        if ($html === '') {
            return null;
        }

        // Gutenberg block comments, scripts, styles and [caption] wrappers are
        // never content.
        $html = preg_replace(
            ['/<!--.*?-->/s', '#<(script|style)\b.*?</\1\s*>#is', '/\[\/?caption[^\]]*\]/i'],
            '',
            $html,
        ) ?? $html;

        $paragraphed = self::addParagraphs($html);

        if ($paragraphed === '') {
            return null;
        }

        try {
            $clean = trim((string) RichContentRenderer::make($paragraphed)->toHtml());
        } catch (Throwable) {
            // Markup the editor cannot parse at all (for example an empty <div>):
            // keep the words rather than failing the whole import.
            $text = trim(preg_replace('/[ \t]+/', ' ', strip_tags($paragraphed)) ?? '');

            return $text !== '' ? '<p>'.e($text).'</p>' : null;
        }

        return $clean !== '' ? $clean : null;
    }

    /** What the importer used to store: the tags removed, nothing else changed. */
    public static function legacyPlainText(?string $source): ?string
    {
        $text = trim(strip_tags((string) $source));

        return $text !== '' ? $text : null;
    }

    /**
     * A value that stays the same when only punctuation, spacing, line breaks
     * or HTML entities differ (WordPress turns quotes into curly quotes and
     * "&" into "&#038;" when it shows a page). Used to recognise an
     * unedited import, never to display anything.
     */
    public static function fingerprint(?string $value): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '', $text));
    }

    /**
     * Decides whether a product's stored description may be replaced by the
     * formatted version of the WordPress text. Only a description nobody has
     * touched is replaced: empty (when `$fillBlank`), or still exactly the
     * plain text the old importer saved. Anything written or saved in the
     * editor (it contains HTML) or typed by hand stays as it is.
     *
     * Reasons: no-source, fill, blank, repair, already-fixed, formatted,
     * nothing-to-fix, edited.
     *
     * @return array{action: 'update'|'keep', reason: string, html: ?string}
     */
    public static function decide(?string $current, ?string $source, bool $fillBlank = false): array
    {
        $html = self::toHtml($source);

        // Nothing a visitor can read (empty, or only a non-breaking space).
        $visible = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($html === null || trim(str_replace("\u{00A0}", ' ', $visible)) === '') {
            return ['action' => 'keep', 'reason' => 'no-source', 'html' => null];
        }

        $current = trim((string) $current);

        if ($current === '') {
            return $fillBlank
                ? ['action' => 'update', 'reason' => 'fill', 'html' => $html]
                : ['action' => 'keep', 'reason' => 'blank', 'html' => $html];
        }

        if (str_contains($current, '<')) {
            return ['action' => 'keep', 'reason' => $current === $html ? 'already-fixed' : 'formatted', 'html' => $html];
        }

        // One plain paragraph: formatting would change nothing.
        if ($html === '<p>'.htmlspecialchars($current, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>') {
            return ['action' => 'keep', 'reason' => 'nothing-to-fix', 'html' => $html];
        }

        if (self::fingerprint($current) === self::fingerprint($source)) {
            return ['action' => 'update', 'reason' => 'repair', 'html' => $html];
        }

        return ['action' => 'keep', 'reason' => 'edited', 'html' => $html];
    }

    /** Wraps the loose text between block elements into paragraphs, like wpautop. */
    private static function addParagraphs(string $html): string
    {
        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>'.$html.'</body></html>',
            LIBXML_NOERROR,
            'UTF-8',
        );

        $output = '';
        $loose = '';

        $flush = function () use (&$output, &$loose): void {
            foreach (preg_split('/\n[ \t]*\n+/', trim($loose)) ?: [] as $paragraph) {
                $paragraph = trim($paragraph);

                if ($paragraph !== '') {
                    // A newline right after an explicit <br> is just source formatting.
                    $paragraph = preg_replace('#(<br\s*/?>)[ \t]*\n#i', '$1', $paragraph) ?? $paragraph;
                    $output .= '<p>'.str_replace("\n", '<br>', $paragraph).'</p>';
                }
            }

            $loose = '';
        };

        /** @var Node $node */
        foreach ($document->body->childNodes as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) {
                continue;
            }

            if ($node->nodeType === XML_ELEMENT_NODE && in_array(strtolower($node->nodeName), self::BLOCK_TAGS, true)) {
                $flush();
                $output .= $document->saveHtml($node);

                continue;
            }

            $loose .= $document->saveHtml($node);
        }

        $flush();

        return $output;
    }
}
