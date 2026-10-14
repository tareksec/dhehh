<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * What a storefront page tells search engines and link previews about itself:
 * its own title and description, whether it may be indexed, its canonical
 * address, its share image and its structured data. A controller fills it in
 * (once, for every theme) and the storefront layout prints it.
 *
 * A page is NOT indexable until its controller says so with index(): the cart,
 * checkout, order tracking, account and every other private or transactional
 * page therefore stay out of search results without having to be listed.
 */
final class StorefrontSeo
{
    private ?string $title = null;

    private ?string $description = null;

    private ?string $image = null;

    private string $type = 'website';

    private ?string $canonical = null;

    /** The robots directive, or null when the page may be indexed. */
    private ?string $robots = 'noindex, nofollow';

    /** @var list<array<string, mixed>> */
    private array $jsonLd = [];

    /**
     * This request's SEO facts, shared by the controller that fills them in and
     * the layout that prints them. Kept on the request itself so one request's
     * facts can never leak into the next (a long-lived worker, or a test that
     * makes several requests).
     */
    public static function current(): self
    {
        $request = request();
        $seo = $request->attributes->get('storefront_seo');

        if (! $seo instanceof self) {
            $seo = new self;
            $request->attributes->set('storefront_seo', $seo);
        }

        return $seo;
    }

    /** The page's own name, shown before the site name in the tab and in results. */
    public function page(?string $title, ?string $description = null, ?string $image = null): self
    {
        $this->title = filled($title) ? trim((string) $title) : null;
        $this->description = filled($description) ? trim((string) $description) : null;
        $this->image = filled($image) ? $image : null;

        return $this;
    }

    /** Let search engines index this page. $canonical defaults to the address without its query string. */
    public function index(?string $canonical = null): self
    {
        $this->robots = null;
        $this->canonical = $canonical;

        return $this;
    }

    /** Keep this page out of results but let crawlers follow its links (sorted, filtered and later listing pages). */
    public function noindexFollow(): self
    {
        $this->robots = 'noindex, follow';

        return $this;
    }

    /** The Open Graph type: "website" (default) or "product". */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** @param  array<string, mixed>  ...$blocks */
    public function jsonLd(array ...$blocks): self
    {
        foreach ($blocks as $block) {
            if ($block !== []) {
                $this->jsonLd[] = $block;
            }
        }

        return $this;
    }

    public function isIndexable(): bool
    {
        return $this->robots === null;
    }

    public function robots(): ?string
    {
        return $this->robots;
    }

    public function documentTitle(string $siteTitle): string
    {
        if ($this->title === null || $this->title === $siteTitle) {
            return $siteTitle;
        }

        return $this->title.' | '.$siteTitle;
    }

    public function description(string $fallback): string
    {
        return $this->description ?? $fallback;
    }

    public function image(?string $fallback): ?string
    {
        return $this->image ?? $fallback;
    }

    public function ogType(): string
    {
        return $this->type;
    }

    public function canonicalUrl(): string
    {
        return $this->canonical ?? request()->url();
    }

    /** @return list<array<string, mixed>> */
    public function structuredData(): array
    {
        return $this->jsonLd;
    }

    /** Plain text for a meta description: tags removed, whitespace collapsed, cut at a word near $limit. */
    public static function excerpt(?string $html, int $limit = 160): ?string
    {
        // A space before every tag keeps "<p>a</p><p>b</p>" from reading "ab".
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string) $html)), ENT_QUOTES | ENT_HTML5)));

        if ($text === '') {
            return null;
        }

        return Str::limit($text, $limit, '…', preserveWords: true);
    }
}
