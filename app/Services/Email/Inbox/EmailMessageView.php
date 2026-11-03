<?php

namespace App\Services\Email\Inbox;

use App\Models\Company;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;

/**
 * Turns a stored message into what the Email page puts on the screen (owner request 2026-11-02). Mail from strangers is
 * never trusted: the HTML (already cleaned when it was received) is shown in a frame of its own whose document carries a
 * content-security-policy that blocks scripts, forms, fonts and every remote picture until the person asks for them
 * (tracking pixels tell the sender that, and when, the mail was opened). Pictures that came inside the mail (`cid:`) are
 * put in as data, so they show without any remote request. Plain-text mail is escaped and kept as written.
 */
class EmailMessageView
{
    /** The most one inline picture, and all of them together, may weigh when put into the page, in bytes. */
    public const MAX_INLINE_IMAGE_BYTES = 1_500_000;

    public const MAX_INLINE_TOTAL_BYTES = 4_000_000;

    /** Inline pictures the page may embed; vector images can carry scripts and are not among them. */
    protected const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(protected EmailFiles $files) {}

    public function hasHtml(EmailMessage $message): bool
    {
        return filled($message->body_html);
    }

    /** Whether the HTML asks for pictures from the internet (so the page offers "Show pictures"). */
    public function hasRemoteImages(EmailMessage $message): bool
    {
        $html = (string) $message->body_html;

        return preg_match('~<img\b[^>]*\bsrc\s*=\s*["\']?\s*https?:~i', $html) === 1
            || preg_match('~url\(\s*["\']?\s*https?:~i', $html) === 1;
    }

    /** The plain text, escaped, with its line breaks. */
    public function text(EmailMessage $message): string
    {
        $text = filled($message->body_text) ? (string) $message->body_text : '';

        return nl2br(e($text), false);
    }

    /** The complete document for the message's frame (`srcdoc`). */
    public function document(EmailMessage $message, Company $company, bool $remoteImages): string
    {
        $body = $this->withInlineImages((string) $message->body_html, $message, $company);
        $images = $remoteImages ? 'data: https: http:' : 'data:';
        $policy = "default-src 'none'; img-src {$images}; style-src 'unsafe-inline'; form-action 'none'; base-uri 'none'";

        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta http-equiv="Content-Security-Policy" content="'.e($policy).'">'
            .'<meta name="color-scheme" content="light"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<base target="_blank">'
            .'<style>html{color-scheme:light}body{margin:0;padding:2px 0;background:#fff;color:#1b1b1a;font:14px/1.55 system-ui,-apple-system,"Segoe UI","Noto Sans Bengali",sans-serif;overflow-wrap:anywhere}'
            .'img{max-width:100%;height:auto}table{max-width:100%}a{color:#1f5fb0}'
            .'blockquote{margin:.5em 0 .5em .4em;padding-left:.9em;border-left:3px solid #d4d3cc;color:#52514e}</style>'
            .'</head><body>'.$body.'</body></html>';
    }

    /** `src="cid:..."` becomes the picture itself when it came with the mail; a picture that did not stays as it is (and shows nothing). */
    protected function withInlineImages(string $html, EmailMessage $message, Company $company): string
    {
        if (! str_contains(strtolower($html), 'cid:')) {
            return $html;
        }

        $pictures = $message->attachments()->where('is_inline', true)->whereNotNull('content_id')->get()
            ->filter(fn (EmailAttachment $file): bool => in_array(strtolower($file->mime), self::INLINE_TYPES, true) && $file->size <= self::MAX_INLINE_IMAGE_BYTES)
            ->keyBy(fn (EmailAttachment $file): string => strtolower(trim((string) $file->content_id, '<> ')));
        $budget = self::MAX_INLINE_TOTAL_BYTES;

        return (string) preg_replace_callback('~(\bsrc\s*=\s*)(["\'])cid:([^"\']+)\2~i', function (array $match) use ($pictures, $company, &$budget): string {
            $file = $pictures->get(strtolower(trim(rawurldecode($match[3]), '<> ')));

            if ($file === null || $file->size > $budget) {
                return $match[0];
            }

            $bytes = $this->files->read($file, $company);

            if ($bytes === null) {
                return $match[0];
            }

            $budget -= strlen($bytes);

            return $match[1].$match[2].'data:'.strtolower($file->mime).';base64,'.base64_encode($bytes).$match[2];
        }, $html);
    }
}
