<?php

namespace App\Support\StorefrontApi;

use Filament\Forms\Components\RichEditor\RichContentRenderer;

/**
 * Admin-written content (product descriptions, pages) comes either as rich
 * HTML or as plain paragraphs. The Blade storefront renders HTML through
 * Filament's sanitising RichContentRenderer and escapes plain text; this does
 * exactly the same so the Next.js site can inject the result safely.
 */
final class RichText
{
    public static function html(?string $content): string
    {
        $content = trim((string) $content);

        if ($content === '') {
            return '';
        }

        if (str_contains($content, '<')) {
            return (string) RichContentRenderer::make($content)->toHtml();
        }

        return collect(preg_split("/\r\n|\n|\r/", $content))
            ->map(fn (string $paragraph): string => trim($paragraph))
            ->filter()
            ->map(fn (string $paragraph): string => '<p>'.e($paragraph).'</p>')
            ->implode("\n");
    }

    public static function text(?string $content): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $content))) ?? '');
    }
}
