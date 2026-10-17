<?php

namespace App\Support;

/**
 * What the owner arranges for the headless Next.js site in Site → Storefront
 * Settings (the Next.js row, tab "Website Design"): the Home Page Builder, the
 * top bar, header, floating help button, and the content of the extra Footer
 * Builder blocks.
 *
 * Everything is stored as plain JSON the admin typed or picked, so this class
 * is the one place that turns it into a clean, bounded shape before it leaves
 * the ERP: unknown section types are dropped, text is trimmed and cut to its
 * limit, numbers are clamped, colours must be #RRGGBB and links must be a
 * path, http(s), mailto: or tel: (never `javascript:`). It is pure — ids are
 * resolved to slugs / image URLs by StorefrontSiteConfigService.
 *
 * The default home layout reproduces the owner-approved design (2026-10-08):
 * hero, trust strip, shop by category, best-deals banner, latest products,
 * flash-sale band, trending and new arrivals. It carries no copy of its own —
 * a title left blank falls back to the website's interface text.
 */
final class StorefrontSiteDesign
{
    public const HOME_SECTION_TYPES = [
        'hero' => 'Hero slider',
        'trust_strip' => 'Trust strip',
        'category_grid' => 'Shop by category',
        'deals_banner' => 'Deals banner',
        'product_row' => 'Product row',
        'flash_sale' => 'Flash sale band',
        'banners' => 'Image banners',
        'mobile_cta' => 'Shop on mobile (WhatsApp / call)',
        'text' => 'Text block',
        'seo_text' => 'SEO text (read more)',
        'business_callout' => 'Business account callout',
    ];

    /** The layout every site starts with (and falls back to while nothing is saved). */
    public const DEFAULT_HOME_SECTIONS = [
        ['type' => 'hero', 'data' => []],
        ['type' => 'trust_strip', 'data' => []],
        ['type' => 'category_grid', 'data' => []],
        ['type' => 'deals_banner', 'data' => []],
        ['type' => 'product_row', 'data' => ['source' => 'latest', 'limit' => 12]],
        ['type' => 'flash_sale', 'data' => []],
        ['type' => 'product_row', 'data' => ['source' => 'trending', 'limit' => 6]],
        ['type' => 'product_row', 'data' => ['source' => 'new_arrivals', 'limit' => 6]],
    ];

    /** Where a product row / deals banner / flash band takes its products from. */
    public const PRODUCT_SOURCES = [
        'latest' => 'Latest products',
        'category' => 'Chosen categories',
        'top_discount' => 'Biggest discounts',
        'flash_sale' => 'Flash Sale collection',
        'trending' => 'Trending collection',
        'new_arrivals' => 'New Arrivals collection',
    ];

    public const HERO_HEIGHTS = [
        'compact' => 'Compact',
        'standard' => 'Standard',
        'tall' => 'Tall',
    ];

    public const TRUST_ICONS = [
        'truck' => 'Delivery truck',
        'refresh' => 'Return / replacement',
        'briefcase' => 'Business',
        'lock' => 'Secure payment',
        'shield' => 'Warranty / genuine',
        'phone' => 'Support / helpline',
        'wallet' => 'Payment / wallet',
        'cpu' => 'Gadgets / technology',
        'badge_check' => 'Quality check',
        'gift' => 'Gift',
        'star' => 'Star / rating',
        'clock' => 'Time / hours',
        'headset' => 'Customer support',
        'percent' => 'Discount',
        'map_pin' => 'Location',
        'heart' => 'Care',
    ];

    public const TEXT_TONES = [
        'plain' => 'Plain',
        'soft' => 'Soft background',
        'brand' => 'Brand colour',
    ];

    public const LINK_TYPES = [
        'shop' => 'Shop all products',
        'category' => 'Category',
        'page' => 'Content page',
        'offers' => 'Offers',
        'track' => 'Track order',
        'account' => 'My account',
        'reseller' => 'Become a reseller',
        'custom' => 'Custom URL',
    ];

    /** Optional small icon in front of a top bar link. */
    public const LINK_ICONS = [
        'none' => 'No icon',
        'smartphone' => 'Mobile app',
        'headset' => 'Customer support',
        'truck' => 'Delivery',
        'map_pin' => 'Location',
        'user' => 'Account',
        'tag' => 'Offers',
        'gift' => 'Gift',
        'phone' => 'Phone',
        'mail' => 'Email',
        'info' => 'Information',
    ];

