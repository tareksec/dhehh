<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCarousel;
use App\Models\StorefrontSlide;
use App\Support\StorefrontListingCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $company = $request->attributes->get('storefront_company');

        if (! $company instanceof Company) {
            // The app's own domain (e.g. app.zamzamint.com — the Filament admin
            // panel and the Android app both load this host) has no storefront
            // company; send it straight into /admin instead of the generic
            // marketing page. Any other unmatched host keeps the marketing page.
            if ($this->isAppOwnDomain($request)) {
                return redirect('/admin');
            }

            return view('marketing.home');
        }

        $setting = $company->storefrontSetting;

        abort_unless($setting?->is_published, 404);

        // Home page listings are identical for every visitor of this company
        // and change only when an admin edits a product/category/setting (all
        // of which bump this cache — see StorefrontListingCache), so caching
        // them keeps a busy storefront from re-running the same query on
        // every single page load.
        $listing = StorefrontListingCache::home($company->getKey(), fn (): array => [
            'categories' => Category::query()
                ->where('is_active', true)
                ->whereHas('products', fn ($query) => $query->where('is_active', true)->where('status', Product::STATUS_AVAILABLE))
                ->orderBy('name')
                ->take(8)
                ->get(),
            'products' => Product::query()
                ->with('category')
                ->where('is_active', true)
                ->where('status', Product::STATUS_AVAILABLE)
                ->latest()
                ->take(23)
                ->get(),
        ]);

        return view($setting->homepageView(), [
            'company' => $company,
            'setting' => $setting,
            'categories' => $listing['categories'],
            'products' => $listing['products'],
            'carousels' => ProductCarousel::forHomepage(),
            'slides' => StorefrontSlide::forCompanyTheme($company->getKey(), $setting->storefrontTheme(), $setting->homepageTemplate(), $setting),
        ]);
    }

    protected function isAppOwnDomain(Request $request): bool
    {
        $appHost = config('app.admin_host');

        return is_string($appHost) && $appHost !== '' && strcasecmp($request->getHost(), $appHost) === 0;
    }
}
