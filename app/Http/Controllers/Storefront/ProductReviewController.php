<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductReview;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Storefront customer review submission — only from the customer's own
 * completed order, one review per (customer, product, order) enforced by the
 * unique index on product_reviews. New reviews always start pending;
 * moderation happens in the admin panel (ProductReviewResource).
 */
class ProductReviewController extends Controller
{
    public function __construct(protected CompanyContext $context) {}

    public function create(Request $request, string $orderNo): View
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->createView($company, $setting, $orderNo);
    }

    public function createPreview(Request $request, Company $company, string $orderNo): View
    {
        $setting = $this->previewStorefront($company);

        return $this->createView($company, $setting, $orderNo, $company->slug);
    }

    public function store(Request $request, string $orderNo): RedirectResponse
    {
        [$company, $setting] = $this->domainStorefront($request);

        return $this->storeForCompany($request, $company, $orderNo);
    }

    public function storePreview(Request $request, Company $company, string $orderNo): RedirectResponse
    {
        $this->previewStorefront($company);

        return $this->storeForCompany($request, $company, $orderNo, $company->slug);
    }

    protected function createView(Company $company, StorefrontSetting $setting, string $orderNo, ?string $previewSlug = null): View
    {
        $order = $this->authorizedCompletedOrder($company, $orderNo);
        $reviewedProductIds = ProductReview::query()
            ->where('order_id', $order->getKey())
            ->pluck('product_id');

        return StorefrontThemeRegistry::page('account.reviews.create', [
            'company' => $company,
            'setting' => $setting,
            'previewSlug' => $previewSlug,
            'order' => $order,
            'reviewedProductIds' => $reviewedProductIds,
        ]);
    }

    protected function storeForCompany(Request $request, Company $company, string $orderNo, ?string $previewSlug = null): RedirectResponse
    {
        $order = $this->authorizedCompletedOrder($company, $orderNo);

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $belongsToOrder = $order->items->contains('product_id', (int) $data['product_id']);
        abort_unless($belongsToOrder, 404);

        $customer = Auth::guard('customer')->user();

        $exists = ProductReview::query()
            ->where('order_id', $order->getKey())
            ->where('product_id', $data['product_id'])
            ->where('customer_id', $customer->getKey())
            ->exists();

        $redirectUrl = $previewSlug
            ? route('storefront.preview.account.reviews.create', [$previewSlug, $orderNo])
            : route('storefront.account.reviews.create', $orderNo);

        if ($exists) {
            return redirect($redirectUrl)->with('storefront_status', 'You already reviewed this product for this order.');
        }

        ProductReview::query()->create([
            'product_id' => $data['product_id'],
            'order_id' => $order->getKey(),
            'customer_id' => $customer->getKey(),
            'customer_name' => $customer->name,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'status' => ProductReview::STATUS_PENDING,
        ]);

        return redirect($redirectUrl)->with('storefront_status', 'Thank you — your review has been submitted for approval.');
    }

    protected function authorizedCompletedOrder(Company $company, string $orderNo): Order
    {
        $customer = Auth::guard('customer')->user();

        abort_unless($customer instanceof Customer, 404);

        $order = Order::query()
            ->with('items.product')
            ->where('company_id', $company->getKey())
            ->where('customer_id', $customer->getKey())
            ->where('order_number', trim($orderNo))
            ->first();

        abort_unless($order && $order->status === Order::STATUS_COMPLETED, 404);

        return $order;
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
