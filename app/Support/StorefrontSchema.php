<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Product;
use App\Models\StorefrontSetting;

/**
 * schema.org structured data (JSON-LD) for the public storefront. Every value
 * comes from what the store already holds and what the page shows (name, price,
 * stock, the admin's contact details and social links), so nothing is invented.
 * No rating is emitted: the product page shows no reviews, and search engines
 * require review markup to match visible content.
 */
final class StorefrontSchema
{
    private const CONTEXT = 'https://schema.org';

    /** @return array<string, mixed> */
    public static function organization(Company $company, StorefrontSetting $setting, string $homeUrl, ?string $logoUrl): array
    {
        $profiles = collect($setting->social_links ?? [])
            ->filter(fn ($link): bool => is_array($link)
                && ($link['platform'] ?? null) !== 'whatsapp'
                && preg_match('#^https?://#i', (string) ($link['url'] ?? '')) === 1)
            ->pluck('url')
            ->unique()
            ->values()
            ->all();

        $contact = array_filter([
            '@type' => 'ContactPoint',
            'contactType' => 'customer service',
            'telephone' => filled($setting->phone_number) ? (string) $setting->phone_number : null,
            'email' => filled($setting->contact_email) ? (string) $setting->contact_email : null,
        ], fn ($value): bool => $value !== null);

        return self::clean([
            '@context' => self::CONTEXT,
            '@type' => 'Organization',
            'name' => $company->name,
            'url' => $homeUrl,
            'logo' => $logoUrl,
            'sameAs' => $profiles,
            'contactPoint' => count($contact) > 2 ? [$contact] : null,
        ]);
    }

    /** The site, with its product search so a result can offer a search box. @return array<string, mixed> */
    public static function website(string $name, string $homeUrl, string $productsUrl): array
    {
        return [
            '@context' => self::CONTEXT,
            '@type' => 'WebSite',
            'name' => $name,
            'url' => $homeUrl,
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $productsUrl.'?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * @param  list<array{name: string, url: string}>  $trail  From the home page down to the current page.
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $trail): array
    {
        return [
            '@context' => self::CONTEXT,
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($trail)->values()->map(fn (array $crumb, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ])->all(),
        ];
    }

    /**
     * A product with the price and stock the page shows. A variable product
     * carries its cheapest and dearest variant; with no price (0) the offer is
     * left out rather than published as free.
     *
     * @return array<string, mixed>
     */
    public static function product(Product $product, StorefrontProductPage $page, string $url, string $currency): array
    {
        $prices = $page->isVariableProduct && $page->variantData->isNotEmpty()
            ? $page->variantData->pluck('price')->map(fn ($price): float => (float) $price)
            : collect([$page->sellingPrice]);
        $prices = $prices->filter(fn (float $price): bool => $price > 0)->values();

        $availability = match (true) {
            $page->inStock || ($page->isVariableProduct && $page->orderable) => 'https://schema.org/InStock',
            $page->isPreorder => 'https://schema.org/PreOrder',
            default => 'https://schema.org/OutOfStock',
        };

        $offer = null;

        if ($prices->isNotEmpty()) {
            $low = (float) $prices->min();
            $high = (float) $prices->max();
            $offer = $low === $high
                ? ['@type' => 'Offer', 'price' => self::money($low)]
                : ['@type' => 'AggregateOffer', 'lowPrice' => self::money($low), 'highPrice' => self::money($high), 'offerCount' => $prices->count()];
            $offer += ['priceCurrency' => $currency, 'availability' => $availability, 'url' => $url];
        }

        return self::clean([
            '@context' => self::CONTEXT,
            '@type' => 'Product',
            'name' => $product->name,
            'description' => StorefrontSeo::excerpt($product->description, 500),
            'image' => $page->galleryImages->all(),
            'sku' => filled($product->sku) ? (string) $product->sku : null,
            'brand' => filled($product->brand) ? ['@type' => 'Brand', 'name' => (string) $product->brand] : null,
            'url' => $url,
            'offers' => $offer,
        ]);
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Drop empty values so the markup never carries blank fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function clean(array $data): array
    {
        return array_filter($data, fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
