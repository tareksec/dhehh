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

        $methodId = (int) ($payment['payment_method_id'] ?? 0);

        if (! $this->paymentMethods($order->company)->contains(fn (StorefrontPaymentMethod $method): bool => (int) $method->getKey() === $methodId)) {
            throw ValidationException::withMessages([$errorPrefix.'payment_method_id' => __('Choose how you paid.')]);
        }

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
            $used = min($this->depositBalance($reseller), $this->outstandingAmount($order));

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
     * was owed) or not sent.
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
            'verified' => __('Verified'),
            'rejected' => __('Rejected — send again'),
            'short' => __('Part received — send the rest'),
            'missing' => __('Not sent'),
        ];
    }
}
