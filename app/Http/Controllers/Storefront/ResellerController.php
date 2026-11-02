<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Support\StorefrontApi\Phone;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            ->with('storefront_status', __('Your reseller application has been received. The store will contact you after review.'));
    }

    public function storePreview(Request $request, Company $company): RedirectResponse
    {
        $this->previewStorefront($company);
        $this->apply($request);

        return redirect()->route('storefront.preview.reseller.show', $company->slug)
            ->with('storefront_status', __('Your reseller application has been received. The store will contact you after review.'));
    }

    protected function apply(Request $request): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'business_name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $phone = Phone::normalize($data['phone']);

        // A number that already belongs to a customer is never taken over by
        // this plain form (owner decision 2026-10-12): the owner of the number
        // proves it with an SMS code on the reseller portal's application page.
        if (app(CustomerAccountService::class)->findByPhone($phone) !== null) {
            $joinUrl = $this->context->company()?->resellerJoinUrl();

            throw ValidationException::withMessages([
                'phone' => $joinUrl
                    ? __('This phone number already belongs to a customer of the store, so it has to be confirmed by SMS first. Please apply here: :url', ['url' => $joinUrl])
                    : __('This phone number already belongs to a customer of the store. Please contact the store to become a reseller.'),
            ]);
        }

        $customer = new Customer(['phone' => $phone]);
        $customer->fill([
            'name' => $data['name'],
            'business_name' => $data['business_name'],
            'reseller_note' => $data['note'] ?? null,
            'reseller_status' => 'pending',
            'customer_type' => 'reseller',
            'customer_source' => 'website',
            'opening_balance' => 0,
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
