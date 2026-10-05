<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
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
    /**
     * The company's active send-money / bank channels the reseller can pay into.
     *
     * @return Collection<int, StorefrontPaymentMethod>
     */
    public function paymentMethods(Company $company): Collection
    {
        return StorefrontPaymentMethod::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('type', StorefrontPaymentMethod::TYPE_MANUAL)
            ->where('is_active', true)
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
     * Records the reseller's advance payment for the whole order. A new one
     * is only accepted while there is no payment waiting or already verified.
     *
     * @param  array{payment_method_id: mixed, sender: string, transaction_id: string}  $payment
     * @param  string  $errorPrefix  form path the errors are keyed to
     */
    public function submitPayment(Order $order, array $payment, string $errorPrefix = 'data.'): StorefrontPayment
    {
        abort_unless($order->reseller_own_courier, 404);

        $methodId = (int) ($payment['payment_method_id'] ?? 0);

        if (! $this->paymentMethods($order->company)->contains(fn (StorefrontPaymentMethod $method): bool => (int) $method->getKey() === $methodId)) {
            throw ValidationException::withMessages([$errorPrefix.'payment_method_id' => __('Choose how you paid.')]);
        }

        $current = $order->resellerAdvancePayment()->first();

        if ($current && $current->status !== StorefrontPayment::STATUS_FAILED) {
            throw ValidationException::withMessages([$errorPrefix.'transaction_id' => __('A payment for this order is already waiting or verified.')]);
        }

        return StorefrontPayment::query()->create([
            'company_id' => $order->company_id,
            'order_id' => $order->getKey(),
            'gateway' => StorefrontPaymentMethod::TYPE_MANUAL,
            'storefront_payment_method_id' => $methodId,
            'purpose' => StorefrontPayment::PURPOSE_RESELLER_ADVANCE,
            'amount' => round((float) $order->total_amount, 2),
            'status' => StorefrontPayment::STATUS_PENDING,
            'payment_method' => trim($payment['sender']),
            'transaction_id' => trim($payment['transaction_id']),
        ]);
    }

    /**
     * Staff confirmed the money arrived: the payment is marked completed and
     * entered in the order's Payments History (into $accountId when the
     * company keeps money accounts), so the order shows as paid.
     */
    public function verifyPayment(StorefrontPayment $payment, ?int $accountId = null): void
    {
        DB::transaction(function () use ($payment, $accountId): void {
            $payment = StorefrontPayment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($payment->purpose !== StorefrontPayment::PURPOSE_RESELLER_ADVANCE || $payment->status !== StorefrontPayment::STATUS_PENDING) {
                return;
            }

            $payment->update(['status' => StorefrontPayment::STATUS_COMPLETED]);

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
        });
    }

    public function rejectPayment(StorefrontPayment $payment): void
    {
        if ($payment->purpose === StorefrontPayment::PURPOSE_RESELLER_ADVANCE && $payment->status === StorefrontPayment::STATUS_PENDING) {
            $payment->update(['status' => StorefrontPayment::STATUS_FAILED]);
        }
    }

    /** The parcel is packed with the reseller's parcel ID on it -- only after the payment is verified. */
    public function markPacked(Order $order): void
    {
        if (! $this->canMarkPacked($order)) {
            throw ValidationException::withMessages(['order' => __('The advance payment must be verified before the parcel is packed.')]);
        }

        $order->forceFill(['reseller_parcel_packed_at' => now()])->save();
    }

    public function canMarkPacked(Order $order): bool
    {
        return $order->reseller_own_courier
            && $order->reseller_parcel_packed_at === null
            && ! in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_RETURNED, Order::STATUS_REFUNDED], true)
            && $order->resellerAdvancePayment()->first()?->status === StorefrontPayment::STATUS_COMPLETED;
    }

    /** Short state for the reseller and staff: waiting / verified / rejected / not sent. */
    public function paymentState(Order $order): string
    {
        return match ($order->resellerAdvancePayment?->status) {
            StorefrontPayment::STATUS_PENDING => 'waiting',
            StorefrontPayment::STATUS_COMPLETED => 'verified',
            StorefrontPayment::STATUS_FAILED => 'rejected',
            default => 'missing',
        };
    }

    /** @return array<string, string> */
    public static function paymentStateLabels(): array
    {
        return [
            'waiting' => __('Waiting for verification'),
            'verified' => __('Verified'),
            'rejected' => __('Rejected — send again'),
            'missing' => __('Not sent'),
        ];
    }
}
