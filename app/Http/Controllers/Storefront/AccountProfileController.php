<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\StorefrontCustomerActivity;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\StorefrontCustomerActivityService;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountProfileController extends Controller
{
    public function __construct(
        protected CompanyContext $context,
        protected CustomerAccountService $accounts,
        protected StorefrontCustomerActivityService $activities,
    ) {}

    public function showPreview(Request $request, Company $company): View|RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->show($request);
    }

    public function updatePreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->update($request);
    }

    public function updatePasswordPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->updatePassword($request);
    }

    public function show(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        $customer = Auth::guard('customer')->user();

        if (! $customer) {
            return redirect()->to($this->accountRoute($request, 'login'));
        }

        return StorefrontThemeRegistry::page('account.profile', [
            'company' => $company,
            'setting' => $setting,
            'customer' => $customer,
            'previewSlug' => $this->previewSlug($request),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->domainStorefront($request);
        $customer = $this->requireCustomer();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        if (filled($data['email']) && $data['email'] !== $customer->email && ! $this->accounts->emailAvailable($data['email'], $customer->getKey())) {
            throw ValidationException::withMessages(['email' => __('That email address is already in use by another account.')]);
        }

        $changedFields = collect($data)
            ->filter(fn ($value, string $field): bool => $customer->getAttribute($field) !== $value)
            ->keys()
            ->values()
            ->all();

        $customer->update($data);

        if ($changedFields !== []) {
            $this->activities->record(
                $customer,
                StorefrontCustomerActivity::TYPE_PROFILE_UPDATED,
                'Profile updated',
                'Your customer information was updated.',
                ['fields' => $changedFields],
            );
        }

        return redirect()->to($this->accountRoute($request, 'profile'))->with('storefront_status', __('Your profile has been updated.'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $this->domainStorefront($request);
        $customer = $this->requireCustomer();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $customer->password)) {
            throw ValidationException::withMessages(['current_password' => __('Your current password is incorrect.')]);
        }

        $this->accounts->updatePassword($customer, $data['password']);

        $this->activities->record(
            $customer,
            StorefrontCustomerActivity::TYPE_PASSWORD_CHANGED,
            'Password changed',
            'Your account password was changed successfully.',
        );

        return redirect()->to($this->accountRoute($request, 'profile'))->with('storefront_status', __('Your password has been changed.'));
    }

    protected function requireCustomer(): Customer
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }

    protected function preparePreview(Request $request, Company $company): void
    {
        abort_unless($company->storefrontSetting?->is_published, 404);

        $request->attributes->set('storefront_company', $company);
        $request->attributes->set('storefront_preview_slug', $company->slug);
    }

    protected function previewSlug(Request $request): ?string
    {
        return $request->attributes->get('storefront_preview_slug');
    }

    protected function accountRoute(Request $request, string $name): string
    {
        if ($previewSlug = $this->previewSlug($request)) {
            return route('storefront.preview.account.'.$name, $previewSlug);
        }

        return route('storefront.account.'.$name);
    }

    protected function domainStorefront(Request $request): array
    {
        $company = $request->attributes->get('storefront_company');

        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);

        $this->context->set($company);

        return [$company, $company->storefrontSetting];
    }
}
