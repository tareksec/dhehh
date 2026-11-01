<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Company;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Support\StorefrontApi\Media;
use App\Support\StorefrontSiteDesign;

/**
 * The site-wide configuration the headless Next.js storefront needs, built
 * from the company's Next.js StorefrontSetting row (site_type "nextjs"), which
 * the owner customises on its own in Site → Storefront Settings next to the
 * Blade (reseller) row. Colors, fonts and spacing come from the model's own resolvers
 * (themePalette(), headingFontFamily(), ...), never re-derived here.
 *
 * Customer-facing text is returned exactly as the admin entered it; this
 * service never supplies copy of its own (CLAUDE.md: no invented content).
 */
class StorefrontSiteConfigService
{
    public function __construct(protected Company $company, protected StorefrontSetting $setting) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $setting = $this->setting;

        return [
            'company' => [
                'name' => $this->company->name,
                'slug' => $this->company->slug,
                'currency' => $this->company->currency,
                'timezone' => $this->company->timezone,
            ],
            'sites' => [
                'blade_domain' => $this->company->domain,
                'nextjs_domain' => $setting->nextjs_site_domain,
                'is_published' => (bool) $setting->is_published,
            ],
            'branding' => [
                'logo_url' => Media::url($setting->logo, $this->company),
                'logo_dark_url' => Media::url($setting->logo_dark, $this->company),
                'favicon_url' => Media::url($setting->favicon, $this->company),
            ],
            'theme' => [
                'mode' => $setting->theme_mode,
                'colors' => $setting->themePalette(),
                'fonts' => [
                    'heading' => ['key' => $setting->typography_heading_font, 'family' => $setting->headingFontFamily()],
                    'body' => ['key' => $setting->typography_body_font, 'family' => $setting->bodyFontFamily()],
                    'google_fonts_url' => $setting->googleFontsUrl(),
                ],
                'typography' => [
                    'base_size' => $setting->typographyBaseSize(),
                    'heading_weight' => $setting->typographyHeadingWeight(),
                    'body_weight' => $setting->typographyBodyWeight(),
                    'line_height' => $setting->typographyLineHeight(),
                    'scale' => $setting->typographyScale(),
                    'heading_tracking' => $setting->typographyHeadingTracking(),
                    'body_tracking' => $setting->typographyBodyTracking(),
                    'content_width' => $setting->typographyContentWidth(),
                ],
                'appearance' => [
                    'container_width' => $setting->appearanceContainerWidth(),
                    'page_gutter' => $setting->appearancePageGutter(),
                    'corner_radii' => $setting->appearanceCornerRadii(),
                    'card_shadow' => [
                        'light' => $setting->appearanceCardShadow(),
                        'dark' => $setting->appearanceCardShadow(true),
                    ],
                    'motion_duration' => $setting->appearanceMotionDuration(),
                    'card_hover' => $setting->appearanceCardHover(),
                ],
            ],
            'contact' => [
                'whatsapp_number' => $setting->whatsapp_number,
                'phone_number' => $setting->phone_number,
                'email' => $setting->contact_email,
                'hours' => $setting->contact_hours,
                'helpline' => $setting->marketplace_helpline,
            ],
            'social_links' => collect($setting->social_links ?? [])
                ->map(fn ($link): array => ['platform' => $link['platform'] ?? null, 'url' => $link['url'] ?? null])
                ->filter(fn (array $link): bool => filled($link['platform']) && filled($link['url']))
                ->values()
                ->all(),
            'hero' => [
                'heading' => $setting->hero_heading,
                'subheading' => $setting->hero_subheading,
                'cta_label' => $setting->hero_cta_label,
            ],
            'announcement' => [
                'enabled' => (bool) $setting->marketplace_announcement_enabled,
                'text' => $setting->marketplace_announcement_text,
            ],
            'utility_bar' => [
                'enabled' => (bool) $setting->marketplace_utility_bar_enabled,
                'text' => $setting->marketplace_utility_bar_text,
            ],
            'campaign' => [
                'badge' => $setting->marketplace_campaign_badge,
                'heading' => $setting->marketplace_campaign_heading,
                'subheading' => $setting->marketplace_campaign_subheading,
                'cta_label' => $setting->marketplace_campaign_cta_label,
            ],
            'trust_items' => $setting->marketplaceTrustItems(),
            'offer_countdown' => $setting->hasActiveOffer() ? [
                'title' => $setting->offer_title,
                'discount_percent' => $setting->offer_discount_percent,
                'ends_at' => $setting->offer_ends_at?->toIso8601String(),
            ] : null,
            // Wholesale: the business-account call-out and the bulk-pricing table.
            'business' => [
                'quote_enabled' => (bool) $setting->marketplace_quote_enabled,
                'accounts_enabled' => (bool) $setting->marketplace_business_accounts_enabled,
                'heading' => $setting->marketplace_business_heading,
                'text' => $setting->marketplace_business_text,
                'bulk_pricing_enabled' => (bool) $setting->marketplace_bulk_pricing_enabled,
                'bulk_pricing_rows' => $setting->marketplaceBulkPricingRows(),
            ],
            'features' => [
                'customer_accounts_enabled' => (bool) $setting->customer_accounts_enabled,
                'reseller_program_enabled' => (bool) $setting->reseller_program_enabled,
            ],
            'menus' => [
                'header' => $this->menu($setting->header_menu),
                'footer' => $this->menu($setting->footer_menu),
            ],
            'footer_blocks' => $this->footerBlocks(),
            // The Next.js site's own design options: top bar, header, footer colours, floating help.
            'design' => $this->design(),
            // The Home Page Builder: the sections to show, in order, ids already resolved to slugs / URLs.
            'home_sections' => $this->homeSections(),
            'seo' => [
                'meta_title' => $setting->meta_title,
                'meta_description' => $setting->meta_description,
            ],
        ];
    }

    /**
     * Header/footer menu items with category and page ids resolved to slugs
     * (the website routes by slug). Items pointing at a category or page that
     * is gone, inactive or unpublished are dropped, like the Blade menus do.
     *
     * @param  array<int, array<string, mixed>>|null  $items
     * @return list<array<string, mixed>>
     */
    protected function menu(?array $items): array
    {
        $items = collect($items ?? []);
        $categories = Category::query()->where('is_active', true)
            ->whereIn('id', $items->pluck('category_id')->filter()->all())->pluck('slug', 'id');
        $pages = StorefrontPage::query()->published()->forSite($this->setting)
            ->whereIn('id', $items->pluck('page_id')->filter()->all())->pluck('slug', 'id');

        return $items->map(function (array $item) use ($categories, $pages): ?array {
            $type = (string) ($item['type'] ?? 'custom');
            $categorySlug = $type === 'category' ? $categories->get($item['category_id'] ?? null) : null;
            $pageSlug = $type === 'page' ? $pages->get($item['page_id'] ?? null) : null;

            if (($type === 'category' && ! $categorySlug) || ($type === 'page' && ! $pageSlug)) {
                return null;
            }

            return [
                'label' => (string) ($item['label'] ?? ''),
                'type' => $type,
                'category_slug' => $categorySlug,
                'page_slug' => $pageSlug,
                'url' => $type === 'custom' ? ($item['url'] ?? null) : null,
                'new_tab' => (bool) ($item['new_tab'] ?? false),
                'icon' => $item['icon'] ?? null,
            ];
        })->filter()->values()->all();
    }

    /**
     * Top bar, header, footer colours and the floating help button, with the
     * top bar's links resolved like the header/footer menus.
     *
     * @return array<string, mixed>
     */
    protected function design(): array
    {
        $design = $this->setting->siteDesign();
        $design['topbar']['links'] = $this->menu($design['topbar']['links']);

        return $design;
    }

    /**
     * The footer builder's blocks as the admin arranged them, each with clean
     * data (StorefrontSiteDesign::footerBlockData) and its ids resolved:
     * the bottom bar's legal links and a custom column's links to slugs, an
     * image to its URL, and the contact block's blanks to the company's own
     * contact details (never invented text).
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    protected function footerBlocks(): array
    {
        $blocks = collect($this->setting->footerBlocks())
            ->filter(fn ($block): bool => is_array($block) && array_key_exists((string) ($block['type'] ?? ''), StorefrontSetting::FOOTER_BLOCK_TYPES))
            ->map(fn (array $block): array => [
                'type' => (string) $block['type'],
                'data' => StorefrontSiteDesign::footerBlockData((string) $block['type'], is_array($block['data'] ?? null) ? $block['data'] : []),
            ]);

        $pageIds = $blocks->flatMap(fn (array $block) => collect($block['data']['legal_links'] ?? [])->pluck('page_id'))->filter()->all();
        $pages = StorefrontPage::query()->published()->forSite($this->setting)->whereIn('id', $pageIds)->get(['id', 'slug', 'title'])->keyBy('id');

        return $blocks->map(function (array $block) use ($pages): ?array {
            $type = $block['type'];
            $data = $block['data'];

            if ($type === 'bottom_bar') {
                $data['legal_links'] = collect($data['legal_links'])
                    ->map(fn (array $link): ?array => ($page = $pages->get($link['page_id']))
                        ? ['label' => $link['label'] ?? $page->title, 'page_slug' => $page->slug]
                        : null)
                    ->filter()
                    ->values()
                    ->all();
            }

            if ($type === 'custom_links') {
                $data['links'] = $this->menu($data['links']);
            }

            if ($type === 'brand_about') {
                $data['tagline'] ??= $this->setting->siteDesign()['header']['tagline'];
            }

            if ($type === 'contact_info') {
                $setting = $this->setting;
                $data['address'] ??= filled($this->company->address) ? trim((string) $this->company->address) : null;
                $data['email'] ??= $setting->contact_email ?: ($this->company->email ?: null);
                $data['phones'] = $data['phones'] ?: collect([$setting->phone_number, $setting->marketplace_helpline])
                    ->filter(fn ($phone) => filled($phone))
                    ->map(fn ($phone) => trim((string) $phone))
                    ->unique()
                    ->take(4)
                    ->values()
                    ->all();
                $data['hours'] = $data['show_hours'] ? ($setting->contact_hours ?: null) : null;
                unset($data['show_hours']);
            }

            if ($type === 'image') {
                $data['image_url'] = Media::url($data['image'], $this->company);
                unset($data['image']);

                if ($data['image_url'] === null) {
                    return null;
                }
            }

            return ['type' => $type, 'data' => $data];
        })->filter()->values()->all();
    }

    /**
     * The Home Page Builder's sections with ids turned into what the website
     * routes by (category slugs, image URLs). A section that would be empty
     * on the website (a row for a category that is gone, banners without an
     * image, text without any words) is left out here.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    protected function homeSections(): array
    {
        $sections = collect($this->setting->homeSections());

        $categoryIds = $sections->flatMap(fn (array $section): array => array_filter([
            ...($section['data']['category_ids'] ?? []),
            $section['data']['category_id'] ?? null,
        ]))->unique()->all();
        $categories = $categoryIds === []
            ? collect()
            : Category::query()->where('is_active', true)->whereIn('id', $categoryIds)->pluck('slug', 'id');

        return $sections->map(function (array $section) use ($categories): ?array {
            $type = $section['type'];
            $data = $section['data'];

            switch ($type) {
                case 'trust_strip':
                    $data['items'] = $data['items'] ?: $this->setting->marketplaceTrustItems();
                    break;

                case 'category_grid':
                    $data['category_slugs'] = collect($data['category_ids'])->map(fn (int $id) => $categories->get($id))->filter()->values()->all();
                    unset($data['category_ids']);
                    break;

                case 'product_row':
                    $data['category_slug'] = $data['source'] === 'category' ? $categories->get($data['category_id']) : null;
                    unset($data['category_id']);

                    if ($data['source'] === 'category' && $data['category_slug'] === null) {
                        return null;
                    }
                    break;

                case 'banners':
                    $data['items'] = collect($data['items'])
                        ->map(fn (array $item): ?array => ($url = Media::url($item['image'], $this->company))
                            ? ['image_url' => $url, 'alt' => $item['alt'], 'url' => $item['url'], 'new_tab' => $item['new_tab']]
                            : null)
                        ->filter()
                        ->values()
                        ->all();

                    if ($data['items'] === []) {
                        return null;
                    }
                    break;

                case 'mobile_cta':
                    if ($data['heading'] === null) {
                        return null;
                    }
                    break;

                case 'text':
                    if ($data['heading'] === null && $data['body'] === null) {
                        return null;
                    }
                    break;

                case 'seo_text':
                    if ($data['body'] === null) {
                        return null;
                    }

                    $data['heading'] = $this->fillTokens($data['heading']);
                    $data['body'] = $this->fillTokens($data['body']);
                    break;
            }

            return ['type' => $type, 'data' => $data];
        })->filter()->values()->all();
    }

    /**
     * {company_name}, {products_count}, {categories_count} and {address} in the
     * SEO text, so a sentence like "a catalog of {products_count} products
     * across {categories_count} categories" stays true as the catalog changes.
     */
    protected function fillTokens(?string $text): ?string
    {
        if ($text === null || ! str_contains($text, '{')) {
            return $text;
        }

        $catalog = new StorefrontCatalogService($this->company);
        $replacements = ['{company_name}' => $this->company->name, '{address}' => trim((string) $this->company->address)];

        if (str_contains($text, '{products_count}')) {
            $replacements['{products_count}'] = (string) $catalog->visibleProducts()->count();
        }

        if (str_contains($text, '{categories_count}')) {
            $replacements['{categories_count}'] = (string) collect($catalog->categories())->where('products_count', '>', 0)->count();
        }

        return strtr($text, $replacements);
    }
}
