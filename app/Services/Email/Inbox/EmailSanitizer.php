<?php

namespace App\Services\Email\Inbox;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans the HTML of a received email before it is kept (owner request 2026-11-02): scripts, forms, frames,
 * objects, event handlers, `javascript:` links and `<style>` blocks are removed; formatting, tables, links
 * (opened in a new tab, `noopener`), pictures and inline `style` attributes stay. It is the first of two layers:
 * the Email page also shows the result in a sandboxed frame whose content-security-policy blocks scripts and,
 * unless the mailbox allows it, every remote picture. Written for mail from strangers: nothing here is trusted.
 */
class EmailSanitizer
{
    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        return $this->sanitizer()->sanitize($html);
    }

    protected function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowElement('font', ['color', 'face', 'size'])
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https', 'data', 'cid'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(2_000_000);

        // What mail layouts are made of: tables and inline styles.
        foreach (['style', 'align', 'valign', 'bgcolor', 'width', 'height', 'border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'color', 'dir'] as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }

        return new HtmlSanitizer($config);
    }
}
