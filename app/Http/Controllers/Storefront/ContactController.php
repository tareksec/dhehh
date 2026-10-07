<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyFaq;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function __construct(protected CompanyContext $context) {}

    public function show(Request $request): View
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->pageView($company, $setting);
    }

    public function showPreview(Company $company): View
    {
        $setting = $this->previewStorefront($company);

        return $this->pageView($company, $setting, $company->slug);
    }

    protected function pageView(Company $company, StorefrontSetting $setting, ?string $previewSlug = null): View
    {
        $faqs = CompanyFaq::query()
            ->where('is_active', true)
            ->orderBy('question')
            ->get();

        return StorefrontThemeRegistry::page('contact.show', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
            'faqs' => $faqs,
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
}