    public const PAYMENT_TONES = [
        'neutral' => 'Neutral',
        'pink' => 'Pink',
        'orange' => 'Orange',
        'purple' => 'Purple',
        'blue' => 'Blue',
        'green' => 'Green',
        'red' => 'Red',
    ];

    public const DEFAULT_PRICE_COLOR = '#EA580C';

    public const DEFAULT_BADGE_COLOR = '#DC2626';

    public const DEFAULT_TOPBAR_BACKGROUND = '#0A1B33';

    public const DEFAULT_FOOTER_BACKGROUND = '#09090B';

    public const DEFAULT_FLASH_BACKGROUND = '#0D2141';

    public const DEFAULT_MOBILE_CTA_BACKGROUND = '#F5F9FC';

    // ------------------------------------------------------------------ home

    /**
     * The sections to show, in order, each with clean data. A section the
     * admin switched off is left out (`$forForm` keeps it, with its switch, so
     * the admin form can show it); an unset or empty builder means the default
     * layout.
     *
     * @param  array<int, mixed>|null  $stored
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public static function homeSections(?array $stored, bool $forForm = false): array
    {
        $blocks = $stored ?: self::DEFAULT_HOME_SECTIONS;
        $sections = [];

        foreach ($blocks as $block) {
            $type = is_array($block) ? (string) ($block['type'] ?? '') : '';
            $data = is_array($block) && is_array($block['data'] ?? null) ? $block['data'] : [];
            $enabled = self::bool($data['enabled'] ?? null, true);

            if (! array_key_exists($type, self::HOME_SECTION_TYPES) || (! $enabled && ! $forForm)) {
                continue;
            }

            $clean = self::homeSectionData($type, $data);
            // The admin form needs the switch itself (and every other key at its effective value).
            $sections[] = ['type' => $type, 'data' => $forForm ? ['enabled' => $enabled] + $clean : $clean];
        }

        return $sections;
    }

    /** @param  array<string, mixed>  $d */
    private static function homeSectionData(string $type, array $d): array
    {
        return match ($type) {
            'hero' => [
                'autoplay_seconds' => self::int($d['autoplay_seconds'] ?? null, 2, 20, 5),
                'show_dots' => self::bool($d['show_dots'] ?? null, true),
                'show_arrows' => self::bool($d['show_arrows'] ?? null, false),
                'height' => self::enum($d['height'] ?? null, array_keys(self::HERO_HEIGHTS), 'standard'),
            ],
            'trust_strip' => [
                'items' => self::trustItems($d['items'] ?? []),
            ],
            'category_grid' => [
                'title' => self::text($d['title'] ?? null, 80),
                'subtitle' => self::text($d['subtitle'] ?? null, 160),
                'category_ids' => self::ids($d['category_ids'] ?? [], 30),
                'limit' => self::int($d['limit'] ?? null, 1, 30, 12),
                'style' => self::enum($d['style'] ?? null, ['tiles', 'circles'], 'tiles'),
                'show_tagline' => self::bool($d['show_tagline'] ?? null, true),
                'show_count' => self::bool($d['show_count'] ?? null, false),
            ],
            'deals_banner' => [
                'title' => self::text($d['title'] ?? null, 80),
                'subtitle' => self::text($d['subtitle'] ?? null, 160),
                'link_label' => self::text($d['link_label'] ?? null, 40),
                'link_url' => self::url($d['link_url'] ?? null),
                'limit' => self::int($d['limit'] ?? null, 2, 8, 4),
                'source' => self::enum($d['source'] ?? null, ['top_discount', 'flash_sale', 'trending', 'new_arrivals'], 'top_discount'),
                'color_from' => self::color($d['color_from'] ?? null),
                'color_to' => self::color($d['color_to'] ?? null),
            ],
            'product_row' => [
                'title' => self::text($d['title'] ?? null, 80),
                'subtitle' => self::text($d['subtitle'] ?? null, 160),
                'source' => self::enum($d['source'] ?? null, array_keys(self::PRODUCT_SOURCES), 'latest'),
                // A row can mix several categories; `category_id` is what rows saved before that held.
                'category_ids' => self::ids([...(is_array($d['category_ids'] ?? null) ? $d['category_ids'] : []), $d['category_id'] ?? null], 8),
                'limit' => self::int($d['limit'] ?? null, 1, 24, 6),
                'columns' => (int) self::enum((string) ($d['columns'] ?? ''), ['4', '5', '6'], '6'),
                'link_label' => self::text($d['link_label'] ?? null, 40),
                'link_url' => self::url($d['link_url'] ?? null),
            ],
            'flash_sale' => [
                'badge' => self::text($d['badge'] ?? null, 40),
                'title' => self::text($d['title'] ?? null, 80),
                'description' => self::text($d['description'] ?? null, 200),
                'countdown_label' => self::text($d['countdown_label'] ?? null, 40),
                'button_label' => self::text($d['button_label'] ?? null, 40),
                'button_url' => self::url($d['button_url'] ?? null),
                'limit' => self::int($d['limit'] ?? null, 2, 6, 4),
                'source' => self::enum($d['source'] ?? null, ['flash_sale', 'top_discount', 'trending', 'new_arrivals'], 'flash_sale'),
                'show_countdown' => self::bool($d['show_countdown'] ?? null, true),
                'background' => self::color($d['background'] ?? null) ?? self::DEFAULT_FLASH_BACKGROUND,
            ],
            'banners' => [
                'columns' => self::int($d['columns'] ?? null, 1, 4, 2),
                'items' => self::bannerItems($d['items'] ?? []),
            ],
            'mobile_cta' => [
                'eyebrow' => self::text($d['eyebrow'] ?? null, 60),
                'heading' => self::text($d['heading'] ?? null, 120),
                'text' => self::text($d['text'] ?? null, 300),
                'whatsapp_label' => self::text($d['whatsapp_label'] ?? null, 40),
                'call_label' => self::text($d['call_label'] ?? null, 40),
                'show_whatsapp' => self::bool($d['show_whatsapp'] ?? null, true),
                'show_call' => self::bool($d['show_call'] ?? null, true),
                'show_mockups' => self::bool($d['show_mockups'] ?? null, true),
                'mockup_button_label' => self::text($d['mockup_button_label'] ?? null, 30),
                'background' => self::color($d['background'] ?? null) ?? self::DEFAULT_MOBILE_CTA_BACKGROUND,
            ],
            'text' => [
                'heading' => self::text($d['heading'] ?? null, 120),
                'body' => self::paragraph($d['body'] ?? null, 2000),
                'button_label' => self::text($d['button_label'] ?? null, 40),
                'button_url' => self::url($d['button_url'] ?? null),
                'align' => self::enum($d['align'] ?? null, ['left', 'center'], 'left'),
                'tone' => self::enum($d['tone'] ?? null, array_keys(self::TEXT_TONES), 'plain'),
            ],
            'seo_text' => [
                'heading' => self::text($d['heading'] ?? null, 120),
                'body' => self::paragraph($d['body'] ?? null, 3000),
                'collapsed_lines' => self::int($d['collapsed_lines'] ?? null, 2, 10, 3),
            ],
            default => [],
        };
    }

