<?php

namespace App\Services;

use App\Jobs\CheckExternalCourierFraudJob;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StorefrontCheckoutAttempt;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The storefront checkout rules, in one place, for every front end: the Blade
 * storefront (session cart) and the headless Next.js site (API) both call
 * process(), so delivery fee, VAT, checkout policy (blocks/order limits),
 * pre-order / new-customer / courier-risk advances and online payment can
 * never drift apart. Prices are NOT decided here: the caller hands in priced
 * cart lines (same shape as StorefrontCart::items()), so the browser can never
 * choose a price.
 *
 * Session-only concerns (cart records, "convert cart on success") run only
 * when a StorefrontCart is passed; the API passes none.
 */
class StorefrontCheckoutService
{
    public function __construct(
        protected PaymentGatewayResolver $gateways,
        protected StorefrontCheckoutPolicyService $policies,
        protected StorefrontDeliveryService $delivery,
        protected StorefrontDeliveryAreaResolver $areas,
        protected StorefrontPaymentEligibilityService $eligibility,
        protected StorefrontMetaDispatchService $metaDispatch,
    ) {}

    /**
     * Advance amount payable online for pre-order lines: quantity beyond
     * current stock means the line is fulfilled as a pre-order, and its
     * full subtotal times the product's advance percent is due up front.
     */
    public static function advanceDue($items): float
    {
        return (float) collect($items)
            ->filter(fn (array $item): bool => ! ($item['variant'] ?? null)
                && $item['product']->is_preorder
                && $item['quantity'] > (int) $item['product']->stock)
            ->sum(fn (array $item): float => $item['subtotal'] * $item['product']->preorderAdvancePercent() / 100);
    }

