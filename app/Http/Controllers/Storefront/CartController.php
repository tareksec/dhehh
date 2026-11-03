<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ResellerProduct;
use App\Models\StorefrontCartRecord;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\ResellerPricing;
use App\Services\StorefrontCart;
use App\Services\StorefrontMetaTrackingService;
use App\Support\StorefrontThemeRegistry;
use App\Support\StorefrontUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(
        protected CompanyContext $context,
        protected StorefrontCart $cart,
        protected StorefrontMetaTrackingService $metaTracking,
    ) {}

    public function show(Request $request): View
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->cartView($company, $setting);
    }

    public function showPreview(Company $company): View
    {
        $setting = $this->previewStorefront($company);

        return $this->cartView($company, $setting, $company->slug);
    }

    public function add(Request $request): RedirectResponse
    {
        [$company] = $this->domainStorefront($request);
        $product = $this->product($this->routeSlug($request));

        return $this->addToCart($request, $company, $product);
    }

    public function addPreview(Request $request, Company $company, string $slug): RedirectResponse
    {
        $this->previewStorefront($company);
        $product = $this->product($slug);

        return $this->addToCart($request, $company, $product);
    }

    /**
     * Handles both simple products (single quantity) and variable products,
     * where multiple variants with individual quantities are added in one
     * submit via quantities[variant_id] inputs.
     */
    protected function addToCart(Request $request, Company $company, Product $product): RedirectResponse
    {
        $buyNow = $request->boolean('buy_now');
        $reseller = $this->resellerContext($request, $product);

        if (! $product->has_variants) {
            $quantity = max(1, (int) $request->integer('quantity', 1));
            $this->cart->add($company, $product, $quantity, reseller: $reseller);
            $this->flashAddToCartEvent($product, $quantity, $product->priceForQuantity($quantity) * $quantity);

            if ($buyNow) {
                return $this->redirectToCheckout($request, $company);
            }

            return back()->with('storefront_status', __(':name added to cart.', ['name' => $product->name]));
        }

        $quantities = collect((array) $request->input('quantities', []))
            ->map(fn ($quantity): int => max(0, (int) $quantity))
            ->filter(fn (int $quantity): bool => $quantity > 0);

        // Backwards-compatible: single variant + quantity inputs.
        if ($quantities->isEmpty() && $request->filled('variant')) {
            $quantities = collect([(int) $request->integer('variant') => max(1, (int) $request->integer('quantity', 1))]);
        }

        abort_if($quantities->isEmpty(), 422, __('Please select quantity for at least one option.'));

        $variants = ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->where('is_active', true)
            ->whereIn('id', $quantities->keys()->all())
            ->get()
            ->keyBy('id');

        abort_if($variants->isEmpty(), 422, __('Selected options are not available.'));

        $addedLines = 0;
        $addedQuantity = 0;
        $addedValue = 0.0;

        foreach ($quantities as $variantId => $quantity) {
            $variant = $variants->get((int) $variantId);

            // A variation the reseller has no selling price for is not for sale.
            if (! $variant || (int) $variant->stock < 1 || app(ResellerPricing::class)->variantBlocked($variant)) {
                continue;
            }

            $this->cart->add($company, $product, $quantity, $variant, $reseller);
            $addedLines++;
            $addedQuantity += $quantity;
            $addedValue += $variant->effectiveSalePrice() * $quantity;
        }

        abort_if($addedLines === 0, 422, __('Selected options are out of stock.'));
        $this->flashAddToCartEvent($product, $addedQuantity, $addedValue);

        if ($buyNow) {
            return $this->redirectToCheckout($request, $company);
        }

        return back()->with('storefront_status', $addedLines === 1
            ? __(':name added to cart (:count option).', ['name' => $product->name, 'count' => $addedLines])
            : __(':name added to cart (:count options).', ['name' => $product->name, 'count' => $addedLines]));
    }

    /**
     * When adding to cart under a reseller's store (/store/{slug}/...), the
     * product must actually be one that reseller picked -- rejects a
     * tampered request for a product outside their curated catalog -- and
     * returns the reseller so the cart record can be attributed to them.
     */
    protected function resellerContext(Request $request, Product $product): ?Customer
    {
        $reseller = $request->attributes->get('storefront_reseller');

        if (! $reseller instanceof Customer) {
            // Not under /store/{slug}: a visitor who came through a
            // reseller's store (remembered in their session) is still
            // attributed to that reseller for products in their catalog.
            $sessionReseller = app(ResellerPricing::class)->reseller();

            return $sessionReseller && ResellerProduct::query()
                ->where('customer_id', $sessionReseller->getKey())
                ->where('product_id', $product->getKey())
                ->where('is_active', true)
                ->exists()
                ? $sessionReseller
                : null;
        }

        $inCatalog = ResellerProduct::query()
            ->where('customer_id', $reseller->getKey())
            ->where('product_id', $product->getKey())
            ->where('is_active', true)
            ->exists();

        abort_unless($inCatalog, 404);

        return $reseller;
    }

    protected function redirectToCheckout(Request $request, Company $company): RedirectResponse
    {
        $previewSlug = $request->route('company')?->slug;

        return redirect()->to(StorefrontUrl::route('checkout.show', [], $previewSlug));
    }

    protected function flashAddToCartEvent(Product $product, int $quantity, float $value): void
    {
        session()->flash('storefront_meta_event', [
            'name' => 'AddToCart',
            'event_id' => $this->metaTracking->eventId('add-to-cart'),
            'data' => [
                'content_ids' => [(string) $product->getKey()],
                'content_type' => 'product',
                'currency' => 'BDT',
                'value' => round($value, 2),
                'num_items' => $quantity,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        [$company] = $this->domainStorefront($request);
        $product = $this->product($this->routeSlug($request));

        $this->cart->update($company, $product, (int) $request->integer('quantity'), $this->resolveVariant($request, $product));

        return back()->with('storefront_status', __('Cart updated.'));
    }

    public function updatePreview(Request $request, Company $company, string $slug): RedirectResponse
    {
        $this->previewStorefront($company);
        $product = $this->product($slug);

        $this->cart->update($company, $product, (int) $request->integer('quantity'), $this->resolveVariant($request, $product));

        return back()->with('storefront_status', __('Cart updated.'));
    }

    public function remove(Request $request): RedirectResponse
    {
        [$company] = $this->domainStorefront($request);
        $product = $this->product($this->routeSlug($request));
        $this->flashRemoveFromCartEvent($product);
        $this->cart->remove($company, $product, $this->resolveVariant($request, $product));

        return back()->with('storefront_status', __('Item removed from cart.'));
    }

    public function removePreview(Request $request, Company $company, string $slug): RedirectResponse
    {
        $this->previewStorefront($company);
        $product = $this->product($slug);
        $this->cart->remove($company, $product, $this->resolveVariant($request, $product));

        return back()->with('storefront_status', __('Item removed from cart.'));
    }

    protected function flashRemoveFromCartEvent(Product $product): void
    {
        session()->flash('storefront_meta_event', [
            'name' => 'RemoveFromCart',
            'event_id' => $this->metaTracking->eventId('remove-from-cart'),
            'data' => [
                'content_ids' => [(string) $product->getKey()],
                'content_type' => 'product',
                'currency' => 'BDT',
                'value' => round((float) $product->selling_price, 2),
            ],
        ]);
    }

    public function recover(Request $request, int $cart, string $token): RedirectResponse
    {
        [$company] = $this->domainStorefront($request);
        $record = StorefrontCartRecord::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->findOrFail($cart);

        abort_unless($record->hasValidRecoveryToken($token), 404);
        $this->cart->restore($company, $record);

        return redirect()->route('storefront.cart.show')
            ->with('storefront_status', __('Your saved cart has been restored. Review the items before checkout.'));
    }

    protected function resolveVariant(Request $request, Product $product, bool $requireForVariable = false): ?ProductVariant
    {
        $variantId = (int) $request->integer('variant');

        if ($variantId < 1) {
            abort_if($requireForVariable && $product->has_variants, 422, __('Please select an option first.'));

            return null;
        }

        $variant = ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->where('is_active', true)
            ->find($variantId);

        abort_unless($variant, 404);

        return $variant;
    }

    protected function cartView(Company $company, StorefrontSetting $setting, ?string $previewSlug = null): View
    {
        return StorefrontThemeRegistry::page('cart.show', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
            'items' => $this->cart->items($company),
            'subtotal' => $this->cart->subtotal($company),
        ]);
    }

    protected function domainStorefront(Request $request): array
    {
        $company = $request->attributes->get('storefront_company');

        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);

        $this->context->set($company);

        return [$company, $company->storefrontSetting];
    }

    protected function previewStorefront(Company $company): StorefrontSetting
    {
        abort_unless(app()->environment(['local', 'testing']) || auth()->check(), 404);

        // Same gate as PreviewController::previewCompany(): a signed-in staff
        // member may only preview their own company/companies, never another
        // company's by slug (set() below would otherwise switch the scope).
        if (auth()->check()) {
            abort_unless(auth()->user()->canAccessCompany($company->getKey()), 404);
        }

        $this->context->set($company);

        return $company->storefrontSetting ?: new StorefrontSetting([
            'company_id' => $company->getKey(),
            'theme_color' => '#F59E0B',
            'meta_title' => $company->name,
            'is_published' => true,
        ]);
    }

    /**
     * Reads the `{slug}` route parameter explicitly by name rather than via
     * method-injection: add()/update()/remove() are also mounted under
     * /store/{resellerSlug}/cart/items/{slug}, and Laravel resolves
     * unmatched scalar route parameters positionally, not by name -- the
     * extra leading {resellerSlug} segment would otherwise land in a
     * method-injected $slug argument instead of the actual product slug.
     */
    protected function routeSlug(Request $request): string
    {
        return (string) $request->route('slug');
    }

    protected function product(string $slug): Product
    {
        return Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->where(fn ($query) => $query
                ->where('stock', '>', 0)
                ->orWhere('is_preorder', true))
            ->firstOrFail();
    }
}
