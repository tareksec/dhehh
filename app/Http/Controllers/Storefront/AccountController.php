<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StorefrontCustomerActivity;
use App\Services\CompanyContext;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __construct(protected CompanyContext $context) {}

    public function indexPreview(Request $request, Company $company): View|RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->index($request);
    }

    public function activityPreview(Request $request, Company $company): View|RedirectResponse
    {
        $this->preparePreview($request, $company);

        return $this->activity($request);
    }

    public function index(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        $customer = $this->customer();

        if (! $customer) {
            return $this->loginRedirect($request, 'Log in to open your account.');
        }

        $orders = $this->ordersQuery($company, $customer);

        return StorefrontThemeRegistry::page('account.index', [
            'company' => $company,
            'setting' => $setting,
            'customer' => $customer,
            'orderCount' => (clone $orders)->count(),
            'activeOrderCount' => (clone $orders)->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'completedOrderCount' => (clone $orders)->where('status', 'completed')->count(),
            // In the buyer's own prices: a reseller's own-courier order counts at the reseller's retail price.
            'totalSpent' => (clone $orders)->whereIn('status', Order::ACCOUNTED_STATUSES)->with('items')->get()->sum(fn (Order $order): float => $order->buyerTotal()),
            'recentOrders' => (clone $orders)->with('items')->latest('order_date')->latest('id')->limit(3)->get(),
            'recentActivities' => $this->activitiesQuery($company, $customer)->limit(5)->get(),
            'previewSlug' => $this->previewSlug($request),
        ]);
    }

    public function activity(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);
        $customer = $this->customer();

        if (! $customer) {
            return $this->loginRedirect($request, 'Log in to view your account activity.');
        }

        return StorefrontThemeRegistry::page('account.activity', [
            'company' => $company,
            'setting' => $setting,
            'customer' => $customer,
            'activities' => $this->activitiesQuery($company, $customer)->paginate(15),
            'previewSlug' => $this->previewSlug($request),
        ]);
    }

    protected function ordersQuery(Company $company, Customer $customer): Builder
    {
        return Order::query()
            ->where('company_id', $company->getKey())
            ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
            ->where('customer_id', $customer->getKey());
    }

    protected function activitiesQuery(Company $company, Customer $customer): Builder
    {
        return StorefrontCustomerActivity::query()
            ->where('company_id', $company->getKey())
            ->where('customer_id', $customer->getKey())
            ->latest();
    }

    protected function customer(): ?Customer
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer ? $customer : null;
    }

    protected function loginRedirect(Request $request, string $message): RedirectResponse
    {
        $route = $this->previewSlug($request)
            ? route('storefront.preview.account.login', $this->previewSlug($request))
            : route('storefront.account.login');

        return redirect()->to($route)->with('storefront_status', $message);
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

    protected function domainStorefront(Request $request): array
    {
        $company = $request->attributes->get('storefront_company');

        abort_unless($company instanceof Company && $company->storefrontSetting?->is_published, 404);
        abort_unless($company->storefrontSetting->customer_accounts_enabled, 404);

        $this->context->set($company);

        return [$company, $company->storefrontSetting];
    }
}
