<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Models\StorefrontCustomerActivity;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Services\CheckoutOutcome;
use App\Services\CompanyContext;
use App\Services\StorefrontApiCartResolver;
use App\Services\StorefrontCatalogService;
use App\Services\StorefrontCheckoutService;
use App\Services\StorefrontCustomerActivityService;
use App\Services\StorefrontCustomerSession;
use App\Services\StorefrontDeliveryAreaResolver;
use App\Services\StorefrontDeliveryService;
use App\Services\StorefrontMetaTrackingService;
use App\Services\StorefrontPaymentService;
use App\Support\StorefrontApi\Media;
use App\Support\StorefrontApi\OrderSummary;
use App\Support\StorefrontApi\Signing;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Checkout for the headless Next.js site (/api/v1/storefront/checkout…).
 *
 * The website sends only WHAT the customer wants (product slug, variation,
 * quantity) and who they are; the ERP decides every price, fee and rule via the
 * same StorefrontCheckoutService the Blade storefront uses: delivery charge,
 * VAT, checkout policy (blocks, order limits), pre-order / new-customer /
 * courier-risk advances, and online payment. A price sent by a browser would be
 * ignored — there is no field for one.
 */
class StorefrontCheckoutController extends Controller
{
    public function __construct(
        protected CompanyContext $context,
        protected StorefrontCheckoutService $checkout,
        protected StorefrontPaymentService $payments,
        protected StorefrontMetaTrackingService $metaTracking,
        protected StorefrontDeliveryService $delivery,
        protected StorefrontDeliveryAreaResolver $areas,
        protected StorefrontCustomerSession $session,
        protected StorefrontCustomerActivityService $activities,
    ) {}

    /** What the cart would cost right now: priced lines, issues, delivery, VAT, totals and the payment options. */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate($this->itemRules() + ['address' => ['nullable', 'string', 'max:1000']]);
        [$company, $setting] = $this->site($request);

        $resolved = $this->resolver($company)->resolve($data['items']);

