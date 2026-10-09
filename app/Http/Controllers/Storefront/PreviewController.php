<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCarousel;
use App\Models\StorefrontSetting;
use App\Models\StorefrontSlide;
use App\Services\CompanyContext;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PreviewController extends Controller
{
    public function __construct(protected CompanyContext $context) {}

    public function home(?Company $company = null): View
    {
        $company = $this->previewCompany($company);
        $setting = $this->previewSetting($company);

        return view($setting->homepageView(), [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $company->slug,
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
            'carousels' => ProductCarousel::forHomepage(),
            'slides' => StorefrontSlide::forCompanyTheme($company->getKey(), $setting->storefrontTheme(), $setting->homepageTemplate(), $setting),
        ]);
    }

    public function products(Request $request, Company $company, ?string $slug = null): View
    {
        $setting = $this->previewSetting($company);

        $category = $slug
            ? Category::query()->where('slug', $slug)->where('is_active', true)->firstOrFail()
            : null;

        $sort = $request->string('sort')->value();
        $search = trim((string) $request->string('q'));

        return StorefrontThemeRegistry::page('products.index', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $company->slug,
            'category' => $category,
            'categories' => Category::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'products' => Product::query()
                ->with('category')
                ->where('is_active', true)
                ->where('status', Product::STATUS_AVAILABLE)
                ->when($category, fn ($query) => $query->whereBelongsTo($category))
                ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
                ->when($sort === 'price_asc', fn ($query) => $query->orderByRaw('COALESCE(sale_price, price) asc'))
                ->when($sort === 'price_desc', fn ($query) => $query->orderByRaw('COALESCE(sale_price, price) desc'))
                ->when(! in_array($sort, ['price_asc', 'price_desc'], true), fn ($query) => $query->latest())
                ->paginate(24)
                ->withQueryString(),
            'sort' => $sort,
            'search' => $search,
        ]);
    }

    public function product(Company $company, string $slug): View
    {
        $setting = $this->previewSetting($company);

        $product = Product::query()
            ->with('category')
            ->where('slug', $slug)
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->firstOrFail();

        $related = Product::query()
            ->with('category')
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->where('id', '!=', $product->id)
            ->when($product->category_id, fn ($query) => $query->where('category_id', $product->category_id))
            ->latest()
            ->limit(4)
            ->get();

        return StorefrontThemeRegistry::page('products.show', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $company->slug,
            'product' => $product,
            'related' => $related,
        ]);
    }

    protected function previewCompany(?Company $company): Company
    {
        abort_unless(app()->environment(['local', 'testing']) || auth()->check(), 404);

        // An explicit /storefront/{company:slug} preview must belong to the
        // signed-in staff member's own company/companies — otherwise any
        // authenticated user could browse another company's unpublished
        // storefront by guessing its slug. Unauthenticated local/testing
        // access (handled above) never reaches here.
        if ($company && auth()->check()) {
            abort_unless(auth()->user()->canAccessCompany($company->getKey()), 404);
        }

        $company ??= StorefrontSetting::query()
            ->blade()
            ->where('is_published', true)
            ->with('company')
            ->first()
            ?->company;

        $company ??= Company::defaultCompany();

        abort_unless($company instanceof Company, 404);

        $this->context->set($company);

        return $company;
    }

    protected function previewSetting(Company $company): StorefrontSetting
    {
        $this->context->set($company);

        return $company->storefrontSetting ?: new StorefrontSetting([
            'company_id' => $company->getKey(),
            'theme_color' => '#F59E0B',
            'meta_title' => $company->name,
            'is_published' => true,
        ]);
    }
}
