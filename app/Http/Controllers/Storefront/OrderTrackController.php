<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\MatchesCustomerPhone;
use App\Models\Company;
use App\Models\Order;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\StorefrontOrderTrackingService;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class OrderTrackController extends Controller
{
    use MatchesCustomerPhone;

    public function __construct(
        protected CompanyContext $context,
        protected StorefrontOrderTrackingService $tracking,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);

        if ($request->filled('order_number')) {
            return redirect()->route('storefront.track.show', [
                'orderNo' => trim((string) $request->string('order_number')),
                'phone' => trim((string) $request->string('phone')),
            ]);
        }

        return $this->trackView($company, $setting);
    }

    public function indexPreview(Request $request, Company $company): View|RedirectResponse
    {
        $setting = $this->previewStorefront($company);

        if ($request->filled('order_number')) {
            return redirect()->route('storefront.preview.track.show', [
                'company' => $company->slug,
                'orderNo' => trim((string) $request->string('order_number')),
                'phone' => trim((string) $request->string('phone')),
            ]);
        }

        return $this->trackView($company, $setting, previewSlug: $company->slug);
    }

    public function show(Request $request, string $orderNo): View
    {
        [$company, $setting] = $this->domainStorefront($request);
        $phone = trim((string) $request->string('phone'));

        return $this->trackView(
            company: $company,
            setting: $setting,
            order: $this->storefrontOrderForRequest($request, $company, $orderNo, $phone),
            orderNumber: trim($orderNo),
            phone: $phone,
            searched: true,
        );
    }

    public function showPreview(Request $request, Company $company, string $orderNo): View
    {
        $setting = $this->previewStorefront($company);
        $phone = trim((string) $request->string('phone'));

        return $this->trackView(
            company: $company,
            setting: $setting,
            order: $this->storefrontOrderForRequest($request, $company, $orderNo, $phone),
            previewSlug: $company->slug,
            orderNumber: trim($orderNo),
            phone: $phone,
            searched: true,
        );
    }

    protected function trackView(
        Company $company,
        StorefrontSetting $setting,
        ?Order $order = null,
        ?string $previewSlug = null,
        ?string $orderNumber = null,
        ?string $phone = null,
        bool $searched = false,
    ): View {
        $order?->load(['customer', 'items.product', 'latestCourierBooking.provider', 'latestCourierBooking.statusLogs']);

        return StorefrontThemeRegistry::page('track.show', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
            'order' => $order,
            'orderNumber' => $orderNumber ?? $order?->order_number,
            'phone' => $phone,
            'notFound' => $searched && ! $order,
            'trackingUpdates' => $order ? $this->trackingUpdates($order) : collect(),
        ]);
    }

    protected function trackingUpdates(Order $order)
    {
        return $this->tracking->updates($order)->map(fn (array $update): array => [
            'time' => $update['time'],
            'type' => $update['type'],
            'title' => match ($update['type']) {
                StorefrontOrderTrackingService::TYPE_DELIVERY => __('Delivery status updated'),
                StorefrontOrderTrackingService::TYPE_ORDER => __('Order status updated'),
                default => __('Courier status updated'),
            },
            'message' => match ($update['type']) {
                StorefrontOrderTrackingService::TYPE_DELIVERY => __('Delivery status updated to :status.', ['status' => $update['label']]),
                StorefrontOrderTrackingService::TYPE_ORDER => __('Order status updated to :status.', ['status' => $update['label']]),
                default => trim($update['label'].($update['note'] ? ': '.$update['note'] : '.')),
            },
        ]);
    }

    /**
     * Resolve a storefront order for public tracking. The order number alone
     * is guessable (sequential), so a matching customer phone is required as
     * a second factor; a mismatch simply returns null (indistinguishable from
     * "not found") rather than revealing the order.
     */
    protected function storefrontOrder(Company $company, string $orderNo, string $phone): ?Order
    {
        $orderNo = trim($orderNo);
        $phone = trim($phone);

        if ($orderNo === '' || $phone === '') {
            return null;
        }

        return Order::query()
            ->where('company_id', $company->getKey())
            ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
            ->where('order_number', $orderNo)
            ->tap(fn ($query) => $this->whereCustomerPhoneMatches($query, $phone))
            ->first();
    }

    /**
     * Signed links can open one order without placing customer contact data in
     * the URL. Logged-in customers may also open their own orders directly;
     * everyone else must still provide the checkout phone as a second factor.
     */
    protected function storefrontOrderForRequest(
        Request $request,
        Company $company,
        string $orderNo,
        string $phone,
    ): ?Order {
        $orderNo = trim($orderNo);

        if ($orderNo === '') {
            return null;
        }

        $query = Order::query()
            ->where('company_id', $company->getKey())
            ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
            ->where('order_number', $orderNo);

        if ($request->hasValidSignature()) {
            return $query->first();
        }

        if ($customer = Auth::guard('customer')->user()) {
            return $query->where('customer_id', $customer->getKey())->first();
        }

        if (trim($phone) === '') {
            return null;
        }

        return $query
            ->tap(fn ($query) => $this->whereCustomerPhoneMatches($query, $phone))
            ->first();
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
