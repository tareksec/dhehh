<?php

namespace App\Support;

class StorefrontUrl
{
    /**
     * Route name "families" that exist under the /store/{resellerSlug}/...
     * group (routes/web.php). Every other family falls back to the plain
     * main-store name so a reseller-store visitor who reaches checkout,
     * account or offer pages keeps working the way it does today —
     * ResellerPricing keeps the price/commission attribution across those
     * pages via the session, independent of which URL family is used.
     */
    protected const RESELLER_STORE_FAMILIES = [
        'show',
        'products.index',
        'categories.show',
        'products.show',
        'cart.show',
        'cart.add',
        'cart.update',
        'cart.remove',
        'checkout.show',
        'checkout.store',
    ];

    /**
     * Build a storefront URL for the given route "family" (the part of the
     * route name after "storefront." / "storefront.preview."), preferring
     * — in order — a staff preview, the current reseller's mini-store, then
     * the main store.
     *
     * @param  array<int|string, mixed>  $parameters  Positional/named params, same shape as route()'s main-store call today.
     */
    public static function route(string $family, array $parameters = [], ?string $previewSlug = null): string
    {
        $previewSlug ??= self::currentPreviewSlug();

        if ($previewSlug) {
            return route('storefront.preview.'.$family, self::prepend($previewSlug, $parameters));
        }

        $resellerSlug = self::currentResellerSlug();

        // On the reseller's own subdomain the host already names the store, so
        // the plain routes ("/products", "/cart", ...) are the right URLs.
        if ($resellerSlug && ! self::onResellerHost() && in_array($family, self::RESELLER_STORE_FAMILIES, true)) {
            return route('storefront.reseller_store.'.$family, self::prepend($resellerSlug, $parameters));
        }

        // The reseller's store front page is the product list ("show" only
        // exists under /store/{slug}).
        if ($family === 'show' && $resellerSlug && self::onResellerHost()) {
            return route('storefront.products.index');
        }

        return route('storefront.'.$family, $parameters);
    }

    public static function onResellerHost(): bool
    {
        return (bool) request()?->attributes->get('storefront_reseller_host');
    }

    public static function currentPreviewSlug(): ?string
    {
        return request()?->route('company')?->slug;
    }

    public static function currentResellerSlug(): ?string
    {
        $request = request();

        if (! $request) {
            return null;
        }

        $reseller = $request->attributes->get('storefront_reseller');

        return $reseller?->reseller_slug ?? $request->route('resellerSlug');
    }

    /**
     * @param  array<int|string, mixed>  $parameters
     * @return array<int|string, mixed>
     */
    protected static function prepend(string $slug, array $parameters): array
    {
        if (array_is_list($parameters)) {
            return [$slug, ...$parameters];
        }

        return ['slug' => $slug, ...$parameters];
    }
}
