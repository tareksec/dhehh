<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\MatchesCustomerPhone;
use App\Models\Order;
use App\Services\CompanyContext;
use App\Support\StorefrontApi\Phone;
use App\Support\StorefrontApi\Signing;
use App\Support\StorefrontApi\TrackingSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Where is my order?" for visitors who are not signed in
 * (/api/v1/storefront/orders/track and /orders/{order_number}/tracking).
 *
 * Order numbers are sequential, so a number alone never opens an order: the
 * visitor must also give the phone the order was placed with (exactly the
 * Blade tracking rule) or hold the unguessable order token from the success
 * link. A wrong guess looks the same as "no such order", and guesses are
 * counted per order number so a phone number can't be brute-forced.
 */
class StorefrontOrderTrackingController extends Controller
{
    use MatchesCustomerPhone;

    private const MAX_WRONG_GUESSES = 8;

    public function __construct(protected CompanyContext $context) {}

    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:60'],
            'phone' => ['required', 'string', 'max:40'],
        ]);
        $company = $this->context->company();
        $orderNumber = trim($data['order_number']);
        $phone = Phone::normalize($data['phone']);
        $limiterKey = 'storefront-track:'.$company->getKey().':'.strtolower($orderNumber);

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_WRONG_GUESSES)) {
            return response()->json(['ok' => false, 'error' => 'too_many_attempts', 'retry_after' => RateLimiter::availableIn($limiterKey)], 429);
        }

        $order = $this->storefrontOrders()
            ->where('order_number', $orderNumber)
            ->tap(fn ($query) => $this->whereCustomerPhoneMatches($query, $phone))
            ->first();

        if (! $order) {
            RateLimiter::hit($limiterKey, 15 * 60);

            return response()->json(['ok' => false, 'error' => 'order_not_found'], 404);
        }

        return response()->json(['data' => TrackingSummary::make($order, $company, $request->attributes->get('storefront_setting'), withCustomer: false)]);
    }

    /** The success link: the order token proves the visitor placed the order, so no phone is needed. */
    public function byToken(Request $request, string $orderNumber): JsonResponse
    {
        $order = $this->storefrontOrders()->where('order_number', $orderNumber)->first();

        if (! $order || ! Signing::matches(Signing::orderToken($order), $request->query('token'))) {
            return response()->json(['ok' => false, 'error' => 'order_not_found'], 404);
        }

        return response()->json(['data' => TrackingSummary::make($order, $this->context->company(), $request->attributes->get('storefront_setting'))]);
    }

    protected function storefrontOrders()
    {
        return Order::query()->whereIn('source', [Order::SOURCE_STOREFRONT, Order::SOURCE_OFFER]);
    }
}