        return response()->json(['data' => $this->quotePayload($company, $setting, $resolved['lines'], $resolved['issues'], $data['address'] ?? null)]);
    }

    /**
     * Place the order — or, when an advance or full online payment is required,
     * start the hosted payment and return its URL (the order is created only
     * once the gateway verifies the payment).
     */
    public function place(Request $request): JsonResponse
    {
        [$company, $setting] = $this->site($request);
        $methods = $this->checkout->activePaymentMethods($setting);

        if (! $request->filled('payment_method') && $methods->isNotEmpty()) {
            $request->merge(['payment_method' => $methods->first()->paymentValue()]);
        }

        $validated = $request->validate($this->checkout->contactRules($methods, (string) $request->input('payment_method')) + $this->itemRules() + [
            'expected_total' => ['nullable', 'numeric', 'min:0'],
            'client_request_id' => ['nullable', 'string', 'max:100'],
            'return_url' => ['required', 'url', 'max:500'],
            'cancel_url' => ['required', 'url', 'max:500'],
            'client_ip' => ['nullable', 'ip'],
            'client_user_agent' => ['nullable', 'string', 'max:500'],
            'meta' => ['nullable', 'array'],
            'meta.fbp' => ['nullable', 'string', 'max:255'],
            'meta.fbc' => ['nullable', 'string', 'max:255'],
            'meta.event_source_url' => ['nullable', 'string', 'max:500'],
            'meta.consent_granted' => ['nullable', 'boolean'],
        ]);

        $returnUrl = $this->siteUrl($validated['return_url'], $setting, 'return_url');
        $cancelUrl = $this->siteUrl($validated['cancel_url'], $setting, 'cancel_url');

        $requestId = $validated['client_request_id'] ?? null;
        $cacheKey = $requestId ? 'storefront-checkout:'.$company->getKey().':'.sha1($requestId) : null;

        // A double click or a retry after a network timeout must never place a second order.
        if ($cacheKey && ($replayed = $this->replay($cacheKey, $company, $setting))) {
            return $replayed;
        }

        $lock = $cacheKey ? Cache::lock($cacheKey.':lock', 30) : null;

        try {
            $lock?->block(10);

            if ($cacheKey && ($replayed = $this->replay($cacheKey, $company, $setting))) {
                return $replayed;
            }

            return $this->run($request, $company, $setting, $validated, $returnUrl, $cancelUrl, $cacheKey);
        } catch (LockTimeoutException) {
            return $this->error('request_in_progress', 409, 'This checkout is already being processed.');
        } finally {
            $lock?->release();
        }
    }

    /** The customer is back from the payment gateway: verify the payment and place the order. */
    public function finalizePayment(Request $request, string $payment): JsonResponse
    {
        $data = $request->validate([
            'ref' => ['required', 'string', 'max:128'],
            'trx_id' => ['nullable', 'string', 'max:100'],
        ]);
        [$company, $setting] = $this->site($request);

        $model = ctype_digit($payment) ? StorefrontPayment::query()->find((int) $payment) : null;

        // Same answer for "no such payment" and "wrong ref": a ref is the only way in.
        if (! $model || ! Signing::matches(Signing::paymentRef($model), $data['ref'])) {
            return $this->error('payment_not_found', 404);
        }

        try {
            $order = $this->payments->verifyAndFinalize($model, $setting, transactionId: $data['trx_id'] ?? null);
        } catch (\Throwable $exception) {
            Log::warning('Storefront API payment return could not be finalized', ['payment' => $model->getKey(), 'error' => $exception->getMessage()]);
            $order = null;
        }

        if (! $order) {
            return $this->error('payment_not_completed', 422, 'The payment was not completed or could not be verified. No order was placed.');
        }

        $this->recordAccountActivity($request, $order);

        return response()->json(['data' => ['status' => 'placed', 'order' => OrderSummary::make($order, $company, $setting)]]);
    }

    /** An order summary for the success page; needs the unguessable token returned when the order was placed. */
    public function order(Request $request, string $orderNumber): JsonResponse
    {
        [$company, $setting] = $this->site($request);

        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->where('source', Order::SOURCE_STOREFRONT)
            ->first();

        if (! $order || ! Signing::matches(Signing::orderToken($order), $request->query('token'))) {
            return $this->error('order_not_found', 404);
        }

        return response()->json(['data' => OrderSummary::make($order, $company, $setting)]);
    }

    // ------------------------------------------------------------------ internals

    /**
     * When the order was placed by a signed-in customer (the website sends the
     * customer token along), it also shows in their account activity, as it does
     * on the Blade storefront. A missing or expired token never affects the order.
     */
    protected function recordAccountActivity(Request $request, Order $order): void
    {
        $resolved = $this->session->resolve($request);

        if (! $resolved || $resolved['customer']->getKey() !== $order->customer_id) {
            return;
        }

        $this->activities->record(
            $resolved['customer'],
            StorefrontCustomerActivity::TYPE_ORDER_PLACED,
            'Order placed',
            "Order {$order->order_number} was submitted successfully.",
            ['order_number' => $order->order_number, 'total_amount' => (float) $order->total_amount],
        );
    }

    protected function run(Request $request, Company $company, StorefrontSetting $setting, array $validated, string $returnUrl, string $cancelUrl, ?string $cacheKey): JsonResponse
    {
        $resolved = $this->resolver($company)->resolve($validated['items']);
        $address = trim($validated['address']);

        if ($resolved['lines']->isEmpty() || $resolved['issues'] !== []) {
            return $this->error(
                $resolved['lines']->isEmpty() ? 'cart_unavailable' : 'cart_changed',
                409,
                'Some items changed. Review the updated cart and try again.',
                ['quote' => $this->quotePayload($company, $setting, $resolved['lines'], $resolved['issues'], $address)],
            );
        }

        $totals = $this->checkout->totals($resolved['lines'], $address, $setting);

        if (isset($validated['expected_total']) && abs($totals['total'] - (float) $validated['expected_total']) > 0.009) {
            return $this->error('price_changed', 409, 'The total changed since you reviewed it. Review the new total and try again.', [
                'quote' => $this->quotePayload($company, $setting, $resolved['lines'], [], $address),
            ]);
        }

        $contact = $this->checkout->normalizeContact(Arr::only($validated, ['name', 'phone', 'email', 'address', 'note', 'payment_method', 'sender_number', 'trx_id']));
        $clientIp = $validated['client_ip'] ?? null;

        $outcome = $this->checkout->process(
            $company,
            $setting,
            $request,
            $resolved['lines'],
            $contact,
            fn (StorefrontPayment $payment): array => [$this->withPaymentRef($returnUrl, $payment), $cancelUrl],
            // Never fall back to the Next.js server's own IP: with no visitor IP, IP rules are skipped.
            clientIp: (string) ($clientIp ?? ''),
            metaContext: $this->metaTracking->contextFromClient($setting, $validated['meta'] ?? [], $clientIp, $validated['client_user_agent'] ?? null),
        );

        if ($outcome->placed()) {
            $this->recordAccountActivity($request, $outcome->order);
        }

        if ($cacheKey) {
            Cache::put($cacheKey, $outcome->placed()
                ? ['order' => $outcome->order->getKey()]
                : ['payment' => $outcome->payment->getKey(), 'url' => $outcome->paymentUrl, 'outcome' => $this->paymentData($outcome, $setting)], now()->addMinutes(30));
        }

        return $this->respond($outcome, $company, $setting);
    }

    protected function respond(CheckoutOutcome $outcome, Company $company, StorefrontSetting $setting): JsonResponse
    {
        if ($outcome->placed()) {
            return response()->json(['data' => ['status' => 'placed', 'order' => OrderSummary::make($outcome->order, $company, $setting)]], 201);
        }

        return response()->json(['data' => ['status' => 'payment_required', 'payment_url' => $outcome->paymentUrl] + $this->paymentData($outcome, $setting)]);
    }

    /** @return array{payment: array<string, mixed>} */
    protected function paymentData(CheckoutOutcome $outcome, StorefrontSetting $setting): array
    {
        $reasons = array_keys($outcome->decision['reasons'] ?? []);

        return ['payment' => [
            'id' => $outcome->payment->getKey(),
            'amount' => (float) $outcome->payment->amount,
            'reasons' => $reasons,
            // The admin's own explanation for the advance, as the Blade checkout shows it.
            'message' => match (true) {
                in_array('new_customer_delivery', $reasons, true) => $setting->customerAdvanceMessage(),
                in_array('courier_no_history', $reasons, true), in_array('courier_low_success_ratio', $reasons, true) => $setting->riskPaymentCustomerMessage(),
                default => null,
            },
        ]];
    }

    protected function replay(string $cacheKey, Company $company, StorefrontSetting $setting): ?JsonResponse
    {
        $previous = Cache::get($cacheKey);

        if (! is_array($previous)) {
            return null;
        }

        if (isset($previous['order']) && ($order = Order::query()->find($previous['order']))) {
            return response()->json(['data' => ['status' => 'placed', 'order' => OrderSummary::make($order, $company, $setting)]]);
        }

        if (isset($previous['payment']) && StorefrontPayment::query()->whereKey($previous['payment'])->where('status', StorefrontPayment::STATUS_PENDING)->exists()) {
            return response()->json(['data' => ['status' => 'payment_required', 'payment_url' => $previous['url']] + $previous['outcome']]);
        }

        Cache::forget($cacheKey);

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    protected function quotePayload(Company $company, StorefrontSetting $setting, Collection $lines, array $issues, ?string $address): array
    {
        $subtotal = (float) $lines->sum('subtotal');
        $vat = $lines->isEmpty() ? 0.0 : $setting->vatAmountForItems($lines);
        $rows = [];
        $totals = [];

        foreach (['inside', 'outside'] as $area) {
            $quote = $lines->isEmpty() ? null : $this->delivery->quote($lines, $area, $setting);
            $rows[$area] = $quote ? [
                'fee' => (float) $quote['fee'],
                'weight' => (float) $quote['weight'],
                'billed_weight' => (int) $quote['billed_weight'],
                'time' => $setting->deliveryTimeFor($area),
            ] : null;
            $totals[$area] = $quote ? round($subtotal + (float) $quote['fee'] + $vat, 2) : null;
        }

        $area = filled($address) ? $this->areas->resolve($address, $setting) : null;

        return [
            'lines' => $lines->map(fn (array $line): array => [
                'slug' => $line['product']->slug,
                'name' => $line['product']->name,
                'variant_id' => $line['variant']?->getKey(),
                'variant_label' => $line['variant']?->label(),
                'image_url' => Media::url(($line['variant']?->images[0] ?? null) ?: $line['product']->image, $company),
                'unit' => $line['product']->unit,
                'quantity' => (int) $line['quantity'],
                'unit_price' => (float) $line['unit_price'],
                'subtotal' => round((float) $line['subtotal'], 2),
                'moq' => $line['product']->effectiveMoq(),
                'is_preorder' => (bool) $line['product']->is_preorder,
            ])->values()->all(),
            'issues' => $issues,
            'subtotal' => round($subtotal, 2),
            'vat' => [
                'active' => $setting->vatActive(),
                'label' => $setting->vatDisplayLabel(),
                'inclusive' => $setting->vatInclusive(),
                'amount' => round($vat, 2),
                'included' => round($lines->isEmpty() ? 0.0 : $setting->vatIncludedInItems($lines), 2),
            ],
            'delivery' => $rows + ['area' => $area],
            'totals' => $totals,
            'total' => $area ? $totals[$area] : null,
            'advance' => [
                'preorder_due' => round(StorefrontCheckoutService::advanceDue($lines), 2),
                'new_customer_delivery_enabled' => (bool) ($setting->new_customer_delivery_advance_enabled ?? true),
                'risk_payment_enabled' => (bool) $setting->risk_payment_enabled,
            ],
            'online_payment_available' => $this->checkout->onlinePaymentAvailable($setting),
            'payment_methods' => $this->checkout->activePaymentMethods($setting)->map(fn (StorefrontPaymentMethod $method): array => [
                'value' => $method->paymentValue(),
                'type' => $method->type,
                'name' => $method->name,
                'account_number' => $method->type === StorefrontPaymentMethod::TYPE_MANUAL ? $method->account_number : null,
                'instructions' => $method->instructions,
            ])->values()->all(),
            'messages' => [
                'new_customer_advance' => ($setting->new_customer_delivery_advance_enabled ?? true) ? $setting->customerAdvanceMessage() : null,
                'risk_payment' => $setting->risk_payment_enabled ? $setting->riskPaymentCustomerMessage() : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.StorefrontApiCartResolver::MAX_LINES],
            'items.*.slug' => ['required', 'string', 'max:191'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.variant_id' => ['nullable', 'integer'],
        ];
    }

    protected function resolver(Company $company): StorefrontApiCartResolver
    {
        return new StorefrontApiCartResolver(new StorefrontCatalogService($company));
    }

    /** @return array{0: Company, 1: StorefrontSetting} */
    protected function site(Request $request): array
    {
        return [$this->context->company(), $request->attributes->get('storefront_setting')];
    }

    /**
     * A URL the customer's browser will be sent to after the gateway (or on
     * cancel) must be on this company's own Next.js site — otherwise an API
     * caller could turn a payment into a redirect to anywhere.
     */
    protected function siteUrl(string $url, StorefrontSetting $setting, string $field): string
    {
        $parts = parse_url($url) ?: [];
        $host = Company::normalizeDomain($parts['host'] ?? '');
        $site = Company::normalizeDomain($setting->nextjs_site_domain);
        $local = app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1'], true);
        $onSite = $site !== null && $host === $site && strtolower($parts['scheme'] ?? '') === 'https';

        if (! $local && ! $onSite) {
            throw ValidationException::withMessages([$field => 'The URL must be https and on the Next.js site domain set in ERP → Site → Storefront Settings → Next.js Site.']);
        }

        return $url;
    }

    protected function withPaymentRef(string $url, StorefrontPayment $payment): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['payment' => $payment->getKey(), 'ref' => Signing::paymentRef($payment)]);
    }

    /** @param  array<string, mixed>  $extra */
    protected function error(string $code, int $status, ?string $hint = null, array $extra = []): JsonResponse
    {
        return response()->json(array_filter(['ok' => false, 'error' => $code, 'hint' => $hint] + $extra, fn ($value) => $value !== null), $status);
    }
}
