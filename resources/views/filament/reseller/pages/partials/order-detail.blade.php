@php
    use App\Models\Order;
    use App\Models\ResellerCommission;
    use App\Support\MoneyFormatter;
@endphp

<div class="space-y-6">
    <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
        <div>
            <div class="text-gray-500 dark:text-gray-400">{{ __('Date') }}</div>
            <div class="font-medium">{{ $order->order_date?->format('d M Y') }}</div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">{{ __('Status') }}</div>
            <div class="font-medium">{{ Order::WORKFLOW_STAGES[$order->workflowStage()] ?? Order::STATUSES[$order->status] ?? $order->status }}</div>
        </div>
        <div>
            <div class="text-gray-500 dark:text-gray-400">{{ __('Sale amount') }}</div>
            <div class="font-medium">{{ MoneyFormatter::currency($order->buyerTotal(), $currency) }}</div>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-sm font-semibold">{{ __('Customer') }}</h3>
        <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Name') }}</div>
                <div class="font-medium">{{ $order->customer?->name ?? $order->customer_name }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Phone') }}</div>
                <div class="font-medium">{{ $order->customer?->phone ?? '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500 dark:text-gray-400">{{ __('Address') }}</div>
                <div class="font-medium">{{ $order->customer?->address ?? '—' }}</div>
            </div>
        </div>
    </div>

    <div>
        <h3 class="mb-2 text-sm font-semibold">{{ __('Items') }}</h3>
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="text-gray-500 dark:text-gray-400">
                    <th class="pb-1">{{ __('Product') }}</th>
                    <th class="pb-1 text-right">{{ __('Quantity') }}</th>
                    <th class="pb-1 text-right">{{ __('Your price') }}</th>
                    <th class="pb-1 text-right">{{ __('Subtotal') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td class="py-1">
                            {{ $item->product?->name ?? '—' }}
                            @if ($item->variant_label)
                                <span class="text-gray-500 dark:text-gray-400">({{ $item->variant_label }})</span>
                            @endif
                        </td>
                        <td class="py-1 text-right">{{ $item->quantity }}</td>
                        <td class="py-1 text-right">{{ MoneyFormatter::currency($item->buyerUnitPrice(), $currency) }}</td>
                        <td class="py-1 text-right">{{ MoneyFormatter::currency($item->buyerSubtotal(), $currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($order->reseller_own_courier)
        @php
            $courier = app(\App\Services\ResellerOwnCourierService::class);
            $outstanding = $courier->outstandingAmount($order);
            $paymentStatuses = [
                \App\Models\StorefrontPayment::STATUS_PENDING => __('Waiting for verification'),
                \App\Models\StorefrontPayment::STATUS_COMPLETED => __('Verified'),
                \App\Models\StorefrontPayment::STATUS_FAILED => __('Rejected'),
            ];
        @endphp
        <div>
            <h3 class="mb-2 text-sm font-semibold">{{ __('Own courier') }}</h3>
            <div class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                <div>
                    <div class="text-gray-500 dark:text-gray-400">{{ __('Courier name') }}</div>
                    <div class="font-medium">{{ $order->reseller_courier_name ?: '—' }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">{{ __('Parcel ID') }}</div>
                    <div class="font-medium">{{ $order->reseller_parcel_id }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">{{ __('Packed') }}</div>
                    <div class="font-medium">{{ $order->reseller_parcel_packed_at?->format('d M Y, h:i A') ?? __('Not yet') }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">{{ __('Advance payment') }}</div>
                    <div class="font-medium">{{ \App\Services\ResellerOwnCourierService::paymentStateLabels()[$courier->paymentState($order)] }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">{{ __('You pay the store') }}</div>
                    <div class="font-medium">{{ MoneyFormatter::currency($order->total_amount, $currency) }}</div>
                </div>
                @if ((float) $order->packaging_fee > 0)
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">{{ __('Packaging charge') }}</div>
                        <div class="font-medium">{{ MoneyFormatter::currency($order->packaging_fee, $currency) }}</div>
                    </div>
                @endif
                @if (($credited = $courier->creditedFrom($order)) > 0)
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">{{ __('Added to your deposit') }}</div>
                        <div class="font-medium">{{ MoneyFormatter::currency($credited, $currency) }}</div>
                    </div>
                @endif
                @if ($outstanding > 0 && $order->status !== Order::STATUS_CANCELLED)
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">{{ __('Still to send') }}</div>
                        <div class="font-medium">{{ MoneyFormatter::currency($outstanding, $currency) }}</div>
                    </div>
                @endif
            </div>

            @if ($order->resellerAdvancePayments->isNotEmpty())
                <table class="mt-3 w-full text-left text-sm">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="pb-1">{{ __('Transaction ID') }}</th>
                            <th class="pb-1">{{ __('Status') }}</th>
                            <th class="pb-1 text-right">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->resellerAdvancePayments as $payment)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                <td class="py-1">{{ $payment->gateway === \App\Services\ResellerOwnCourierService::GATEWAY_DEPOSIT ? __('From your deposit') : (isset(\App\Services\ResellerOwnCourierService::ONLINE_GATEWAYS[$payment->gateway]) ? __('Online (:gateway)', ['gateway' => \App\Services\ResellerOwnCourierService::ONLINE_GATEWAYS[$payment->gateway]]) : $payment->transaction_id) }}</td>
                                <td class="py-1">{{ $paymentStatuses[$payment->status] ?? $payment->status }}</td>
                                <td class="py-1 text-right">{{ MoneyFormatter::currency($payment->amount, $currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    @if (($returnCharge = app(\App\Services\ResellerOwnCourierService::class)->returnChargeOf($order)) > 0)
        <div>
            <h3 class="mb-2 text-sm font-semibold">{{ __('Return charge') }}</h3>
            <p class="text-sm">{{ __(':amount was taken from your deposit because this order was returned.', ['amount' => MoneyFormatter::currency($returnCharge, $currency)]) }}</p>
        </div>
    @endif

    <div>
        <h3 class="mb-2 text-sm font-semibold">{{ __('Commission') }}</h3>
        @if ($order->reseller_own_courier && ! $order->resellerCommission)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('None (own courier)') }}</p>
        @elseif ($order->resellerCommission)
            <p class="text-sm">
                {{ MoneyFormatter::currency($order->resellerCommission->amount, $currency) }}
                &mdash; {{ ResellerCommission::STATUSES[$order->resellerCommission->status] ?? $order->resellerCommission->status }}
            </p>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No wholesale rate') }}</p>
        @endif
    </div>

    @if ($order->statusTransitions->isNotEmpty())
        <div>
            <h3 class="mb-2 text-sm font-semibold">{{ __('Timeline') }}</h3>
            <ul class="space-y-1 text-sm">
                @foreach ($order->statusTransitions as $transition)
                    <li>
                        <span class="text-gray-500 dark:text-gray-400">{{ $transition->created_at?->format('d M Y, h:i A') }}</span>
                        &mdash;
                        {{ Order::STATUSES[$transition->to_status] ?? $transition->to_status }}
                        @if ($transition->to_delivery_status)
                            ({{ Order::DELIVERY_STATUSES[$transition->to_delivery_status] ?? $transition->to_delivery_status }})
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
