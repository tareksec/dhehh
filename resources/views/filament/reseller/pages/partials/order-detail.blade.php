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
            <div class="font-medium">{{ MoneyFormatter::currency($order->total_amount, $currency) }}</div>
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
                        <td class="py-1 text-right">{{ MoneyFormatter::currency($item->unit_price, $currency) }}</td>
                        <td class="py-1 text-right">{{ MoneyFormatter::currency($item->subtotal, $currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div>
        <h3 class="mb-2 text-sm font-semibold">{{ __('Commission') }}</h3>
        @if ($order->resellerCommission)
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
