<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\MatchesCustomerPhone;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorefrontCustomerActivity;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\StorefrontCart;
use App\Services\StorefrontCustomerActivityService;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AccountOrdersController extends Controller
{
    use MatchesCustomerPhone;

    public function __construct(
        protected CompanyContext $context,
        protected StorefrontCart $cart,
        protected StorefrontCustomerActivityService $activities,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->ordersView($request, $company, $setting);
    }

    public function indexPreview(Request $request, Company $company): View
    {
        $setting = $this->previewStorefront($company);

        if ($setting->customer_accounts_enabled && ($customer = Auth::guard('customer')->user()) instanceof Customer) {
            $orders = Order::query()
                ->with(['customer', 'items.product'])
                ->where('company_id', $company->getKey())
                ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
                ->where('customer_id', $customer->getKey())
                ->latest('order_date')
                ->latest('id')
                ->get();

            return StorefrontThemeRegistry::page('account.orders', [
                'company' => $company,
                'setting' => $setting,
                'customer' => $customer,
                'previewSlug' => $company->slug,
                'phone' => null,
                'orders' => $orders,
                'hasSearched' => true,
            ]);
        }

        return $this->ordersView($request, $company, $setting, $company->slug);
    }

    public function reorder(Request $request, string $orderNo): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);

        if (! $setting->customer_accounts_enabled) {
            return redirect()
                ->route('storefront.track.index')
                ->with('storefront_status', __('Enter your order number and checkout phone to find an order.'));
        }

        $customer = Auth::guard('customer')->user();

        if (! $customer) {
            return redirect()
                ->route('storefront.account.login')
                ->with('storefront_status', __('Log in to reorder from your order history.'));
        }

        $order = Order::query()
            ->with(['items.product'])
            ->where('company_id', $company->getKey())
            ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
            ->where('customer_id', $customer->getKey())
            ->where('order_number', trim($orderNo))
            ->first();

        abort_unless($order, 404);

        $added = $this->addOrderItemsToCart($company, $order);

        if ($added > 0 && $customer instanceof Customer) {
            $this->activities->record(
                $customer,
                StorefrontCustomerActivity::TYPE_ORDER_REORDERED,
                'Items added from a previous order',
                "Available items from order {$order->order_number} were added to your cart.",
                ['order_number' => $order->order_number, 'items_added' => $added],
            );
        }

        return redirect()->route('storefront.cart.show');
    }

    public function reorderPreview(Request $request, Company $company, string $orderNo): RedirectResponse
    {
        $this->previewStorefront($company);

        if (($customer = Auth::guard('customer')->user()) instanceof Customer) {
            $order = Order::query()
                ->with(['items.product'])
                ->where('company_id', $company->getKey())
                ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
                ->where('customer_id', $customer->getKey())
                ->where('order_number', trim($orderNo))
                ->first();

            abort_unless($order, 404);

            $added = $this->addOrderItemsToCart($company, $order);

            if ($added > 0) {
                $this->activities->record(
                    $customer,
                    StorefrontCustomerActivity::TYPE_ORDER_REORDERED,
                    'Items added from a previous order',
                    "Available items from order {$order->order_number} were added to your cart.",
                    ['order_number' => $order->order_number, 'items_added' => $added],
                );
            }

            return redirect()->route('storefront.preview.cart.show', $company->slug);
        }

        $phone = trim((string) $request->string('phone'));

        abort_if($phone === '', 404);

        $order = $this->ordersForPhone($company, $phone)
            ->firstWhere('order_number', trim($orderNo));

        abort_unless($order, 404);

        $this->addOrderItemsToCart($company, $order);

        return redirect()->route('storefront.preview.cart.show', $company->slug);
    }

    protected function addOrderItemsToCart(Company $company, Order $order): int
    {
        $added = 0;

        foreach ($order->items as $item) {
            $product = $item->product;

            if (! $product || ! $product->is_active || $product->status !== Product::STATUS_AVAILABLE) {
                continue;
            }

            $variant = $item->product_variant_id
                ? $product->activeVariants()->whereKey($item->product_variant_id)->first()
                : null;

            if ($item->product_variant_id && ! $variant) {
                continue;
            }

            $this->cart->add($company, $product, (int) $item->quantity, $variant);
            $added++;
        }

        session()->flash('storefront_status', $added > 0
            ? $added === 1
                ? __('Added :count item from order :order to your cart.', ['count' => $added, 'order' => $order->order_number])
                : __('Added :count items from order :order to your cart.', ['count' => $added, 'order' => $order->order_number])
            : __('None of the items from that order are currently available.'));

        return $added;
    }

    protected function ordersView(
        Request $request,
        Company $company,
        StorefrontSetting $setting,
        ?string $previewSlug = null,
    ): View|RedirectResponse {
        if ($previewSlug === null) {
            if (! $setting->customer_accounts_enabled) {
                return redirect()
                    ->route('storefront.track.index')
                    ->with('storefront_status', __('Enter your order number and checkout phone to find an order.'));
            }

            $customer = Auth::guard('customer')->user();

            if (! $customer) {
                return redirect()
                    ->route('storefront.account.login')
                    ->with('storefront_status', __('Log in to view your order history.'));
            }

            $orders = Order::query()
                ->with(['customer', 'items.product'])
                ->where('company_id', $company->getKey())
                ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
                ->where('customer_id', $customer->getKey())
                ->latest('order_date')
                ->latest('id')
                ->get();

            return StorefrontThemeRegistry::page('account.orders', [
                'company' => $company,
                'setting' => $setting,
                'customer' => $customer,
                'previewSlug' => $previewSlug,
                'phone' => null,
                'orders' => $orders,
                'hasSearched' => true,
            ]);
        }

        $phone = trim((string) $request->string('phone'));
        $orders = $phone === '' ? collect() : $this->ordersForPhone($company, $phone);

        return StorefrontThemeRegistry::page('account.orders', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
            'phone' => $phone,
            'orders' => $orders,
            'hasSearched' => $request->filled('phone'),
        ]);
    }

    protected function ordersForPhone(Company $company, string $phone): Collection
    {
        return Order::query()
            ->with(['customer', 'items.product'])
            ->where('company_id', $company->getKey())
            ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
            ->tap(fn ($query) => $this->whereCustomerPhoneMatches($query, $phone))
            ->latest('order_date')
            ->latest('id')
            ->get();
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
