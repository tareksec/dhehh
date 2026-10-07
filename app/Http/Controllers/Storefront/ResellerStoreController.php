<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ResellerCommission;
use App\Models\ResellerPayoutRequest;
use App\Models\ResellerProduct;
use App\Services\CompanyContext;
use App\Services\ResellerCommissionService;
use App\Services\ResellerPricing;
use App\Support\MoneyFormatter;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Self-service curation of an approved reseller's own storefront -- which
 * products show at {company-domain}/store/{reseller_slug}, and the slug
 * itself. Reuses the existing storefront Customer login (guard "customer"),
 * same as the rest of /account/*; no separate auth system.
 */
class ResellerStoreController extends Controller
{
    public function __construct(
        protected CompanyContext $context,
        protected ResellerCommissionService $commissions,
    ) {}

    public function editPreview(Request $request, Company $company): View|RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->edit($request);
    }

    public function updateSlugPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->updateSlug($request);
    }

    public function toggleProductPreview(Request $request, Company $company, Product $product): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->toggleProduct($request, $product);
    }

    public function updatePricePreview(Request $request, Company $company, Product $product): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->updatePrice($request, $product);
    }

    public function requestPayoutPreview(Request $request, Company $company): RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->requestPayout($request);
    }

    public function edit(Request $request): View|RedirectResponse
    {
        $company = $this->domainStorefront($request);
        $reseller = $this->approvedReseller($request);

        if ($reseller instanceof RedirectResponse) {
            return $reseller;
        }

        // The reseller's dashboard now lives in the reseller portal (owner's
        // answer, 13_RESELLER_PORTAL_PLAN.md §2.1 Q5: redirect). Staff preview
        // has no portal host to send anyone to, so it keeps this page.
        $portalHost = $this->previewSlug($request) ? null : $company->resellerPortalHost();

        if ($portalHost) {
            return redirect()->away($request->getScheme().'://'.$portalHost.'/portal');
        }

        $search = trim((string) $request->query('search', ''));

        $products = Product::query()
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        $pickedProductIds = $reseller->resellerCatalog()->pluck('products.id')->all();
        $pickedPrices = ResellerProduct::query()
            ->where('customer_id', $reseller->getKey())
            ->pluck('price', 'product_id')
            ->all();
        $costPerUnit = (float) ($company->storefrontSetting?->reseller_cost_per_unit ?? 0);

        return StorefrontThemeRegistry::page('account.reseller', [
            'company' => $company,
            'setting' => $company->storefrontSetting,
            'customer' => $reseller,
            'products' => $products,
            'pickedProductIds' => $pickedProductIds,
            'pickedPrices' => $pickedPrices,
            'costPerUnit' => $costPerUnit,
            'search' => $search,
            'storeUrl' => $this->storeUrl($company, $reseller),
            'previewSlug' => $this->previewSlug($request),
            'balances' => $this->commissions->balances($reseller),
            'minWithdrawal' => (float) ($company->storefrontSetting?->reseller_min_withdrawal ?? 0),
            'payoutMethods' => ResellerPayoutRequest::METHODS,
            'commissions' => ResellerCommission::query()
                ->with('order:id,order_number,order_date')
                ->where('customer_id', $reseller->getKey())
                ->latest()
                ->limit(20)
                ->get(),
            'payoutRequests' => ResellerPayoutRequest::query()
                ->where('customer_id', $reseller->getKey())
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function updateSlug(Request $request): RedirectResponse
    {
        $company = $this->domainStorefront($request);
        $reseller = $this->approvedReseller($request);

        if ($reseller instanceof RedirectResponse) {
            return $reseller;
        }

        $data = $request->validate([
            'reseller_slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('customers', 'reseller_slug')
                    ->where('company_id', $company->getKey())
                    ->ignore($reseller->getKey()),
            ],
        ]);

        $reseller->update(['reseller_slug' => $data['reseller_slug']]);

        return redirect()
            ->to($this->editUrl($request))
            ->with('storefront_status', 'Your store URL has been updated.');
    }

    public function toggleProduct(Request $request, Product $product): RedirectResponse
    {
        $company = $this->domainStorefront($request);
        $reseller = $this->approvedReseller($request);

        if ($reseller instanceof RedirectResponse) {
            return $reseller;
        }

        // A reseller can only ever pick products from their own company's
        // catalog -- never another company's, even if a product id is
        // guessed/tampered with.
        abort_unless((int) $product->company_id === (int) $company->getKey(), 404);

        $existing = ResellerProduct::query()
            ->where('customer_id', $reseller->getKey())
            ->where('product_id', $product->getKey())
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            ResellerProduct::query()->create([
                'customer_id' => $reseller->getKey(),
                'product_id' => $product->getKey(),
                'is_active' => true,
            ]);
        }

        return redirect()
            ->to($this->editUrl($request))
            ->with('storefront_status', $existing ? "Removed \"{$product->name}\" from your store." : "Added \"{$product->name}\" to your store.");
    }

    /**
     * Sets the reseller's own selling price for a product in their store.
     * Owner's rule: any price at or above the base price (lowest wholesale
     * tier + the company's reseller cost per unit); blank = suggested price.
     */
    public function updatePrice(Request $request, Product $product): RedirectResponse
    {
        $company = $this->domainStorefront($request);
        $reseller = $this->approvedReseller($request);

        if ($reseller instanceof RedirectResponse) {
            return $reseller;
        }

        abort_unless((int) $product->company_id === (int) $company->getKey(), 404);

        $pick = ResellerProduct::query()
            ->where('customer_id', $reseller->getKey())
            ->where('product_id', $product->getKey())
            ->firstOrFail();

        $base = ResellerPricing::basePrice($product, (float) ($company->storefrontSetting?->reseller_cost_per_unit ?? 0));

        abort_if($base === null, 422, __('This product has no wholesale rate, so it sells at the normal price.'));

        $data = $request->validate([
            'price' => ['nullable', 'numeric', 'min:'.$base, 'max:99999999'],
        ], [
            'price.min' => __('Your price must be at least :amount.', ['amount' => MoneyFormatter::number($base)]),
        ]);

        $pick->update(['price' => filled($data['price'] ?? null) ? round((float) $data['price'], 2) : null]);

        return redirect()
            ->to($this->editUrl($request))
            ->with('storefront_status', __('Price updated for ":product".', ['product' => $product->name]));
    }

    /** A withdrawal request against the reseller's available (delivered-order) commission. */
    public function requestPayout(Request $request): RedirectResponse
    {
        $company = $this->domainStorefront($request);
        $reseller = $this->approvedReseller($request);

        if ($reseller instanceof RedirectResponse) {
            return $reseller;
        }

        $minimum = max(0.01, (float) ($company->storefrontSetting?->reseller_min_withdrawal ?? 0));

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.$minimum],
            'method' => ['required', Rule::in(array_keys(ResellerPayoutRequest::METHODS))],
            'account_details' => ['required', 'string', 'max:500'],
        ], [
            'amount.min' => __('The minimum withdrawal is :amount.', ['amount' => MoneyFormatter::number($minimum)]),
        ]);

        $created = $this->commissions->requestPayout($reseller, (float) $data['amount'], $data['method'], $data['account_details']);

        if (! $created) {
            return redirect()
                ->to($this->editUrl($request))
                ->withErrors(['amount' => __('The amount is more than your available balance.')])
                ->withInput();
        }

        return redirect()
            ->to($this->editUrl($request))
            ->with('storefront_status', __('Your withdrawal request has been sent. The store will review it.'));
    }

    protected function approvedReseller(Request $request): Customer|RedirectResponse
    {
        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer) {
            $route = $this->previewSlug($request)
                ? route('storefront.preview.account.login', $this->previewSlug($request))
                : route('storefront.account.login');

            return redirect()->to($route)->with('storefront_status', 'Log in to manage your store.');
        }

        abort_unless($customer->isApprovedReseller(), 403);

        return $customer;
    }

    protected function storeUrl(Company $company, Customer $reseller): ?string
    {
        if (blank($reseller->reseller_slug) || blank($company->domain)) {
            return null;
        }

        return 'https://'.$company->domain.route('storefront.reseller_store.products.index', $reseller->reseller_slug, false);
    }

    protected function editUrl(Request $request): string
    {
        return $this->previewSlug($request)
            ? route('storefront.preview.account.reseller', $this->previewSlug($request))
            : route('storefront.account.reseller');
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

    protected function domainStorefront(Request $request): Company
    {
        $company = $request->attributes->get('storefront_company');

        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);
        abort_unless($company->reseller_module_enabled, 404);

        $this->context->set($company);

        return $company;
    }
}