    /** @return list<array{icon: string, title: string, subtitle: ?string}> */
    private static function trustItems(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->map(fn ($item): ?array => is_array($item) && ($title = self::text($item['title'] ?? null, 60)) !== null ? [
                'icon' => self::enum($item['icon'] ?? null, array_keys(self::TRUST_ICONS), 'shield'),
                'title' => $title,
                'subtitle' => self::text($item['subtitle'] ?? null, 80),
            ] : null)
            ->filter()
            ->take(6)
            ->values()
            ->all();
    }

    /** @return list<array{image: string, alt: ?string, url: ?string, new_tab: bool}> */
    private static function bannerItems(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->map(fn ($item): ?array => is_array($item) && filled($item['image'] ?? null) && is_string($item['image']) ? [
                'image' => $item['image'],
                'alt' => self::text($item['alt'] ?? null, 120),
                'url' => self::url($item['url'] ?? null),
                'new_tab' => self::bool($item['new_tab'] ?? null, false),
            ] : null)
            ->filter()
            ->take(6)
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------- design

    /**
     * Top bar, header, footer colours, product card style and floating help button, with every
     * missing or invalid value replaced by its default.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array{topbar: array<string, mixed>, header: array<string, mixed>, footer: array<string, mixed>, product_card: array<string, mixed>, floating_help: array<string, mixed>}
     */
    public static function design(?array $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $top = self::section($stored, 'topbar');
        $header = self::section($stored, 'header');
        $footer = self::section($stored, 'footer');
        $help = self::section($stored, 'floating_help');
        $card = self::section($stored, 'product_card');

        return [
            'topbar' => [
                'enabled' => self::bool($top['enabled'] ?? null, true),
                'show_social' => self::bool($top['show_social'] ?? null, true),
                'message' => self::text($top['message'] ?? null, 160),
                'background' => self::color($top['background'] ?? null) ?? self::DEFAULT_TOPBAR_BACKGROUND,
                'links' => self::links($top['links'] ?? [], 6),
            ],
            'header' => [
                'tagline' => self::text($header['tagline'] ?? null, 80),
                'search_placeholder' => self::text($header['search_placeholder'] ?? null, 80),
                'show_search' => self::bool($header['show_search'] ?? null, true),
                'show_theme_toggle' => self::bool($header['show_theme_toggle'] ?? null, true),
                'show_account' => self::bool($header['show_account'] ?? null, true),
                'show_wishlist' => self::bool($header['show_wishlist'] ?? null, true),
                'show_support' => self::bool($header['show_support'] ?? null, true),
                'support_label' => self::text($header['support_label'] ?? null, 30),
                'support_phone' => self::text($header['support_phone'] ?? null, 40),
                'show_categories_menu' => self::bool($header['show_categories_menu'] ?? null, true),
                'categories_label' => self::text($header['categories_label'] ?? null, 30),
                'sticky' => self::bool($header['sticky'] ?? null, true),
            ],
            'footer' => [
                'background' => self::color($footer['background'] ?? null) ?? self::DEFAULT_FOOTER_BACKGROUND,
            ],
            'product_card' => [
                'price_color' => self::color($card['price_color'] ?? null) ?? self::DEFAULT_PRICE_COLOR,
                'badge_color' => self::color($card['badge_color'] ?? null) ?? self::DEFAULT_BADGE_COLOR,
                'show_rating' => self::bool($card['show_rating'] ?? null, true),
                'show_stock' => self::bool($card['show_stock'] ?? null, true),
                'show_wishlist' => self::bool($card['show_wishlist'] ?? null, true),
                'show_quick_view' => self::bool($card['show_quick_view'] ?? null, true),
                'show_add_to_cart' => self::bool($card['show_add_to_cart'] ?? null, true),
            ],
            'floating_help' => [
                'enabled' => self::bool($help['enabled'] ?? null, true),
                'title' => self::text($help['title'] ?? null, 40),
                'subtitle' => self::text($help['subtitle'] ?? null, 60),
                'url' => self::url($help['url'] ?? null),
            ],
        ];
    }

    // ---------------------------------------------------------------- footer

    /**
     * Clean data for one Footer Builder block. Keeps the keys the Blade
     * footer already reads (heading, show_logo, text, copyright_text,
     * legal_links — `legal_links` page ids are resolved by the caller).
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public static function footerBlockData(string $type, array $d): array
    {
        return match ($type) {
            'brand_about' => [
                'show_logo' => self::bool($d['show_logo'] ?? null, false),
                'text' => self::text($d['text'] ?? null, 500),
                'tagline' => self::text($d['tagline'] ?? null, 80),
            ],
            'quick_links' => [
                'heading' => self::text($d['heading'] ?? null, 100),
            ],
            'social_links' => [
                'heading' => self::text($d['heading'] ?? null, 100),
                'own_column' => self::bool($d['own_column'] ?? null, false),
            ],
            'contact_info' => [
                'heading' => self::text($d['heading'] ?? null, 100),
                'address' => self::paragraph($d['address'] ?? null, 300),
                'email' => self::text($d['email'] ?? null, 120),
                'phones' => self::lines($d['phones'] ?? null, 4, 40),
                'trade_license' => self::text($d['trade_license'] ?? null, 120),
                'show_hours' => self::bool($d['show_hours'] ?? null, false),
            ],
            'bottom_bar' => [
                'copyright_text' => self::text($d['copyright_text'] ?? null, 255),
                'center_text' => self::text($d['center_text'] ?? null, 160),
                'center_url' => self::url($d['center_url'] ?? null),
                'right_text' => self::text($d['right_text'] ?? null, 160),
                'legal_links' => collect(is_array($d['legal_links'] ?? null) ? $d['legal_links'] : [])
                    ->filter(fn ($link): bool => is_array($link) && filled($link['page_id'] ?? null))
                    ->map(fn (array $link): array => ['label' => self::text($link['label'] ?? null, 60), 'page_id' => self::id($link['page_id'])])
                    ->filter(fn (array $link): bool => $link['page_id'] !== null)
                    ->values()
                    ->all(),
            ],
            'custom_links' => [
                'heading' => self::text($d['heading'] ?? null, 100),
                'links' => self::links($d['links'] ?? [], 12),
            ],
            'custom_text' => [
                'heading' => self::text($d['heading'] ?? null, 100),
                'text' => self::paragraph($d['text'] ?? null, 1000),
                'button_label' => self::text($d['button_label'] ?? null, 40),
                'button_url' => self::url($d['button_url'] ?? null),
                'own_column' => self::bool($d['own_column'] ?? null, false),
            ],
            'payment_methods' => [
                'heading' => self::text($d['heading'] ?? null, 100),
                'own_column' => self::bool($d['own_column'] ?? null, false),
                'items' => collect(is_array($d['items'] ?? null) ? $d['items'] : [])
                    ->map(fn ($item): ?array => is_array($item) && ($label = self::text($item['label'] ?? null, 24)) !== null ? [
                        'label' => $label,
                        'tone' => self::enum($item['tone'] ?? null, array_keys(self::PAYMENT_TONES), 'neutral'),
                    ] : null)
                    ->filter()
                    ->take(8)
                    ->values()
                    ->all(),
            ],
            'image' => [
                'heading' => self::text($d['heading'] ?? null, 100),
                'image' => is_string($d['image'] ?? null) && filled($d['image']) ? $d['image'] : null,
                'alt' => self::text($d['alt'] ?? null, 120),
                'url' => self::url($d['url'] ?? null),
                'own_column' => self::bool($d['own_column'] ?? null, false),
            ],
            default => [],
        };
    }

    // --------------------------------------------------------------- helpers

    /**
     * Menu-style links (label + target). The target stays as ids / a URL here;
     * the site config service turns ids into slugs.
     *
     * @return list<array{label: string, type: string, category_id: ?int, page_id: ?int, url: ?string, new_tab: bool, icon: ?string}>
     */
    public static function links(mixed $items, int $max): array
    {
        return collect(is_array($items) ? $items : [])
            ->map(function ($item): ?array {
                if (! is_array($item) || ($label = self::text($item['label'] ?? null, 40)) === null) {
                    return null;
                }

                $type = self::enum($item['type'] ?? null, array_keys(self::LINK_TYPES), 'shop');
                $url = $type === 'custom' ? self::url($item['url'] ?? null) : null;

                if ($type === 'custom' && $url === null) {
                    return null;
                }

                return [
                    'label' => $label,
                    'type' => $type,
                    'category_id' => $type === 'category' ? self::id($item['category_id'] ?? null) : null,
                    'page_id' => $type === 'page' ? self::id($item['page_id'] ?? null) : null,
                    'url' => $url,
                    'new_tab' => self::bool($item['new_tab'] ?? null, false),
                    'icon' => ($icon = self::enum($item['icon'] ?? null, array_keys(self::LINK_ICONS), 'none')) === 'none' ? null : $icon,
                ];
            })
            ->filter()
            ->take($max)
            ->values()
            ->all();
    }

    /** A path, http(s), mailto: or tel: link — anything else (javascript:, data:, //host) is dropped. */
    public static function url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 500 || preg_match('/[\x00-\x1F\x7F\s]/', $value)) {
            return null;
        }

