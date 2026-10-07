<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __construct(protected CompanyContext $context) {}

    public function show(Request $request, string $slug): View
    {
        [$company, $setting, $previewSlug] = $this->domainStorefront($request);

        return $this->pageView($company, $setting, $slug, $previewSlug);
    }

    public function showPreview(Company $company, string $slug): View
    {
        $setting = $this->previewStorefront($company);

        return $this->pageView($company, $setting, $slug, $company->slug);
    }

    protected function pageView(
        Company $company,
        StorefrontSetting $setting,
        string $slug,
        ?string $previewSlug = null,
    ): View {
        $page = StorefrontPage::query()
            ->where('company_id', $company->getKey())
            ->where('slug', StorefrontPage::normalizeSlug($slug))
            ->published()
            ->firstOrFail();

        return StorefrontThemeRegistry::page('pages.show', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
            'page' => $page,
        ]);
    }

    protected function domainStorefront(Request $request): array
    {
        $company = $request->attributes->get('storefront_company');

        if (! $company instanceof Company && app()->environment(['local', 'testing'])) {
            $company = StorefrontSetting::query()
                ->blade()
                ->where('is_published', true)
                ->whereHas('company', fn ($query) => $query->where('is_active', true))
                ->with('company')
                ->orderBy('company_id')
                ->first()
                ?->company;
        }

        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);

        $this->context->set($company);

        return [
            $company,
            $company->storefrontSetting,
            $request->attributes->has('storefront_company') ? null : $company->slug,
        ];
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
}