    /**
     * The dashboard-managed, company-scoped checkout payment options —
     * any number of `cod`/`manual` rows plus at most one `online_gateway`
     * row, which is only included when the gateway it reuses actually has
     * credentials configured (a company can enable the row while its
     * gateway credentials are stale/missing, e.g. mid-switch between
     * ZiniPay and PayStation).
     */
    public function activePaymentMethods(StorefrontSetting $setting): Collection
    {
        return StorefrontPaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (StorefrontPaymentMethod $method): bool => $method->type !== StorefrontPaymentMethod::TYPE_ONLINE_GATEWAY
                || $this->gateways->isConfigured($setting))
            ->values();
    }

    public function onlinePaymentAvailable(StorefrontSetting $setting): bool
    {
        return $this->gateways->isConfigured($setting);
    }

    /** @return array<string, mixed> */
    public function contactRules(Collection $methods, ?string $selectedPayment): array
    {
        $isManualSelection = str_starts_with((string) $selectedPayment, StorefrontPaymentMethod::TYPE_MANUAL.':');

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['required', Rule::in($methods->map->paymentValue()->all())],
            'sender_number' => [$isManualSelection ? 'required' : 'nullable', 'string', 'max:20'],
            'trx_id' => [$isManualSelection ? 'required' : 'nullable', 'string', 'max:40'],
        ];
    }

    /** @param array<string, mixed> $data validated by contactRules() */
    public function normalizeContact(array $data): array
    {
        $data['phone'] = trim($data['phone']);
        $data['phone'] = $this->policies->normalizeLocalPhone($data['phone']) ?: $data['phone'];
        $data['email'] = $data['email'] ?? null;

        return $data;
    }

    /**
     * Delivery area/fee, VAT and the order total for these priced lines.
     *
     * @return array{area: string, quote: array, vat: float, subtotal: float, total: float}
     */
    public function totals(Collection $items, string $address, StorefrontSetting $setting): array
    {
        $area = $this->areas->resolve($address, $setting);
        $quote = $this->delivery->quote($items, $area, $setting);
        $vat = $setting->vatAmountForItems($items);
        $subtotal = (float) $items->sum('subtotal');

        return [
            'area' => $area,
            'quote' => $quote,
            'vat' => $vat,
            'subtotal' => $subtotal,
            'total' => $subtotal + (float) $quote['fee'] + $vat,
        ];
    }

    /**
     * Run the whole checkout for already validated contact/payment data.
     *
     * @param  array<string, mixed>  $data  contact + payment_method (+ sender_number/trx_id), validated and normalized
     * @param  Closure(StorefrontPayment): array{0: string, 1: string}  $paymentUrls  [return URL, cancel URL] for a hosted payment
     * @param  array<string, mixed>  $metaContext  Meta tracking context captured from the visitor (empty = none)
     * @param  string|null  $clientIp  the visitor's IP when the request comes from another server (the API), else request()->ip()
     *
     * @throws ValidationException when the checkout policy blocks the order, payment is unavailable or stock is short
     */
    public function process(
        Company $company,
        StorefrontSetting $setting,
        Request $request,
        Collection $items,
        array $data,
        Closure $paymentUrls,
        ?StorefrontCart $cart = null,
        ?string $clientIp = null,
        array $metaContext = [],
    ): CheckoutOutcome {
        $data['meta_tracking_context'] = $metaContext;
        $priced = $this->totals($items, $data['address'], $setting);
        $quote = $priced['quote'];
        $data['delivery_area'] = $priced['area'];
        $data['vat'] = $priced['vat'];
        $totalAmount = $priced['total'];

        $cart?->startCheckout($company, $totalAmount, $data);
        [$data, $attempt] = $this->policies->evaluate($request, $company, $setting, $data, $totalAmount, $clientIp);

        // A customer choosing to pay the full order online is orthogonal to
        // the mandatory-advance rules below (preorder/new-customer/courier
        // risk) — paying in full already covers whatever advance those rules
        // would otherwise require, so this always takes the online-payment
        // path regardless of the eligibility decision.
        if ($data['payment_method'] === StorefrontPaymentMethod::TYPE_ONLINE_GATEWAY) {
            $this->assertOnlinePaymentAvailable($setting, 'Online payment is not available right now.');
            $decision = $this->fullOnlinePaymentDecision($totalAmount);
            $this->policies->recordPaymentDecision($attempt, $decision);

            return $this->startAdvancePayment($company, $setting, $data, $items, $quote, $decision, $paymentUrls, $attempt, $cart);
        }

        $customer = Customer::query()->whereIn('phone', $this->policies->phoneVariants($data['phone']))->first();
        $decision = $this->eligibility->decide(
            $company,
            $setting,
            $data['phone'],
            $totalAmount,
            self::advanceDue($items),
            ! $customer && ($setting->new_customer_delivery_advance_enabled ?? true) ? (float) $quote['fee'] : 0,
            $customer,
        );
        $data['courier_success_ratio'] = $decision['courier_success_ratio'];
        $this->policies->recordPaymentDecision($attempt, $decision);

        if ($decision['required_advance'] > 0) {
            $this->assertOnlinePaymentAvailable($setting, 'This order requires a verified online advance before it can be placed.');

            return $this->startAdvancePayment($company, $setting, $data, $items, $quote, $decision, $paymentUrls, $attempt, $cart);
        }

        $order = $this->createOrder($company, $data, $items, (float) $quote['fee'], $cart, $setting);
        $this->policies->markAccepted($attempt, $order);

        return new CheckoutOutcome(order: $order, decision: $decision, attempt: $attempt);
    }

    public function assertOnlinePaymentAvailable(StorefrontSetting $setting, string $message): void
    {
        if (! $this->gateways->isConfigured($setting)) {
            throw ValidationException::withMessages(['payment' => $message.' No active online payment gateway is available right now.']);
        }
    }

    /**
     * Same shape as StorefrontPaymentEligibilityService::decide(), for the
     * customer's own choice to pay the full order online (a normal checkout
     * option, not a risk-driven mandatory advance).
     *
     * @return array{required_advance: float, reasons: array<string, float>, courier_success_ratio: float|null, courier_lookup_status: string}
     */
    public function fullOnlinePaymentDecision(float $totalAmount): array
    {
        return [
            'required_advance' => round(max(0, $totalAmount), 2),
            'reasons' => ['full_online_payment' => round(max(0, $totalAmount), 2)],
            'courier_success_ratio' => null,
            'courier_lookup_status' => 'not_applicable',
        ];
    }

    /**
     * Start one hosted checkout-advance payment without creating a Customer
     * or Order. The verified webhook/return flow places both atomically.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $decision
     */
    public function startAdvancePayment(
        Company $company,
        StorefrontSetting $setting,
        array $data,
        Collection $items,
        array $quote,
        array $decision,
        Closure $paymentUrls,
        ?StorefrontCheckoutAttempt $attempt = null,
        ?StorefrontCart $cart = null,
    ): CheckoutOutcome {
        $cart?->rememberContact($company, $data['phone'], $data['name']);
        $this->assertStock($items);

        $payment = StorefrontPayment::query()->create([
            'company_id' => $company->getKey(),
            'storefront_setting_id' => $setting->recordedSiteId(),
            'order_id' => null,
            'gateway' => $setting->online_payment_gateway ?: 'zinipay',
            'purpose' => StorefrontPayment::PURPOSE_CHECKOUT_ADVANCE,
            'amount' => $decision['required_advance'],
            'status' => StorefrontPayment::STATUS_PENDING,
            'checkout_data' => [
                ...$data,
                'shipping_fee' => (float) $quote['fee'],
                'vat' => (float) ($data['vat'] ?? 0),
                'total_weight' => (float) $quote['weight'],
                'billed_weight' => (int) $quote['billed_weight'],
                'checkout_attempt_id' => $attempt?->getKey(),
                'cart_record_id' => $cart?->recordId($company),
                'reseller_customer_id' => $cart?->orderResellerId($company, $items),
                'advance_reasons' => $decision['reasons'],
                'courier_lookup_status' => $decision['courier_lookup_status'],
                'courier_success_ratio' => $decision['courier_success_ratio'],
                'items' => collect($items)->map(fn (array $item): array => [
                    'product_id' => $item['product']->getKey(),
                    'product_variant_id' => ($item['variant'] ?? null)?->getKey(),
                    'variant_label' => ($item['variant'] ?? null)?->label(),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'unit_cost' => (float) (($item['variant'] ?? null)?->cost_price ?? $item['product']->cost_price ?? 0),
                    'reseller_base_price' => $item['reseller_base_price'] ?? null,
                ])->values()->all(),
            ],
        ]);

        [$redirectUrl, $cancelUrl] = $paymentUrls($payment);

        $gateway = $setting->online_payment_gateway ?: 'zinipay';
        // Used as PayStation's own invoice_number since PayStation never
        // echoes one back (ZiniPay ignores this and derives its own invoice
        // id instead). Deliberately NOT just the StorefrontPayment's own
        // primary key: PayStation tracks invoice numbers on its own side
        // permanently, independent of this app's database, so a local table
        // reset (migrate:fresh, demo refresh, restore) that restarts the
        // auto-increment sequence would resend an invoice_number PayStation
        // already saw before and gets rejected with "Duplicate invoice
        // number" — confirmed live in production. The random suffix makes
        // this unique regardless of local ID reuse; the payment id prefix
        // keeps it traceable back to the StorefrontPayment row.
        $merchantReference = $payment->getKey().'-'.Str::random(10);

        try {
            $created = $this->gateways->forSetting($setting)->createPayment(
                $setting,
                $decision['required_advance'],
                $data['name'],
                $data['email'],
                $data['phone'],
                $data['address'],
                $redirectUrl,
                $cancelUrl,
                webhookUrl: route($gateway.'.webhook', $payment),
                merchantReference: $merchantReference,
                metadata: ['payment_id' => $payment->getKey(), 'purpose' => StorefrontPayment::PURPOSE_CHECKOUT_ADVANCE],
            );
        } catch (\Throwable $exception) {
            $payment->update(['status' => StorefrontPayment::STATUS_FAILED, 'payload' => ['error' => $exception->getMessage()]]);
            Log::warning('Checkout advance payment creation failed', ['payment' => $payment->getKey(), 'error' => $exception->getMessage()]);

            throw ValidationException::withMessages(['payment' => 'The secure payment page could not be opened. No order was placed; please try again.']);
        }

        $payment->update(['invoice_id' => $created['invoice_id']]);
        $this->policies->markPendingPayment($attempt);

        return new CheckoutOutcome(payment: $payment, paymentUrl: $created['payment_url'], decision: $decision, attempt: $attempt);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    /**
     * @param  StorefrontSetting|null  $setting  The site the order is placed on. Omitted
     *                                           for the Blade storefront, whose settings are the company's own.
     */
    public function createOrder(Company $company, array $data, Collection $items, float $deliveryCharge, ?StorefrontCart $cart = null, ?StorefrontSetting $setting = null): Order
    {
        $setting ??= $company->storefrontSetting;

        // Keep the contact on the persisted cart so abandoned-cart
        // reminders can reach the customer if this checkout fails.
        $cart?->rememberContact($company, $data['phone'], $data['name']);

        return DB::transaction(function () use ($company, $data, $items, $deliveryCharge, $cart, $setting): Order {
            abort_if($items->isEmpty(), 422, 'Your cart is empty.');

            $this->assertStock($items);

            $customer = Customer::query()
                ->whereIn('phone', $this->policies->phoneVariants($data['phone']))
                ->first() ?? new Customer(['phone' => $data['phone']]);

            $customer->fill([
                'name' => $data['name'],
                'email' => $data['email'] ?? $customer->email,
                'address' => $data['address'],
                'customer_type' => $customer->customer_type ?: 'regular',
                'customer_source' => 'website',
                'opening_balance' => $customer->opening_balance ?? 0,
                'is_active' => true,
            ]);
            $customer->save();

            $order = Order::query()->create([
                'storefront_setting_id' => $setting?->recordedSiteId(),
                'customer_id' => $customer->getKey(),
                'reseller_customer_id' => $cart?->orderResellerId($company, $items),
                'customer_name' => $customer->name,
                'order_date' => now()->toDateString(),
                'discount' => 0,
                'vat' => (float) ($data['vat'] ?? 0),
                'shipping_zone' => $data['delivery_area'],
                'shipping_fee' => $deliveryCharge,
                'paid_amount' => 0,
                'status' => 'draft',
                'source' => Order::SOURCE_STOREFRONT,
                'note' => trim("Storefront checkout\nDelivery address: {$data['address']}\nPayment method: ".self::paymentMethodLabel($data['payment_method'])."\n".(($data['note'] ?? null) ? "Customer note: {$data['note']}" : '')),
            ]);

            foreach ($items as $item) {
                $variant = $item['variant'] ?? null;

                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    'product_id' => $item['product']->getKey(),
                    'product_variant_id' => $variant?->getKey(),
                    'variant_label' => $variant?->label(),
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => ($variant?->cost_price ?? $item['product']->cost_price) ?? 0,
                    'reseller_base_price' => $item['reseller_base_price'] ?? null,
                ]);
            }

            $order->refresh();
            $cartRecordId = $cart?->recordId($company);

            if (str_starts_with($data['payment_method'], StorefrontPaymentMethod::TYPE_MANUAL.':')) {
                StorefrontPayment::query()->create([
                    'company_id' => $company->getKey(),
                    'storefront_setting_id' => $setting?->recordedSiteId(),
                    'order_id' => $order->getKey(),
                    'gateway' => StorefrontPaymentMethod::TYPE_MANUAL,
                    'storefront_payment_method_id' => (int) substr($data['payment_method'], strlen(StorefrontPaymentMethod::TYPE_MANUAL.':')),
                    'amount' => round((float) $order->total_amount, 2),
                    'status' => StorefrontPayment::STATUS_PENDING,
                    'payment_method' => $data['sender_number'],
                    'transaction_id' => $data['trx_id'],
                ]);
            }

            $cart?->clear($company, $order);

            // Customer never sees this — the external check happens in the
            // background and only surfaces to staff as a review requirement
            // if the cross-courier success ratio is low (Part 3.8).
            CheckExternalCourierFraudJob::dispatch($order->getKey())->afterCommit();

            $this->metaDispatch->captureOrder(
                $order,
                $setting,
                (array) ($data['meta_tracking_context'] ?? []),
                isset($data['courier_success_ratio']) ? (float) $data['courier_success_ratio'] : null,
                $cartRecordId,
            );

            return $order;
        });
    }

    public function assertStock($items): void
    {
        abort_if(collect($items)->isEmpty(), 422, 'Your cart is empty.');

        foreach ($items as $item) {
            $variant = $item['variant'] ?? null;
            $availableStock = $variant ? (int) $variant->stock : (int) $item['product']->stock;

            if (! $variant && $item['product']->is_preorder) {
                continue;
            }

            abort_if($item['quantity'] > $availableStock, 422, "{$item['product']->name} does not have enough stock.");
        }
    }

    public static function paymentMethodLabel(string $paymentValue): string
    {
        if ($paymentValue === StorefrontPaymentMethod::TYPE_ONLINE_GATEWAY) {
            return 'Paid online in full';
        }

        if (str_starts_with($paymentValue, StorefrontPaymentMethod::TYPE_MANUAL.':')) {
            $methodId = (int) substr($paymentValue, strlen(StorefrontPaymentMethod::TYPE_MANUAL.':'));
            $name = StorefrontPaymentMethod::withoutGlobalScopes()->find($methodId)?->name;

            return $name ? "{$name} (Send Money, pending verification)" : 'Manual payment (pending verification)';
        }

        return 'Cash on Delivery';
    }
}
