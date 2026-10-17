<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The starter home page for the Next.js site (owner request 2026-10-14: "arrange the home page like
 * zamzamint.netlify.app, every section — doing it by hand in the admin takes too long").
 *
 * It is the owner's reference home page written as Home Page Builder sections: hero, trust strip,
 * shop by category, best-deals banner, latest products, flash deal, four category rows, the
 * WhatsApp section and the about text. The copy is the reference site's own wording; the owner
 * edits every word afterwards in the Home Page Builder, which is where this layout is loaded
 * (nothing is saved until the owner presses Save). It is never applied behind the owner's back:
 * a site that has not saved a layout keeps the copy-free default (StorefrontSiteDesign).
 *
 * Categories are matched to the company's real ones by name, so a category the company does not
 * have is simply left out (and a row left with no category is dropped).
 */
final class StorefrontHomeStarter
{
    /**
     * The reference's categories, in its order: key => [names to match (letters and digits only,
     * "&" read as "and"), tagline, icon]. The tagline and icon are only used to fill the blank ones.
     */
    private const CATEGORIES = [
        'gadgets' => [['gadgets'], 'Accessories · Audio · Fan', 'heroicon-o-cpu-chip'],
        'audio' => [['audio'], 'Earbuds · TWS · Headsets', 'heroicon-o-speaker-wave'],
        'fan' => [['fan'], 'Solar Fan · Turbo Fan', 'heroicon-o-sun'],
        'kitchen' => [['kitchen'], 'Air Fryer · Cooker', 'heroicon-o-cake'],
        'cosmetics' => [['cosmetics'], 'Lipstick · Beauty', 'heroicon-o-sparkles'],
        'gift_crafts' => [['giftandcrafts', 'giftcrafts', 'giftsandcrafts'], 'DIY Kits · Necklace', 'heroicon-o-gift'],
        'home_decor' => [['homedecor'], 'Decor · Lighting', 'heroicon-o-light-bulb'],
        'accessories' => [['accessories'], 'Cables · Chargers', 'heroicon-o-bolt'],
        'earbuds' => [['eearbuds', 'earbuds'], 'TWS · Sports', 'heroicon-o-musical-note'],
        'solar_fan' => [['solarfan'], 'Rechargeable · Outdoor', 'heroicon-o-sun'],
        'air_fryer' => [['airfryer'], 'Kitchen · Electric', 'heroicon-o-fire'],
        'cooker' => [['cooker', 'cocker'], 'Kitchen · Home', 'heroicon-o-home'],
        'lipstick' => [['lipstick'], 'Cosmetics · Shades', 'heroicon-o-paint-brush'],
        'diy_necklace_kit' => [['diynecklacekit'], 'Crafts · DIY', 'heroicon-o-heart'],
    ];

    /** The four rows under the flash deal: heading => the categories whose products it mixes. */
    private const CATEGORY_ROWS = [
        'Best Audio Picks' => ['audio', 'earbuds'],
        'Fans & Cooling' => ['fan', 'solar_fan'],
        'Kitchen Essentials' => ['kitchen', 'air_fryer', 'cooker'],
        'Beauty & Lifestyle' => ['cosmetics', 'lipstick', 'gift_crafts', 'diy_necklace_kit', 'home_decor'],
    ];

    /**
     * The sections, in the shape the Home Page Builder keeps them (`[{type, data}]`).
     *
     * @param  Collection<int, Category>  $categories  the company's categories
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public static function sections(Collection $categories): array
    {
        $sections = [
            ['type' => 'hero', 'data' => ['autoplay_seconds' => 5, 'show_dots' => true, 'show_arrows' => false, 'height' => 'standard']],
            ['type' => 'trust_strip', 'data' => ['items' => [
                ['icon' => 'truck', 'title' => 'Fast Delivery', 'subtitle' => 'Across Bangladesh'],
                ['icon' => 'wallet', 'title' => 'Easy Payment', 'subtitle' => 'COD · bKash · Nagad'],
                ['icon' => 'cpu', 'title' => 'Smart Gadgets', 'subtitle' => 'Daily tech picks'],
                ['icon' => 'badge_check', 'title' => 'Quality Check', 'subtitle' => 'Practical & reliable'],
            ]]],
            // A blank heading is the website's own "Shop by category" in English and Bangla.
            ['type' => 'category_grid', 'data' => [
                'category_ids' => self::idsFor(array_keys(self::CATEGORIES), $categories),
                'limit' => 14,
                'style' => 'tiles',
                'show_tagline' => true,
                'show_count' => false,
            ]],
            ['type' => 'deals_banner', 'data' => [
                'title' => 'Best Deals Today',
                'subtitle' => 'Real discounts from our live catalog',
                'link_label' => 'View all promotions',
                'source' => 'top_discount',
                'limit' => 4,
                'color_from' => '#0E9F8A',
                'color_to' => '#1D4ED8',
            ]],
            ['type' => 'product_row', 'data' => ['title' => 'Latest Gadgets', 'source' => 'latest', 'limit' => 12, 'columns' => 6]],
            ['type' => 'flash_sale', 'data' => [
                'badge' => 'Flash Deal',
                'title' => "Today's Top Picks",
                'description' => 'Fresh picks, deep discounts — while stock lasts.',
                'countdown_label' => 'Deal ends in',
                'button_label' => 'Shop the Deal',
                'source' => 'flash_sale',
                'limit' => 4,
                'show_countdown' => true,
                'background' => '#0C2140',
            ]],
        ];

        foreach (self::CATEGORY_ROWS as $heading => $keys) {
            if (($ids = self::idsFor($keys, $categories)) !== []) {
                $sections[] = ['type' => 'product_row', 'data' => ['title' => $heading, 'source' => 'category', 'category_ids' => $ids, 'limit' => 6, 'columns' => 6]];
            }
        }

        $sections[] = ['type' => 'mobile_cta', 'data' => [
            'eyebrow' => 'Shop on mobile',
            'heading' => 'Order Easily on WhatsApp',
            'text' => 'Chat with us, send your product list, pay on delivery — fast human support in Bangla.',
            'whatsapp_label' => 'Chat on WhatsApp',
            'mockup_button_label' => 'Chat to Order',
            'background' => '#F6F9FC',
        ]];
        $sections[] = ['type' => 'seo_text', 'data' => [
            'heading' => 'Wholesale Gadgets in Bangladesh',
            'collapsed_lines' => 3,
            'body' => implode("\n\n", [
                '{company_name} is a wholesale gadget supplier based in Uttara, Dhaka — {address}. We supply original gadgets and accessories to resellers and retailers across Bangladesh, with a catalog of {products_count} products across {categories_count} categories: audio, fans, kitchen appliances, cosmetics, gift & crafts, home decor and more.',
                'Our B2B model is simple: a minimum order quantity of 12 pieces per product, transparent wholesale pricing in BDT, and dedicated support on WhatsApp at +8801325224400. Every order is quality-checked before dispatch, and we deliver all over Bangladesh with cash-on-delivery and bKash/Nagad payment options.',
                'Whether you run a retail shop, an online page, or a corporate gifting business, {company_name} helps you source fast-moving gadgets — from TWS earbuds and turbo fans to solar fans and air fryers — at prices that keep your margins healthy.',
            ]),
        ]];

        return StorefrontSiteDesign::homeSections($sections, forForm: true);
    }

    /**
     * The reference's top bar quick links (the thin strip above the header).
     *
     * @return list<array<string, mixed>>
     */
    public static function topBarLinks(): array
    {
        return [
            ['label' => 'Mobile App', 'type' => 'custom', 'url' => '/#mobile-app', 'icon' => 'smartphone', 'new_tab' => false],
            ['label' => 'Track Order', 'type' => 'track', 'icon' => 'truck', 'new_tab' => false],
        ];
    }

    /**
     * What a category is missing that the reference gives it: only the blank tagline and the blank
     * icon, never one the owner already chose.
     *
     * @param  Collection<int, Category>  $categories
     * @return array<int, array{tagline?: string, icon?: string}> category id => the fields to fill
     */
    public static function categoryFills(Collection $categories): array
    {
        $fills = [];

        foreach (self::CATEGORIES as [$names, $tagline, $icon]) {
            foreach ($categories->filter(fn (Category $category): bool => in_array(self::normalise($category->name), $names, true)) as $category) {
                $fill = array_filter([
                    'tagline' => blank($category->tagline) ? $tagline : null,
                    'icon' => blank($category->icon) ? $icon : null,
                ]);

                if ($fill !== []) {
                    $fills[$category->getKey()] = $fill;
                }
            }
        }

        return $fills;
    }

    /**
     * The company's category ids for some reference categories, in the reference's order.
     *
     * @param  list<string>  $keys
     * @param  Collection<int, Category>  $categories
     * @return list<int>
     */
    private static function idsFor(array $keys, Collection $categories): array
    {
        $ids = [];

        foreach ($keys as $key) {
            $names = self::CATEGORIES[$key][0];
            $match = $categories->first(fn (Category $category): bool => in_array(self::normalise($category->name), $names, true));

            if ($match) {
                $ids[] = (int) $match->getKey();
            }
        }

        return array_values(array_unique($ids));
    }

    /** "Gift & Crafts", "Gift and Crafts" and "gift-crafts"-style names all compare as letters and digits. */
    private static function normalise(?string $name): string
    {
        return Str::of((string) $name)->lower()->replace(['&amp;', '&'], 'and')->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
