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
    /** Where the standard reseller banner lives, under the ERP's public folder. */
    public const DEFAULT_RESELLER_BANNER = '/images/reseller-program-banner.png';

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
            'social_links' => $this->socialLinks(),
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
     * (the website routes by slug) and a "Become a reseller" item resolved to
     * the reseller portal's address. Items pointing at a category or page that
     * is gone, inactive or unpublished, or at a reseller program that is off,
     * are dropped, like the Blade menus do.
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

        // "Become a reseller" is not a page of the website: it is the reseller portal's guide (or
        // application form), as the Blade storefront links it. With the reseller module off, or no
        // portal address, the item is dropped like a menu item pointing at a category that is gone.
        $isReseller = $items->contains(fn (array $item): bool => ($item['type'] ?? null) === 'reseller');
        $resellerUrl = $isReseller ? $this->company->resellerJoinUrl() : null;
        $resellerBanner = $resellerUrl ? $this->resellerBannerUrl() : null;

        return $items->map(function (array $item) use ($categories, $pages, $resellerUrl, $resellerBanner): ?array {
            $type = (string) ($item['type'] ?? 'custom');
            $categorySlug = $type === 'category' ? $categories->get($item['category_id'] ?? null) : null;
            $pageSlug = $type === 'page' ? $pages->get($item['page_id'] ?? null) : null;

            if (($type === 'category' && ! $categorySlug) || ($type === 'page' && ! $pageSlug) || ($type === 'reseller' && ! $resellerUrl)) {
                return null;
            }

            return [
                'label' => (string) ($item['label'] ?? ''),
                'type' => $type,
                'category_slug' => $categorySlug,
                'page_slug' => $pageSlug,
                'url' => match ($type) {
                    'custom' => $item['url'] ?? null,
                    'reseller' => $resellerUrl,
                    default => null,
                },
                'new_tab' => (bool) ($item['new_tab'] ?? false),
                'icon' => $item['icon'] ?? null,
                // A "Become a reseller" item also carries the program banner, which the website's footer
                // shows as a button instead of a text link (menus without it ignore the key).
                'image_url' => $type === 'reseller' ? $resellerBanner : null,
            ];
        })->filter()->values()->all();
    }

    /**
     * The picture of the reseller program's call-to-action button: the one the admin uploaded in
     * Resellers → Reseller Settings, otherwise the standard "Reselling Program" banner that ships with the ERP.
     */
    public function resellerBannerUrl(): string
    {
        $uploaded = StorefrontSetting::bladeFor($this->company->getKey())?->reseller_banner_image;

        return Media::url($uploaded, $this->company) ?: url(self::DEFAULT_RESELLER_BANNER);
    }

    /** @return list<array{platform: mixed, url: mixed}> */
    protected function socialLinks(): array
    {
        return collect($this->setting->social_links ?? [])
            ->map(fn ($link): array => ['platform' => $link['platform'] ?? null, 'url' => $link['url'] ?? null])
            ->filter(fn (array $link): bool => filled($link['platform']) && filled($link['url']))
            ->values()
            ->all();
    }

    /**
     * The website's footer for a page that is not part of the website (the reseller landing page):
     * the same Footer Builder blocks, footer menu, social links and background colour, grouped into
     * columns the way the website draws them, with every link turned into an address that works from
     * another host. A link to a page of the website needs the website's domain, so without one only
     * absolute links stay; links to the reseller program itself are left out, because this footer
     * sits on it.
     *
     * @return array{name: string, logo_url: ?string, background: string, dark: bool, social_links: array, menu: array, columns: array, bottom: ?array, template: string}
     */
    public function footer(string $scheme = 'https'): array
    {
        $base = filled($this->setting->nextjs_site_domain) ? $scheme.'://'.$this->setting->nextjs_site_domain : null;
        $absolute = fn (?string $url): ?string => $url !== null && str_starts_with($url, '/') ? ($base ? $base.$url : null) : $url;
        $links = fn (array $items): array => collect($items)
            ->map(fn (array $item): ?array => ($href = $absolute($this->menuPath($item))) && ($item['type'] ?? null) !== 'reseller' && filled($item['label'] ?? null)
                ? ['label' => (string) $item['label'], 'href' => $href, 'new_tab' => (bool) ($item['new_tab'] ?? false)]
                : null)
            ->filter()
            ->values()
            ->all();

        $blocks = collect($this->footerBlocks())->map(function (array $block) use ($absolute, $links): array {
            $data = $block['data'];

            switch ($block['type']) {
                case 'custom_links':
                    $data['links'] = $links($data['links'] ?? []);
                    break;

                case 'custom_text':
                    $data['button_url'] = $absolute($data['button_url'] ?? null);
                    break;

                case 'image':
                    $data['url'] = $absolute($data['url'] ?? null);
                    break;

                case 'bottom_bar':
                    $data['center_url'] = $absolute($data['center_url'] ?? null);
                    $data['legal_links'] = collect($data['legal_links'] ?? [])
                        ->map(fn (array $link): array => ['label' => $link['label'], 'href' => $absolute('/pages/'.$link['page_slug'])])
                        ->filter(fn (array $link): bool => $link['href'] !== null)
                        ->values()
                        ->all();
                    break;
            }

            return ['type' => $block['type'], 'data' => $data];
        });

        // One column per "column" block; the small blocks (social, payments, text, image) ride along
        // under the previous one unless the admin gave them a column of their own.
        $columns = [];

        foreach ($blocks->reject(fn (array $block): bool => $block['type'] === 'bottom_bar') as $block) {
            $stacks = in_array($block['type'], ['social_links', 'custom_text', 'payment_methods', 'image'], true) && ! ($block['data']['own_column'] ?? false);

            if ($columns === [] || ! $stacks) {
                $columns[] = [$block];
            } else {
                $columns[array_key_last($columns)][] = $block;
            }
        }

        $background = $this->setting->siteDesign()['footer']['background'] ?? '#09090B';
        $template = count($columns) > 1
            ? collect($columns)->map(fn (array $column): string => collect($column)->contains(fn (array $block): bool => in_array($block['type'], ['brand_about', 'contact_info'], true)) ? 'minmax(0, 1.3fr)' : 'minmax(0, 1fr)')->implode(' ')
            : '1fr';

        return [
            'name' => $this->company->name,
            'logo_url' => Media::url($this->setting->logo, $this->company),
            'background' => $background,
            'dark' => $this->isDark($background),
            'social_links' => $this->socialLinks(),
            'menu' => $links($this->menu($this->setting->footer_menu)),
            'columns' => $columns,
            'bottom' => $blocks->first(fn (array $block): bool => $block['type'] === 'bottom_bar')['data'] ?? null,
            'template' => $template,
        ];
    }

    /** Where a menu item points on the website, as the website itself routes it (src/lib/nav.ts menuHref). */
    protected function menuPath(array $item): ?string
    {
        return match ($item['type'] ?? null) {
            'shop' => '/products',
            'category' => filled($item['category_slug'] ?? null) ? '/category/'.$item['category_slug'] : null,
            'page' => filled($item['page_slug'] ?? null) ? '/pages/'.$item['page_slug'] : null,
            'track' => '/track',
            'account' => '/account',
            'custom', 'reseller' => $item['url'] ?? null,
            default => null,
        };
    }

    /** True when white text reads better than black text on this hex colour (WCAG relative luminance, like the website). */
    protected function isDark(string $hex): bool
    {
        if (! preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', trim($hex), $m)) {
            return false;
        }

        [$r, $g, $b] = array_map(function (string $channel): float {
            $c = hexdec($channel) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, [$m[1], $m[2], $m[3]]);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b <= 0.179;
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
                    $slugs = $data['source'] === 'category'
                        ? collect($data['category_ids'])->map(fn (int $id) => $categories->get($id))->filter()->values()->all()
                        : [];
                    // `category_slug` (the first one) stays for websites built before a row could mix categories.
                    $data['category_slugs'] = $slugs;
                    $data['category_slug'] = $slugs[0] ?? null;
                    unset($data['category_ids']);

                    if ($data['source'] === 'category' && $slugs === []) {
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