        $isPath = str_starts_with($value, '/') && ! str_starts_with($value, '//');

        return $isPath || str_starts_with($value, '#') || preg_match('#^(https?://|mailto:|tel:)#i', $value) ? $value : null;
    }

    public static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtoupper($value) : null;
    }

    /** @return array<string, mixed> */
    private static function section(array $stored, string $key): array
    {
        return is_array($stored[$key] ?? null) ? $stored[$key] : [];
    }

    /** Single-line text: trimmed, whitespace collapsed, cut to `$max` characters. */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Multi-line text: trimmed, line breaks kept, cut to `$max` characters. */
    private static function paragraph(mixed $value, int $max): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** @return list<string> */
    private static function lines(mixed $value, int $maxLines, int $maxLength): array
    {
        $lines = is_array($value) ? $value : preg_split('/\R/u', is_scalar($value) ? (string) $value : '');

        return collect($lines ?: [])
            ->map(fn ($line): ?string => self::text($line, $maxLength))
            ->filter()
            ->take($maxLines)
            ->values()
            ->all();
    }

    private static function bool(mixed $value, bool $default): bool
    {
        return $value === null || $value === '' ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function int(mixed $value, int $min, int $max, int $default): int
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    /** @param  list<string>  $allowed */
    private static function enum(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return list<int> */
    private static function ids(mixed $value, int $max): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn ($id): ?int => self::id($id))
            ->filter()
            ->unique()
            ->take($max)
            ->values()
            ->all();
    }
}
