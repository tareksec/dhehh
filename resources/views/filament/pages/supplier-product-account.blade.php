@php
    $detail = $this->detail();
    $row = $detail['row'] ?? [];
    $cell = 'px-3 py-2 text-sm text-gray-950 dark:text-white';
    $head = 'px-3 py-2 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div>
            <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-m-arrow-left" :href="$this->backUrl()">
                {{ __('Back to supplier account') }}
            </x-filament::button>
        </div>

        {{-- This product's part of the account --}}
        <x-filament::section icon="heroicon-o-scale" :heading="__('This product in the account')">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    [__('Received (pcs)'), $this->number($row['received'] ?? 0)],
                    [__('Short (pcs)'), $this->number($row['short'] ?? 0)],
                    [__('Damage (pcs)'), $this->number($row['damage'] ?? 0)],
                    [__('Sold (pcs)'), $this->number($row['sold'] ?? 0)],
                    [__('Outside godown (pcs)'), $this->number($row['outside'] ?? 0)],
                    [__('Taken by supplier (pcs)'), $this->number($row['taken'] ?? 0)],
                    [__('Stock (pcs)'), $this->number($row['stock'] ?? 0)],
                    [__('Difference (pcs)'), $this->number($row['difference'] ?? 0)],
                    [__('Value received'), $this->money($row['value_received'] ?? 0)],
                    [__('Goods taken value'), $this->money($row['goods_taken'] ?? 0)],
                    [__('Customer advance'), $this->money($row['advance'] ?? 0)],
                    [__('Value after deductions'), $this->money(($row['value_received'] ?? 0) - ($row['goods_taken'] ?? 0) - ($row['advance'] ?? 0))],
                ] as [$label, $value])
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                {{ __('Cash, shipping and ad cost are given to the supplier as a whole, so they are not split by product.') }}
            </p>
        </x-filament::section>

        {{-- 1. Received --}}
        <x-filament::section icon="heroicon-o-inbox-arrow-down" :heading="__('1. Goods received')">
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr>
                            @foreach ([__('Date'), __('Receipt Number'), __('Cartons'), __('Pcs per carton'), __('Received (pcs)'), __('Short (pcs)'), __('Damage (pcs)'), __('Buy price (per pc)'), __('Value')] as $title)
                                <th class="{{ $head }}">{{ $title }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($detail['receipts'] as $item)
                            <tr>
                                <td class="{{ $cell }}">{{ $item->receipt->received_on->format('d M Y') }}@if ($item->receipt->is_opening) <span class="text-xs text-gray-500">({{ __('Opening receipt') }})</span>@endif</td>
                                <td class="{{ $cell }}">{{ $item->receipt->receipt_number }}</td>
                                <td class="{{ $cell }}">{{ $item->cartons ?? '-' }}</td>
                                <td class="{{ $cell }}">{{ $item->pcs_per_carton ?? '-' }}</td>
                                <td class="{{ $cell }}">{{ $this->number($item->quantity) }}</td>
                                <td class="{{ $cell }}">{{ $this->number($item->short_quantity) }}</td>
                                <td class="{{ $cell }}">{{ $this->number($item->damage_quantity) }}</td>
                                <td class="{{ $cell }}">{{ $this->money($item->unit_cost) }}</td>
                                <td class="{{ $cell }}">{{ $this->money($item->value()) }}</td>
                            </tr>
                        @empty
                            <tr><td class="{{ $cell }}" colspan="9">{{ __('Nothing received yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- 2. Sales by price --}}
        <x-filament::section icon="heroicon-o-shopping-bag" :heading="__('2. Sales by price')" :description="__('Delivered orders, after returns and line discount.')">
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr>
                            @foreach ([__('Price (per pc)'), __('Pieces'), __('Amount')] as $title)
                                <th class="{{ $head }}">{{ $title }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($detail['sales_by_price'] as $line)
                            <tr>
                                <td class="{{ $cell }}">{{ $this->money($line['price']) }}</td>
                                <td class="{{ $cell }}">{{ $this->number($line['quantity']) }}</td>
                                <td class="{{ $cell }}">{{ $this->money($line['amount']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="{{ $cell }}" colspan="3">{{ __('Nothing sold yet.') }}</td></tr>
                        @endforelse
                        @if (count($detail['sales_by_price']) > 0)
                            <tr class="font-semibold">
                                <td class="{{ $cell }}">{{ __('Total') }}</td>
                                <td class="{{ $cell }}">{{ $this->number(collect($detail['sales_by_price'])->sum('quantity')) }}</td>
                                <td class="{{ $cell }}">{{ $this->money(collect($detail['sales_by_price'])->sum('amount')) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- 3 and 4. Stock and damage --}}
        <x-filament::section icon="heroicon-o-cube" :heading="__('3. Stock and 4. Damage')">
            <p class="mb-3 text-sm text-gray-950 dark:text-white">
                {{ __('Stock (pcs)') }}: <span class="font-semibold">{{ $this->number($row['stock'] ?? 0) }}</span>
            </p>
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr>
                            @foreach ([__('Date'), __('Damage (pcs)'), __('Reason')] as $title)
                                <th class="{{ $head }}">{{ $title }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($detail['damage'] as $movement)
                            <tr>
                                <td class="{{ $cell }}">{{ $movement->created_at->format('d M Y') }}</td>
                                <td class="{{ $cell }}">{{ $this->number(abs($movement->quantity)) }}</td>
                                <td class="{{ $cell }}">{{ $movement->reason ?: $movement->note }}</td>
                            </tr>
                        @empty
                            <tr><td class="{{ $cell }}" colspan="3">{{ __('No damage recorded.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- 5. Outside the godown --}}
        <x-filament::section icon="heroicon-o-truck" :heading="__('5. Outside the godown')" :description="__('Our own orders that are approved or on the way, not delivered yet.')">
            <div class="flex flex-wrap gap-3">
                @forelse ($detail['pipeline'] as $status => $quantity)
                    <x-filament::badge color="gray" size="lg">
                        {{ __(\App\Models\Order::STATUSES[$status] ?? $status) }}: {{ $this->number($quantity) }}
                    </x-filament::badge>
                @empty
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing on the way.') }}</span>
                @endforelse
            </div>
        </x-filament::section>

        {{-- 6. Taken by the supplier --}}
        <x-filament::section icon="heroicon-o-clipboard-document-list" :heading="__('6. Taken by the supplier')">
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr>
                            @foreach ([__('Order Date'), __('Order Number'), __('Customer Name'), __('Pieces'), __('Supplier buy price (per pc)'), __('Off supplier account'), __('Status')] as $title)
                                <th class="{{ $head }}">{{ $title }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($detail['taken'] as $item)
                            <tr>
                                <td class="{{ $cell }}">{{ $item->order->order_date->format('d M Y') }}</td>
                                <td class="{{ $cell }}">{{ $item->order->order_number }}</td>
                                <td class="{{ $cell }}">{{ $item->order->customer_name ?: '-' }}</td>
                                <td class="{{ $cell }}">{{ $this->number($item->quantity) }}</td>
                                <td class="{{ $cell }}">{{ $this->money($item->supplier_unit_cost) }}</td>
                                <td class="{{ $cell }}">{{ $this->money($item->cost()) }}</td>
                                <td class="{{ $cell }}">{{ __(\App\Models\SupplierOrder::STATUSES[$item->order->status] ?? $item->order->status) }}</td>
                            </tr>
                        @empty
                            <tr><td class="{{ $cell }}" colspan="7">{{ __('The supplier took nothing yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- 7. Advance --}}
        <x-filament::section icon="heroicon-o-banknotes" :heading="__('7. Advance the supplier collected')" :description="__('Whole order advance; the amount counted for this product is the share of its lines.')">
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead>
                        <tr>
                            @foreach ([__('Order Date'), __('Order Number'), __('Customer Name'), __('Advance paid by customer')] as $title)
                                <th class="{{ $head }}">{{ $title }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($detail['advance'] as $order)
                            <tr>
                                <td class="{{ $cell }}">{{ $order->order_date->format('d M Y') }}</td>
                                <td class="{{ $cell }}">{{ $order->order_number }}</td>
                                <td class="{{ $cell }}">{{ $order->customer_name ?: '-' }}</td>
                                <td class="{{ $cell }}">{{ $this->money($order->advance_amount) }}</td>
                            </tr>
                        @empty
                            <tr><td class="{{ $cell }}" colspan="4">{{ __('No advance collected by the supplier.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
