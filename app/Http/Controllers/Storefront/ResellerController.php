<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResellerController extends Controller
{
    public function __construct(protected CompanyContext $context) {}

    public function show(Request $request): View
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->applyView($company, $setting);
    }

    public function showPreview(Company $company): View
    {
        $setting = $this->previewStorefront($company);

        return $this->applyView($company, $setting, $company->slug);
    }

    public function store(Request $request): RedirectResponse
    {
        [$company] = $this->domainStorefront($request);
        $this->apply($request);

        return redirect()->route('storefront.reseller.show')
            ->with('storefront_status', 'Your reseller application has been received. The store will contact you after review.');
    }

    public function storePreview(Request $request, Company $company): RedirectResponse
    {
        $this->previewStorefront($company);
        $this->apply($request);

        return redirect()->route('storefront.preview.reseller.show', $company->slug)
            ->with('storefront_status', 'Your reseller application has been received. The store will contact you after review.');
    }

    protected function apply(Request $request): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'business_name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = Customer::query()->firstOrNew(['phone' => $data['phone']]);

        // Approved resellers keep their status; everyone else becomes pending.
        $customer->fill([
            'name' => $data['name'],
            'business_name' => $data['business_name'],
            'reseller_note' => $data['note'] ?? null,
            'reseller_status' => $customer->reseller_status === 'approved' ? 'approved' : 'pending',
            'customer_type' => $customer->customer_type ?: 'reseller',
            'customer_source' => $customer->customer_source ?: 'website',
            'opening_balance' => $customer->opening_balance ?? 0,
            'is_active' => true,
        ]);
        $customer->save();
    }

    protected function applyView(Company $company, StorefrontSetting $setting, ?string $previewSlug = null): View
    {
        return StorefrontThemeRegistry::page('reseller.apply', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
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
        // member may only preview (and submit test applications against)
        // their own company/companies, never another company's by slug.
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
