<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\StorefrontCustomerActivity;
use App\Models\StorefrontCustomerToken;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\StorefrontCustomerActivityService;
use App\Support\StorefrontApi\AccountPayload;
use App\Support\StorefrontApi\Media;
use App\Support\StorefrontApi\TrackingSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The signed-in customer's area of the headless Next.js site
 * (/api/v1/storefront/account…): profile, password, order history and tracking,
 * reorder and product reviews. Mirrors the Blade account pages, and every query
 * is limited to the customer behind the token — there is no way to name another
 * customer's order.
 */
class StorefrontAccountController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(
        protected CompanyContext $context,
        protected CustomerAccountService $accounts,
        protected StorefrontCustomerActivityService $activities,
    ) {}

    /** The account home: profile, order counters, the latest orders and activity. */
    public function show(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $company = $this->context->company();
        $orders = $this->ordersQuery($customer);

        return response()->json(['data' => [
            'customer' => AccountPayload::customer($customer),
            'stats' => [
                'order_count' => (clone $orders)->count(),
                'active_order_count' => (clone $orders)->whereNotIn('status', ['completed', 'cancelled'])->count(),
                'completed_order_count' => (clone $orders)->where('status', 'completed')->count(),
                'total_spent' => round((float) (clone $orders)->whereIn('status', Order::ACCOUNTED_STATUSES)->sum('total_amount'), 2),
            ],
            'recent_orders' => $this->listItems((clone $orders)->with('items.product')->latest('order_date')->latest('id')->limit(3)->get(), $customer, $company),
            'recent_activity' => $this->activityQuery($customer)->limit(5)->get()->map(fn ($a) => AccountPayload::activity($a))->all(),
        ]]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        if (filled($data['email'] ?? null) && $data['email'] !== $customer->email && ! $this->accounts->emailAvailable($data['email'], $customer->getKey())) {
            return $this->fail('email_taken', 409);
        }

        $changed = collect($data)
            ->filter(fn ($value, string $field): bool => $customer->getAttribute($field) !== $value)
            ->keys()->values()->all();

        $customer->update($data);

        if ($changed !== []) {
            $this->activities->record($customer, StorefrontCustomerActivity::TYPE_PROFILE_UPDATED, 'Profile updated', 'Your customer information was updated.', ['fields' => $changed]);
        }

        return response()->json(['data' => AccountPayload::customer($customer->refresh())]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], (string) $customer->password)) {
            return $this->fail('current_password_incorrect', 422);
        }

        $this->accounts->updatePassword($customer, $data['password']);

        // Every other device is signed out; this one stays.
        StorefrontCustomerToken::revokeAllFor($customer, $request->attributes->get('storefront_customer_token')?->getKey());

        $this->activities->record($customer, StorefrontCustomerActivity::TYPE_PASSWORD_CHANGED, 'Password changed', 'Your account password was changed successfully.');

        return response()->json(['ok' => true]);
    }

    public function orders(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $page = $this->ordersQuery($customer)->with('items.product')->latest('order_date')->latest('id')->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $this->listItems($page->getCollection(), $customer, $this->context->company()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function order(Request $request, string $orderNumber): JsonResponse
    {
        $customer = $this->customer($request);
        $order = $this->ownOrder($customer, $orderNumber);

        if (! $order) {
            return $this->fail('order_not_found', 404);
        }

        $summary = TrackingSummary::make($order, $this->context->company(), $request->attributes->get('storefront_setting'));
        $reviews = $this->reviewsFor($customer, [$order->getKey()]);

        $summary['items'] = $order->items->values()->map(function ($item, int $index) use ($summary, $reviews, $order): array {
            $review = $reviews->get($order->getKey().':'.$item->product_id);

            return $summary['items'][$index] + [
                'review' => $review ? ['status' => $review->status, 'rating' => (int) $review->rating, 'comment' => $review->comment] : null,
                'can_review' => $order->status === Order::STATUS_COMPLETED && $item->product && ! $review,
            ];
        })->all();

        return response()->json(['data' => $summary]);
    }

    /**
     * What to put back in the cart. The website adds these lines to its own
     * cart and re-prices them through the normal quote, so a product that is
     * gone, out of stock, below its minimum quantity or changed in price is
     * reported there like any other cart line.
     */
    public function reorder(Request $request, string $orderNumber): JsonResponse
    {
        $customer = $this->customer($request);
        $order = $this->ownOrder($customer, $orderNumber);

        if (! $order) {
            return $this->fail('order_not_found', 404);
        }

        $lines = [];
        $skipped = 0;

        foreach ($order->items as $item) {
            $product = $item->product;

            if (! $product || ! $product->is_active || $product->status !== Product::STATUS_AVAILABLE) {
                $skipped++;

                continue;
            }

            if ($item->product_variant_id && ! $product->activeVariants()->whereKey($item->product_variant_id)->exists()) {
                $skipped++;

                continue;
            }

            $lines[] = [
                'slug' => $product->slug,
                'quantity' => (int) $item->quantity,
                'variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
            ];
        }

        if ($lines !== []) {
            $this->activities->record(
                $customer,
                StorefrontCustomerActivity::TYPE_ORDER_REORDERED,
                'Items added from a previous order',
                "Available items from order {$order->order_number} were added to your cart.",
                ['order_number' => $order->order_number, 'items_added' => count($lines)],
            );
        }

        return response()->json(['data' => ['lines' => $lines, 'skipped' => $skipped]]);
    }

    /**
     * A review, only for a product on the customer's own COMPLETED order, once
     * per product and order. It starts pending: the owner approves it in the
     * ERP before it shows on the product page.
     */
    public function storeReview(Request $request, string $orderNumber): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate([
            'product_slug' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = $this->ownOrder($customer, $orderNumber);

        if (! $order) {
            return $this->fail('order_not_found', 404);
        }

        if ($order->status !== Order::STATUS_COMPLETED) {
            return $this->fail('order_not_completed', 409);
        }

        $item = $order->items->first(fn ($line): bool => $line->product && $line->product->slug === $data['product_slug']);

        if (! $item) {
            return $this->fail('product_not_in_order', 404);
        }

        // A review the owner moved to Trash still counts: one review per product and order.
        $already = ProductReview::withTrashed()
            ->where('order_id', $order->getKey())
            ->where('product_id', $item->product_id)
            ->where('customer_id', $customer->getKey())
            ->exists();

        if ($already) {
            return $this->fail('already_reviewed', 409);
        }

        $review = ProductReview::query()->create([
            'product_id' => $item->product_id,
            'order_id' => $order->getKey(),
            'customer_id' => $customer->getKey(),
            'customer_name' => $customer->name,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'status' => ProductReview::STATUS_PENDING,
        ]);

        return response()->json(['data' => ['status' => $review->status, 'rating' => (int) $review->rating, 'comment' => $review->comment]], 201);
    }

    public function activity(Request $request): JsonResponse
    {
        $page = $this->activityQuery($this->customer($request))->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($activity) => AccountPayload::activity($activity))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    // ------------------------------------------------------------------ internals

    protected function customer(Request $request): Customer
    {
        return $request->attributes->get('storefront_customer');
    }

    protected function ordersQuery(Customer $customer): Builder
    {
        return Order::query()
            ->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER])
            ->where('customer_id', $customer->getKey());
    }

    protected function ownOrder(Customer $customer, string $orderNumber): ?Order
    {
        return $this->ordersQuery($customer)->with('items.product')->where('order_number', trim($orderNumber))->first();
    }

    protected function activityQuery(Customer $customer): Builder
    {
        return StorefrontCustomerActivity::query()->where('customer_id', $customer->getKey())->latest();
    }

    /** The customer's reviews of these orders, keyed "orderId:productId". */
    protected function reviewsFor(Customer $customer, array $orderIds)
    {
        return ProductReview::query()
            ->where('customer_id', $customer->getKey())
            ->whereIn('order_id', $orderIds)
            ->get()
            ->keyBy(fn (ProductReview $review): string => $review->order_id.':'.$review->product_id);
    }

    /** @return list<array<string, mixed>> */
    protected function listItems($orders, Customer $customer, $company): array
    {
        $reviews = $this->reviewsFor($customer, $orders->pluck('id')->all());

        return $orders->map(function (Order $order) use ($reviews, $company): array {
            $delivery = (string) ($order->delivery_status ?: 'not_booked');

            return [
                'order_number' => $order->order_number,
                'order_date' => $order->order_date?->toDateString(),
                'status' => $order->status,
                'status_label' => Order::STATUSES[$order->status] ?? str((string) $order->status)->headline()->toString(),
                'delivery_status' => $delivery,
                'delivery_status_label' => Order::DELIVERY_STATUSES[$delivery] ?? str($delivery)->headline()->toString(),
                'total_amount' => (float) $order->total_amount,
                'due_amount' => (float) $order->due_amount,
                'item_count' => (int) $order->items->sum('quantity'),
                'items' => $order->items->take(3)->map(fn ($item): array => [
                    'name' => $item->product?->name,
                    'quantity' => (int) $item->quantity,
                    'image_url' => Media::url($item->product?->image, $company),
                ])->values()->all(),
                'can_review' => $order->status === Order::STATUS_COMPLETED
                    && $order->items->contains(fn ($item): bool => $item->product && ! $reviews->has($order->getKey().':'.$item->product_id)),
            ];
        })->values()->all();
    }

    protected function fail(string $error, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $error], $status);
    }
}
