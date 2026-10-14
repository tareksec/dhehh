<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Support\StorefrontProductPage;
use App\Support\StorefrontSchema;
use App\Support\StorefrontSeo;
use App\Support\StorefrontThemeRegistry;
use App\Support\StorefrontUrl;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductShowController extends Controller
{
    public function __invoke(Request $request): View
    {
        $company = $request->attributes->get('storefront_company');

        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);

        $reseller = $request->attributes->get('storefront_reseller');

        // See ProductIndexController::__invoke() for why $slug is read by
        // name rather than method-injected: this controller is also mounted
        // under /store/{resellerSlug}/product/{slug}, and Laravel resolves
        // unmatched scalar route parameters positionally, not by name.
        $slug = (string) $request->route('slug');

        $product = Product::query()
            ->with('category')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->when(
                $reseller instanceof Customer,
                fn ($query) => $query->whereIn('id', ResellerProduct::query()
                    ->where('customer_id', $reseller->getKey())
                    ->where('is_active', true)
                    ->select('product_id')),
            )
            ->firstOrFail();

        $related = Product::query()
            ->with('category')
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->where('id', '!=', $product->id)
            ->when($product->category_id, fn ($query) => $query->where('category_id', $product->category_id))
            ->when(
                $reseller instanceof Customer,
                fn ($query) => $query->whereIn('id', ResellerProduct::query()
                    ->where('customer_id', $reseller->getKey())
                    ->where('is_active', true)
                    ->select('product_id')),
            )
            ->latest()
            ->limit(4)
            ->get();

        $this->describe($company, $product, $reseller instanceof Customer);

        return StorefrontThemeRegistry::page('products.show', [
            'company' => $company,
            'setting' => $company->storefrontSetting,
            'product' => $product,
            'related' => $related,
        ]);
    }

    /**
     * The product's own title, description and photo, and its price and stock
     * as structured data. A reseller's store charges that reseller's prices,
     * so it keeps the page indexable but publishes no price markup.
     */
    protected function describe(Company $company, Product $product, bool $isResellerStore): void
    {
        $page = StorefrontProductPage::make($product, $company);
        $url = StorefrontUrl::route('products.show', [$product->slug]);
        $trail = [
            ['name' => __('Home'), 'url' => StorefrontUrl::home()],
            ['name' => __('All products'), 'url' => StorefrontUrl::route('products.index')],
        ];

        if ($product->category) {
            $trail[] = ['name' => $product->category->name, 'url' => StorefrontUrl::route('categories.show', [$product->category->slug])];
        }

        $trail[] = ['name' => $product->name, 'url' => $url];

        StorefrontSeo::current()
            ->page($product->name, StorefrontSeo::excerpt($product->description), $page->galleryImages->first())
            ->type('product')
            ->index()
            ->jsonLd(
                $isResellerStore ? [] : StorefrontSchema::product($product, $page, $url, $company->currency ?: 'BDT'),
                StorefrontSchema::breadcrumbs($trail),
            );
    }
}
