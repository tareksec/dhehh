<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\ResellerDepositEntry;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orders a reseller sends with their own courier (owner's process): the
 * reseller books the parcel with their courier, gives the company the parcel
 * ID and pays the full amount in advance; the company checks the payment,
 * packs the parcel and puts the reseller's parcel ID on it.
 *
 * The advance is a manual StorefrontPayment (sender number + transaction ID,
 * the same as a storefront send-money payment), so it also shows on the
 * admin Payments list. Verifying it records the money in the order's
 * Payments History.
 */
class ResellerOwnCourierService
{
    /** StorefrontPayment::gateway of an advance paid from the reseller's deposit (no transaction to check). */
    public const GATEWAY_DEPOSIT = 'reseller_deposit';

    /** The "Paid to" choice that pays through the store's online payment gateway instead of a send-money number. */
    public const ONLINE = 'online';

    /** StorefrontPayment::gateway values of an advance paid through an online gateway. */
    public const ONLINE_GATEWAYS = ['zinipay' => 'ZiniPay', 'paystation' => 'PayStation'];

    /**
     * The send-money / bank channels a reseller can pay an advance into
     * (owner decision 2026-10-12): the store's manual methods the admin chose
     * in Reseller Settings, plus the methods that exist only for resellers.
     * Until the admin has saved a choice, every active manual store method
     * is offered, as before. A deactivated method is never offered.
     *
     * @return Collection<int, StorefrontPaymentMethod>
     */
    public function paymentMethods(Company $company): Collection
    {
        $chosen = StorefrontSetting::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('site_type', StorefrontSetting::SITE_BLADE)
            ->first()
            ?->reseller_payment_method_ids;

        return StorefrontPaymentMethod::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('type', StorefrontPaymentMethod::TYPE_MANUAL)
            ->where('is_active', true)
            ->where(function ($query) use ($chosen): void {
                $query->where('reseller_only', true);

                if ($chosen === null) {
                    $query->orWhere('reseller_only', false);
                } else {
                    $query->orWhere(fn ($store) => $store->where('reseller_only', false)->whereIn('id', array_map('intval', $chosen)));
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * The online payment gateway the store has switched on and set up
     * (Integrations > Payment Gateway), or null when it has none (owner
     * decision 2026-10-14: whichever gateway is active is offered too).
     */
    public function onlineGateway(Company $company): ?string
    {
        $setting = StorefrontSetting::bladeFor((int) $company->getKey());

        if (! $setting || ! $setting->online_payment_enabled) {
            return null;
        }

        $gateway = $setting->online_payment_gateway ?: 'zinipay';

        return array_key_exists($gateway, self::ONLINE_GATEWAYS)
            && app(PaymentGatewayResolver::class)->isConfigured($setting)
            ? $gateway
            : null;
    }

    /**
     * Everything a reseller can pick under "Paid to": the manual methods,
     * then the active online gateway.
     *
     * @return array<string, string>
     */
    public function paymentOptions(Company $company): array
    {
        $options = $this->paymentMethods($company)
            ->mapWithKeys(fn (StorefrontPaymentMethod $method): array => [
                (string) $method->getKey() => trim($method->name.($method->account_number ? ' — '.$method->account_number : '')),
            ])
            ->all();

        if ($gateway = $this->onlineGateway($company)) {
            $options[self::ONLINE] = __('Pay online (:gateway)', ['gateway' => self::ONLINE_GATEWAYS[$gateway]]);
        }

        return $options;
    }

    /** True when $payment was paid, or is to be paid, through an online gateway. */
    public function isOnline(StorefrontPayment $payment): bool
    {
        return array_key_exists((string) $payment->gateway, self::ONLINE_GATEWAYS);
    }

    /** The online payment the reseller started but has not completed, if any. */
    public function pendingOnlinePayment(Order $order): ?StorefrontPayment
    {
        return $order->resellerAdvancePayments()
            ->where('status', StorefrontPayment::STATUS_PENDING)
            ->whereIn('gateway', array_keys(self::ONLINE_GATEWAYS))
            ->latest('id')
            ->first();
    }

    /** True when the reseller already used $parcelId on another order that is not cancelled. */
    public function parcelIdIsTaken(int $resellerId, string $parcelId, ?int $exceptOrderId = null): bool
    {
        return Order::query()
            ->where('reseller_customer_id', $resellerId)
            ->where('reseller_own_courier', true)
            ->where('reseller_parcel_id', trim($parcelId))
            ->whereNotIn('status', [Order::STATUS_CANCELLED])
            ->when($exceptOrderId, fn ($query) => $query->whereKeyNot($exceptOrderId))
            ->exists();
    }

    /**
     * Records the reseller's advance payment for what is still unpaid: the
     * whole order at first, only the extra after an edit raised the total
     * (owner rule, 2026-10-06), or the rejected part again. Nothing is
     * accepted while payments already waiting or verified cover the total.
     *
     * The reseller may say they sent more (`amount`, blank = exactly what is
     * owed, never less): once verified, the part above the total goes to
     * their deposit (owner rule, 2026-10-06).
     *
     * @param  array{payment_method_id: mixed, sender: string, transaction_id: string, amount?: mixed}  $payment
     * @param  string  $errorPrefix  form path the errors are keyed to
     */
    public function submitPayment(Order $order, array $payment, string $errorPrefix = 'data.'): StorefrontPayment
    {
        abort_unless($order->reseller_own_courier, 404);

        if (($payment['payment_method_id'] ?? null) === self::ONLINE) {
            return $this->startOnlinePayment($order, $errorPrefix);
        }

        $methodId = (int) ($payment['payment_method_id'] ?? 0);

        if (! $this->paymentMethods($order->company)->contains(fn (StorefrontPaymentMethod $method): bool => (int) $method->getKey() === $methodId)) {
            throw ValidationException::withMessages([$errorPrefix.'payment_method_id' => __('Choose how you paid.')]);
        }

        // An online attempt that was never completed gives way to this one.
        $this->replaceOnlineAttempts($order);

        $owed = $this->outstandingAmount($order);

        if ($owed <= 0) {
            throw ValidationException::withMessages([$errorPrefix.'transaction_id' => __('A payment for this order is already waiting or verified.')]);
        }

        $amount = filled($payment['amount'] ?? null) ? round((float) $payment['amount'], 2) : $owed;

        if ($amount < $owed) {
            throw ValidationException::withMessages([$errorPrefix.'amount' => __('Send at least :amount.', ['amount' => MoneyFormatter::number($owed)])]);
        }

        return StorefrontPayment::query()->create([
            'company_id' => $order->company_id,
            'order_id' => $order->getKey(),
            'gateway' => StorefrontPaymentMethod::TYPE_MANUAL,
            'storefront_payment_method_id' => $methodId,
            'purpose' => StorefrontPayment::PURPOSE_RESELLER_ADVANCE,
            'amount' => $amount,
            'status' => StorefrontPayment::STATUS_PENDING,
            'payment_method' => trim($payment['sender']),
            'transaction_id' => trim($payment['transaction_id']),
        ]);
    }

    /**
     * Starts an online payment of what is still owed on $order: a hosted
     * invoice at the store's active gateway. The payment waits (pending)
     * until the gateway confirms it -- on the reseller's return or by its
     * webhook -- and is then recorded without staff checking it. An earlier
     * online attempt that never completed is replaced. The page URL to send
     * the reseller to is kept in the payment's payload.
     */
    public function startOnlinePayment(Order $order, string $errorPrefix = 'data.'): StorefrontPayment
    {
        $company = Company::query()->withoutGlobalScopes()->findOrFail($order->company_id);
        $gateway = $this->onlineGateway($company);
        $setting = StorefrontSetting::bladeFor((int) $company->getKey());

        if (! $gateway || ! $setting) {
            throw ValidationException::withMessages([$errorPrefix.'payment_method_id' => __('Online payment is not available. Choose a send-money number.')]);
        }

        $payment = DB::transaction(function () use ($order, $gateway, $errorPrefix): StorefrontPayment {
            // One reseller payment at a time on an order.
            Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $this->replaceOnlineAttempts($order);

            $owed = $this->outstandingAmount($order);

            if ($owed <= 0) {
                throw ValidationException::withMessages([$errorPrefix.'transaction_id' => __('A payment for this order is already waiting or verified.')]);
            }

            return StorefrontPayment::query()->create([
                'company_id' => $order->company_id,
                'order_id' => $order->getKey(),
                'gateway' => $gateway,
                'purpose' => StorefrontPayment::PURPOSE_RESELLER_ADVANCE,
                'amount' => $owed,
                'status' => StorefrontPayment::STATUS_PENDING,
            ]);
        });

        $reseller = Customer::withoutGlobalScopes()->findOrFail($order->reseller_customer_id);
        $returnUrl = URL::temporarySignedRoute('reseller.portal.orders.payment-return', now()->addHour(), ['payment' => $payment]);
        // The gateway calls the webhook on the store's own domain: the portal host serves only the portal.
        $webhookPath = route($gateway.'.webhook', $payment, false);
        $webhookUrl = filled($company->domain) ? 'https://'.$company->domain.$webhookPath : url($webhookPath);

        try {
            $created = app(PaymentGatewayResolver::class)->byKey($gateway)->createPayment(
                $setting,
                (float) $payment->amount,
                (string) $reseller->name,
                $reseller->email,
                (string) $reseller->phone,
                (string) ($reseller->address ?: 'N/A'),
                $returnUrl,
                $returnUrl,
                $webhookUrl,
                $payment->getKey().'-'.Str::random(10),
                ['payment_id' => $payment->getKey(), 'purpose' => StorefrontPayment::PURPOSE_RESELLER_ADVANCE],
            );
        } catch (\Throwable $exception) {
            $payment->update(['status' => StorefrontPayment::STATUS_FAILED, 'payload' => ['error' => $exception->getMessage()]]);
            Log::warning('Reseller online advance could not be started', ['payment' => $payment->getKey(), 'error' => $exception->getMessage()]);

            throw ValidationException::withMessages([$errorPrefix.'payment_method_id' => __('The secure payment page could not be opened. Please try again.')]);
        }

        $payment->update(['invoice_id' => $created['invoice_id'], 'payload' => ['payment_url' => $created['payment_url']]]);

        return $payment->refresh();
    }

    /** Online payments the reseller started and never completed are closed, so a new payment can be made. */
    protected function replaceOnlineAttempts(Order $order): void
    {
        $order->resellerAdvancePayments()
            ->reorder()
            ->where('status', StorefrontPayment::STATUS_PENDING)
            ->whereIn('gateway', array_keys(self::ONLINE_GATEWAYS))
            ->update(['status' => StorefrontPayment::STATUS_FAILED, 'payload' => json_encode(['replaced' => true])]);
    }

    /**
     * Enters an online advance the gateway confirmed in the order's Payments
     * History, once (called by StorefrontPaymentService when it marks the
     * payment completed), and credits anything above the total to the
     * reseller's deposit. Does nothing for any other payment.
     */
    public function recordOnlinePayment(StorefrontPayment $payment): void
    {
        if ($payment->purpose !== StorefrontPayment::PURPOSE_RESELLER_ADVANCE || ! $this->isOnline($payment)) {
            return;
        }

        $context = app(CompanyContext::class);
        $previous = $context->company();
        $context->set(Company::query()->withoutGlobalScopes()->findOrFail($payment->company_id));

        try {
            DB::transaction(function () use ($payment): void {
                $payment = StorefrontPayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->getKey());

                // The note carries the payment's id, so a repeated call (the
                // return and the webhook both arrive) enters it only once.
                $marker = '[payment #'.$payment->getKey().']';

                if ($payment->status !== StorefrontPayment::STATUS_COMPLETED
                    || OrderPayment::query()->where('order_id', $payment->order_id)->where('note', 'like', '%'.$marker.'%')->exists()) {
                    return;
                }

                $order = Order::withoutGlobalScopes()->findOrFail($payment->order_id);

                OrderPayment::query()->create([
                    'company_id' => $payment->company_id,
                    'order_id' => $order->getKey(),
                    'type' => OrderPayment::TYPE_ADVANCE,
                    'method' => 'other',
                    'amount' => $payment->amount,
                    'note' => trim('Reseller advance paid online ('.(self::ONLINE_GATEWAYS[$payment->gateway] ?? $payment->gateway).'). '
                        .($payment->payment_method ? $payment->payment_method.', ' : '')
                        .'transaction '.$payment->transaction_id.' '.$marker),
                ]);

                $this->creditOverpayment($order, $payment);
            });
        } finally {
            $context->set($previous);
        }
    }

    /**
     * Staff confirmed the money arrived: the payment is marked completed and
     * entered in the order's Payments History (into $accountId when the
     * company keeps money accounts), so the order shows as paid.
     *
     * $received is what actually arrived when it differs from what the
     * reseller said. Whatever the verified payments hold above the order's
     * total is credited to the reseller's deposit.
     */
    public function verifyPayment(StorefrontPayment $payment, ?int $accountId = null, ?float $received = null): void
    {
        DB::transaction(function () use ($payment, $accountId, $received): void {
            $payment = StorefrontPayment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($payment->purpose !== StorefrontPayment::PURPOSE_RESELLER_ADVANCE || $payment->status !== StorefrontPayment::STATUS_PENDING) {
                return;
            }

            if ($received !== null && round($received, 2) <= 0) {
                throw ValidationException::withMessages(['amount' => __('Enter the amount received.')]);
            }

            $payment->update([
                'status' => StorefrontPayment::STATUS_COMPLETED,
                'amount' => $received !== null ? round($received, 2) : $payment->amount,
            ]);

            OrderPayment::query()->create([
                'company_id' => $payment->company_id,
                'order_id' => $payment->order_id,
                'type' => OrderPayment::TYPE_ADVANCE,
                'method' => 'mobile_banking',
                'account_id' => $accountId,
                'amount' => $payment->amount,
                'note' => trim('Reseller advance payment. '
                    .($payment->paymentMethod?->name ? $payment->paymentMethod->name.', ' : '')
                    .'sender '.$payment->payment_method.', transaction '.$payment->transaction_id),
            ]);

            $this->creditOverpayment($payment->order()->firstOrFail(), $payment);
        });
    }

    /** What the reseller has in their deposit: credited overpayments minus what later orders used. */
    public function depositBalance(Customer $reseller): float
    {
        return round((float) ResellerDepositEntry::withoutGlobalScopes()
            ->where('company_id', $reseller->company_id)
            ->where('customer_id', $reseller->getKey())
            ->sum('amount'), 2);
    }

    /** The part of the deposit an order can use: never anything while a return charge has left it below zero. */
    public function spendableDeposit(Customer $reseller): float
    {
        return max(0.0, $this->depositBalance($reseller));
    }

    /**
     * Pays what is still owed on $order from the reseller's deposit (owner
     * rule: the deposit is used on their next orders), as a payment that
     * needs no checking. Returns the amount used.
     */
    public function applyDeposit(Order $order): float
    {
        if (! $order->reseller_own_courier || ! $order->reseller_customer_id) {
            return 0.0;
        }

        return DB::transaction(function () use ($order): float {
            // One reseller's deposit is used by one order at a time.
            $reseller = Customer::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->reseller_customer_id);
            $used = min($this->spendableDeposit($reseller), $this->outstandingAmount($order));

            if ($used <= 0) {
                return 0.0;
            }

            $payment = StorefrontPayment::query()->create([
                'company_id' => $order->company_id,
                'order_id' => $order->getKey(),
                'gateway' => self::GATEWAY_DEPOSIT,
                'purpose' => StorefrontPayment::PURPOSE_RESELLER_ADVANCE,
                'amount' => $used,
                'status' => StorefrontPayment::STATUS_COMPLETED,
                'payment_method' => 'Deposit',
            ]);

            ResellerDepositEntry::query()->create([
                'company_id' => $order->company_id,
                'customer_id' => $reseller->getKey(),
                'order_id' => $order->getKey(),
                'storefront_payment_id' => $payment->getKey(),
                'type' => ResellerDepositEntry::TYPE_USED,
                'amount' => -$used,
                'note' => 'Used for order '.$order->order_number,
            ]);

            // No account: this money already arrived with the earlier
            // order's payment, into that payment's account.
            OrderPayment::query()->create([
                'company_id' => $order->company_id,
                'order_id' => $order->getKey(),
                'type' => OrderPayment::TYPE_ADVANCE,
                'method' => 'other',
                'amount' => $used,
                'note' => 'Paid from the reseller\'s deposit.',
            ]);

            $order->unsetRelation('resellerAdvancePayments');

            return $used;
        });
    }

    /**
     * A cancelled order gives back what it took from the reseller's deposit
     * (owner rule, 2026-10-12). Only the part paid from the deposit returns
     * this way: money the reseller sent to the store is never refunded
     * automatically. Safe to call again -- it only returns what has not
     * come back yet. Returns the amount returned now.
     */
    public function refundDeposit(Order $order): float
    {
        if (! $order->reseller_own_courier || ! $order->reseller_customer_id || $order->status !== Order::STATUS_CANCELLED) {
            return 0.0;
        }

        return DB::transaction(function () use ($order): float {
            // Same lock as applyDeposit(): one deposit change at a time.
            if (! Customer::withoutGlobalScopes()->lockForUpdate()->find($order->reseller_customer_id)) {
                return 0.0;
            }

            $entries = ResellerDepositEntry::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('order_id', $order->getKey());
            $used = -(float) (clone $entries)->where('type', ResellerDepositEntry::TYPE_USED)->sum('amount');
            $returned = (float) (clone $entries)->where('type', ResellerDepositEntry::TYPE_REFUND)->sum('amount');
            $back = round($used - $returned, 2);

            if ($back <= 0) {
                return 0.0;
            }

            ResellerDepositEntry::query()->create([
                'company_id' => $order->company_id,
                'customer_id' => $order->reseller_customer_id,
                'order_id' => $order->getKey(),
                'type' => ResellerDepositEntry::TYPE_REFUND,
                'amount' => $back,
                'note' => 'Returned: order '.$order->order_number.' was cancelled',
            ]);

            return $back;
        });
    }

    /**
     * A returned order takes the return charge from the reseller's deposit
     * (owner rule, 2026-10-14): the amount set in Reseller Settings (or the
     * lower amount of the reseller's level, owner decision 2026-10-16), once per
     * order, for store-delivered and own-courier orders alike. Nothing else
     * comes back for a return. The deposit may end up below zero; later
     * credits make that up. Safe to call again -- it charges only once.
     * Returns the amount charged now.
     */
    public function chargeReturn(Order $order): float
    {
        if (! $order->reseller_customer_id || $order->status !== Order::STATUS_RETURNED) {
            return 0.0;
        }

        // The reseller's own level may set a lower charge than the company's (owner decision 2026-10-16).
        $reseller = Customer::withoutGlobalScopes()->with('resellerLevel')->find($order->reseller_customer_id);
        $charge = $reseller?->resellerReturnCharge() ?? 0.0;

        if ($charge <= 0) {
            return 0.0;
        }

        return DB::transaction(function () use ($order, $charge): float {
            // Same lock as applyDeposit(): one deposit change at a time.
            if (! Customer::withoutGlobalScopes()->lockForUpdate()->find($order->reseller_customer_id)) {
                return 0.0;
            }

            $charged = ResellerDepositEntry::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('order_id', $order->getKey())
                ->where('type', ResellerDepositEntry::TYPE_RETURN_CHARGE)
                ->exists();

            if ($charged) {
                return 0.0;
            }

            ResellerDepositEntry::query()->create([
                'company_id' => $order->company_id,
                'customer_id' => $order->reseller_customer_id,
                'order_id' => $order->getKey(),
                'type' => ResellerDepositEntry::TYPE_RETURN_CHARGE,
                'amount' => -$charge,
                'note' => 'Return charge: order '.$order->order_number.' was returned',
            ]);

            return $charge;
        });
    }

    /** What a returned $order took from the reseller's deposit as the return charge. */
    public function returnChargeOf(Order $order): float
    {
        return round(-(float) ResellerDepositEntry::withoutGlobalScopes()
            ->where('company_id', $order->company_id)
            ->where('order_id', $order->getKey())
            ->where('type', ResellerDepositEntry::TYPE_RETURN_CHARGE)
            ->sum('amount'), 2);
    }

    /** What a cancelled $order gave back to the reseller's deposit. */
    public function refundedTo(Order $order): float
    {
        return round((float) ResellerDepositEntry::query()
            ->where('order_id', $order->getKey())
            ->where('type', ResellerDepositEntry::TYPE_REFUND)
            ->sum('amount'), 2);
    }

    /** Credits the part of $order's verified payments above its total that is not credited yet. */
    protected function creditOverpayment(Order $order, StorefrontPayment $payment): void
    {
        $verified = (float) $order->resellerAdvancePayments()->where('status', StorefrontPayment::STATUS_COMPLETED)->sum('amount');
        $credited = (float) ResellerDepositEntry::query()
            ->where('order_id', $order->getKey())
            ->where('type', ResellerDepositEntry::TYPE_OVERPAYMENT)
            ->sum('amount');
        $extra = round($verified - (float) $order->total_amount - $credited, 2);

        if ($extra <= 0 || ! $order->reseller_customer_id) {
            return;
        }

        ResellerDepositEntry::query()->create([
            'company_id' => $order->company_id,
            'customer_id' => $order->reseller_customer_id,
            'order_id' => $order->getKey(),
            'storefront_payment_id' => $payment->getKey(),
            'type' => ResellerDepositEntry::TYPE_OVERPAYMENT,
            'amount' => $extra,
            'note' => 'Paid above the total of order '.$order->order_number,
        ]);
    }

    /** What $order's verified payments put into the reseller's deposit. */
    public function creditedFrom(Order $order): float
    {
        return round((float) ResellerDepositEntry::query()
            ->where('order_id', $order->getKey())
            ->where('type', ResellerDepositEntry::TYPE_OVERPAYMENT)
            ->sum('amount'), 2);
    }

    public function rejectPayment(StorefrontPayment $payment): void
    {
        if ($payment->purpose === StorefrontPayment::PURPOSE_RESELLER_ADVANCE && $payment->status === StorefrontPayment::STATUS_PENDING) {
            $payment->update(['status' => StorefrontPayment::STATUS_FAILED]);
        }
    }

    /**
     * The parcel is packed with the reseller's parcel ID on it -- only after
     * the payment is verified. A draft order is confirmed first, so the
     * packed products leave stock (a draft takes no stock); when stock is
     * short this fails with the products named and nothing is marked.
     */
    public function markPacked(Order $order): void
    {
        if (! $this->canMarkPacked($order)) {
            throw ValidationException::withMessages(['order' => __('The advance payment must be verified before the parcel is packed.')]);
        }

        DB::transaction(function () use ($order): void {
            if (! $order->isAccounted()) {
                app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED, null, 'reseller_parcel_packed');
            }

            Order::query()->whereKey($order->getKey())->firstOrFail()->forceFill(['reseller_parcel_packed_at' => now()])->save();
        });

        $order->refresh();
    }

    public function canMarkPacked(Order $order): bool
    {
        return $order->reseller_own_courier
            && $order->reseller_parcel_packed_at === null
            && ! in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_RETURNED, Order::STATUS_REFUNDED], true)
            && $this->paymentState($order->load('resellerAdvancePayments')) === 'verified';
    }

    /** Payments waiting or verified: what the reseller has sent that still counts. */
    public function sentAmount(Order $order): float
    {
        return round((float) $order->resellerAdvancePayments()
            ->whereIn('status', [StorefrontPayment::STATUS_PENDING, StorefrontPayment::STATUS_COMPLETED])
            ->sum('amount'), 2);
    }

    /** What the reseller still has to send for the order's current total. */
    public function outstandingAmount(Order $order): float
    {
        return max(0.0, round((float) $order->total_amount - $this->sentAmount($order), 2));
    }

    /**
     * Short state for the reseller and staff: verified (the whole total is
     * verified), rejected (the last one was not accepted and money is still
     * owed), waiting (a payment is being checked), short (less arrived than
     * was owed), paying (an online payment is not confirmed yet) or not sent.
     */
    public function paymentState(Order $order): string
    {
        $payments = $order->resellerAdvancePayments;
        $verified = round((float) $payments->where('status', StorefrontPayment::STATUS_COMPLETED)->sum('amount'), 2);

        if ($payments->isNotEmpty() && $verified >= round((float) $order->total_amount, 2)) {
            return 'verified';
        }

        $sent = round((float) $payments->whereIn('status', [StorefrontPayment::STATUS_PENDING, StorefrontPayment::STATUS_COMPLETED])->sum('amount'), 2);

        // The latest one was rejected and money is still owed: the reseller
        // has to send it again, even while an earlier payment is waiting.
        if ($payments->last()?->status === StorefrontPayment::STATUS_FAILED && $sent < round((float) $order->total_amount, 2)) {
            return 'rejected';
        }

        // The reseller started an online payment that the gateway has not confirmed yet.
        if ($payments->contains(fn (StorefrontPayment $payment): bool => $payment->status === StorefrontPayment::STATUS_PENDING && $this->isOnline($payment))) {
            return 'paying';
        }

        if ($payments->contains('status', StorefrontPayment::STATUS_PENDING)) {
            return 'waiting';
        }

        // Staff verified less than was owed (they correct the amount received).
        return $payments->contains('status', StorefrontPayment::STATUS_COMPLETED) ? 'short' : 'missing';
    }

    /**
     * The reseller may change the order from the portal until the store
     * verifies any of the advance (owner rule, 2026-10-06) -- and never once
     * the parcel is packed or the order is closed. Staff never change its
     * items (OrderForm).
     */
    public function canEdit(Order $order): bool
    {
        return $order->reseller_own_courier
            && $order->reseller_parcel_packed_at === null
            && ! in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_RETURNED, Order::STATUS_REFUNDED, Order::STATUS_COMPLETED], true)
            // A payment from the deposit needs no checking, so it does not lock the order.
            && ! $order->resellerAdvancePayments->contains(fn (StorefrontPayment $payment): bool => $payment->status === StorefrontPayment::STATUS_COMPLETED
                && $payment->gateway !== self::GATEWAY_DEPOSIT);
    }

    /** @return array<string, string> */
    public static function paymentStateLabels(): array
    {
        return [
            'waiting' => __('Waiting for verification'),
            'paying' => __('Waiting for the online payment'),
            'verified' => __('Verified'),
            'rejected' => __('Rejected — send again'),
            'short' => __('Part received — send the rest'),
            'missing' => __('Not sent'),
        ];
    }
}
