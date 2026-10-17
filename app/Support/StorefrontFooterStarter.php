<?php

namespace App\Support;

use App\Models\StorefrontPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The starter footer for the Next.js site (owner request 2026-10-14: "design the footer too, like
 * the reference site").
 *
 * It is the footer of the owner's reference site (zamzamint.netlify.app) written as Footer Builder
 * blocks: the brand with its about text and social icons, the Quick links column, the Customer
 * service column, "Our Office" with the contact details and licence line, the payment badges and
 * the bottom bar. The wording is the reference's own; the owner edits every word afterwards in the
 * Footer Builder, which is where this is loaded (nothing is saved until the owner presses Save).
 *
 * Links to content pages (About, Contact, Delivery, Returns, Terms, Privacy) are matched to the
 * company's own published pages by title or slug; a link whose page does not exist yet is left out
 * and reported, never pointed at a page that would 404. The reference's "Request for Quote" and
 * "Reselling Program" links are not added: the Next.js site has no such pages yet.
 */
final class StorefrontFooterStarter
{
    /** The reference's page links: key => [names to match (letters and digits only), label shown]. */
    private const PAGE_LINKS = [
        'about' => [['aboutus', 'about'], 'About Us'],
        'contact' => [['contactus', 'contact'], 'Contact Us'],
        'delivery' => [['deliveryinformation', 'deliveryinfo', 'delivery', 'deliverypolicy', 'shippinginformation', 'shippingpolicy', 'shipping'], 'Delivery Information'],
        'returns' => [['returnrefundexchange', 'returnrefundandexchange', 'returnrefundpolicy', 'returnandrefundpolicy', 'refundandreturnpolicy', 'returnpolicy', 'refundpolicy', 'returnsrefunds', 'returns'], 'Return, Refund & Exchange'],
        'terms' => [['termsandconditions', 'termsconditions', 'termsofservice', 'termsofuse', 'terms'], 'Terms and Conditions'],
        'privacy' => [['privacypolicy', 'privacy'], 'Privacy Policy'],
    ];

    /** The reference's own contact details for "Our Office", written as the Footer Builder keeps them. */
    private const OFFICE = [
        'address' => "House-38 (Flat-1A), Road-5/A, Sector-5,\nUttara, Dhaka-1230, Bangladesh",
        'email' => 'info@zamzamint.com',
        'phones' => ['+8801325224400', '+8801894449445'],
        'trade_license' => 'Trade License: TRAD/DNCC/049335/2025',
    ];

    /**
     * The footer: its blocks, the Quick links menu, and the labels of the page links left out because
     * the company has no published page by that name.
     *
     * @param  Collection<int, StorefrontPage>  $pages  the company's published pages for the site
     * @return array{blocks: list<array{type: string, data: array<string, mixed>}>, menu: list<array<string, mixed>>, skipped: list<string>}
     */
    public static function layout(Collection $pages, string $companyName): array
    {
        $skipped = [];
        $page = function (string $key) use ($pages, &$skipped): ?array {
            [$names, $label] = self::PAGE_LINKS[$key];
            $match = $pages->first(fn (StorefrontPage $candidate): bool => in_array(self::normalise($candidate->title), $names, true) || in_array(self::normalise($candidate->slug), $names, true));

            if (! $match) {
                $skipped[] = $label;

                return null;
            }

            return self::link($label, 'page', pageId: (int) $match->getKey());
        };

        $menu = array_values(array_filter([
            self::link('Home', 'custom', url: '/'),
            self::link('Shop', 'shop'),
            $page('about'),
            $page('contact'),
            // The website has no promotions page; the discounted products list is what the reference's link shows.
            self::link('Promotions', 'custom', url: '/products?sort=discount&sale=1'),
        ]));

        $customerService = array_values(array_filter([$page('delivery'), $page('returns'), $page('terms'), $page('privacy')]));

        $blocks = [
            // The Bangla sentence is the reference's own; it lives in lang/bn.json, never in code.
            ['type' => 'brand_about', 'data' => ['show_logo' => false, 'text' => __('Quality gadgets at affordable prices for everyday needs — :name is your trusted online shop.', ['name' => $companyName], 'bn')]],
            ['type' => 'social_links', 'data' => []],
            ['type' => 'quick_links', 'data' => []],
            ['type' => 'custom_links', 'data' => ['heading' => 'Customer Service', 'links' => $customerService]],
            ['type' => 'contact_info', 'data' => ['heading' => 'Our Office'] + self::OFFICE],
            ['type' => 'payment_methods', 'data' => ['heading' => 'Safety Payments', 'items' => [
                ['label' => 'bKash', 'tone' => 'pink'],
                ['label' => 'Nagad', 'tone' => 'orange'],
                ['label' => 'Rocket', 'tone' => 'purple'],
                ['label' => 'Cards', 'tone' => 'neutral'],
            ]]],
            ['type' => 'bottom_bar', 'data' => [
                'copyright_text' => '© {year} {company_name}. All rights reserved.',
                'center_text' => 'Developed by Bright Hive Digital',
                'center_url' => 'https://brighthivedigital.com/',
                'right_text' => 'Developed with {heart} for Bangladesh',
            ]],
        ];

        return [
            'blocks' => array_map(fn (array $block): array => [
                'type' => $block['type'],
                'data' => StorefrontSiteDesign::footerBlockData($block['type'], $block['data']),
            ], $blocks),
            'menu' => $menu,
            'skipped' => $skipped,
        ];
    }

    /** One menu-style link in the shape the Footer Builder and the menu repeater keep it. */
    private static function link(string $label, string $type, ?int $pageId = null, ?string $url = null): array
    {
        return ['label' => $label, 'type' => $type, 'category_id' => null, 'page_id' => $pageId, 'url' => $url, 'new_tab' => false, 'icon' => null];
    }

    /** "Return, Refund & Exchange", "return-refund-and-exchange" and "Return Refund and Exchange" compare alike. */
    private static function normalise(?string $name): string
    {
        return Str::of((string) $name)->lower()->replace(['&amp;', '&'], 'and')->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
