<?php

namespace App\Services;

use App\Jobs\CheckExternalCourierFraudJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StorefrontCartRecord;
use App\Models\StorefrontCheckoutAttempt;
use App\Models\StorefrontPayment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StorefrontOrderPlacementService
{
    public function __construct(
        protected CompanyContext $context,
        protected StorefrontCheckoutPolicyService $checkoutPolicies,
        protected StorefrontMetaDispatchService $metaDispatch,
    ) {}

    public function placePaidCheckout(StorefrontPayment $payment): Order
    {
        $company = $payment->company()->withoutGlobalScopes()->firstOrFail();
        $this->context->set($company);

        return DB::transaction(function () use ($payment, $company): Order {
            $lockedPayment = StorefrontPayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->getKey());

            if ($lockedPayment->order_id) {
                return Order::withoutGlobalScopes()->findOrFail($lockedPayment->order_id);
            }

            if ($lockedPayment->status !== StorefrontPayment::STATUS_COMPLETED) {
                throw new RuntimeException('The checkout advance has not been verified yet.');
            }

            $data = $lockedPayment->checkout_data;

            if (! is_array($data) || empty($data['items']) || empty($data['phone'])) {
                throw new RuntimeException('The pending checkout data is incomplete.');
            }

            $customer = Customer::query()
                ->whereIn('phone', $this->checkoutPolicies->phoneVariants($data['phone']))
                ->first() ?? new Customer(['phone' => $data['phone']]);
            $customer->fill([
                'name' => $data['name'],
                'email' => $data['email'] ?: $customer->email,
                'address' => $data['address'],
                'customer_type' => $customer->customer_type ?: 'regular',
                'customer_source' => 'website',
                'opening_balance' => $customer->opening_balance ?? 0,
                'is_active' => true,
            ]);
            $customer->save();

            $advanceReasonKeys = array_keys($data['advance_reasons'] ?? []);

            if ($advanceReasonKeys === ['full_online_payment']) {
                // The customer chose to pay the whole order online (a normal
                // checkout option, not a risk-driven advance) - say so
                // plainly rather than calling it an "advance".
                $advanceNote = 'Paid online in full';
            } else {
                $advanceReasonLabels = collect($advanceReasonKeys)
                    ->map(fn (string $reason): string => match ($reason) {
                        'preorder' => 'pre-order',
                        'new_customer_delivery' => 'new-customer delivery',
                        'courier_no_history', 'courier_low_success_ratio' => 'checkout eligibility',
                        default => 'checkout',
                    })
                    ->unique()
                    ->implode(', ');
                $advanceNote = $advanceReasonLabels !== ''
                    ? "Verified online advance paid ({$advanceReasonLabels})"
                    : 'Verified online advance paid';
            }

            $order = Order::query()->create([
                'storefront_setting_id' => $payment->storefront_setting_id,
                'customer_id' => $customer->getKey(),
                'reseller_customer_id' => $data['reseller_customer_id'] ?? null,
                'customer_name' => $customer->name,
                'order_date' => now()->toDateString(),
                'discount' => 0,
                'vat' => (float) ($data['vat'] ?? 0),
                'shipping_zone' => $data['delivery_area'],
                'shipping_fee' => (float) $data['shipping_fee'],
                'paid_amount' => 0,
                'status' => 'draft',
                'source' => Order::SOURCE_STOREFRONT,
                'note' => trim("Storefront checkout\nDelivery address: {$data['address']}\n{$advanceNote}\n".(($data['note'] ?? null) ? "Customer note: {$data['note']}" : '')),
            ]);

            foreach ($data['items'] as $snapshot) {
                $product = Product::query()->findOrFail($snapshot['product_id']);
                $variant = ! empty($snapshot['product_variant_id'])
                    ? ProductVariant::query()->where('product_id', $product->getKey())->findOrFail($snapshot['product_variant_id'])
                    : null;

                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    'product_id' => $product->getKey(),
                    'product_variant_id' => $variant?->getKey(),
                    'variant_label' => $variant?->label() ?: ($snapshot['variant_label'] ?? null),
                    'quantity' => (int) $snapshot['quantity'],
                    'unit_price' => (float) $snapshot['unit_price'],
                    'unit_cost' => (float) $snapshot['unit_cost'],
                    'reseller_base_price' => isset($snapshot['reseller_base_price']) ? (float) $snapshot['reseller_base_price'] : null,
                ]);
            }

            $order->refresh();
            $order->update(['paid_amount' => min((float) $lockedPayment->amount, (float) $order->total_amount)]);
            $lockedPayment->update(['order_id' => $order->getKey(), 'checkout_data' => null]);

            $cartRecordId = (int) ($data['cart_record_id'] ?? 0);

            if ($cartRecordId > 0) {
                StorefrontCartRecord::withoutGlobalScopes()
                    ->where('company_id', $company->getKey())
                    ->find($cartRecordId)
                    ?->markConverted($order);
            }

            $attemptId = (int) ($data['checkout_attempt_id'] ?? 0);
            $attempt = null;

            if ($attemptId > 0) {
                $attempt = StorefrontCheckoutAttempt::withoutGlobalScopes()
                    ->where('company_id', $company->getKey())
                    ->find($attemptId);
                $this->checkoutPolicies->markAccepted($attempt, $order);
            }

            CheckExternalCourierFraudJob::dispatch($order->getKey())->afterCommit();

            $this->metaDispatch->captureOrder(
                $order,
                $payment->siteSetting(),
                (array) ($data['meta_tracking_context'] ?? []),
                isset($data['courier_success_ratio']) ? (float) $data['courier_success_ratio'] : $attempt?->courier_success_ratio,
                $cartRecordId ?: null,
            );

            return $order->fresh(['items.product', 'customer', 'storefrontPayments']);
        });
    }
}
