@php
    use App\Models\Order;
    use App\Support\MoneyFormatter;
@endphp

<x-filament-panels::page>
    @php($reseller = $this->reseller())

    @if ($reseller->reseller_status === 'pending')
        <x-filament::section>
            <div class="flex flex-col items-center gap-3 py-8 text-center">
                <x-filament::icon icon="heroicon-o-clock" class="h-10 w-10 text-warning-500" />
                <h2 class="text-lg font-semibold">{{ __('Your application is being reviewed') }}</h2>
                <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">
                    {{ __('We will let you know once it is approved. This usually does not take long.') }}
                </p>
            </div>
        </x-filament::section>
    @elseif ($reseller->reseller_status === 'rejected')
        <x-filament::section>
            <div class="flex flex-col items-center gap-3 py-8 text-center">
                <x-filament::icon icon="heroicon-o-x-circle" class="h-10 w-10 text-danger-500" />
                <h2 class="text-lg font-semibold">{{ __('Your application was not approved') }}</h2>
                @if ($reseller->reseller_note)
                    <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $reseller->reseller_note }}</p>
                @endif
            </div>
        </x-filament::section>
    @else
        @php($currency = $this->currency())

        @unless ($this->hasStoreSlug())
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
                <p class="text-sm">{{ __('Your store link is not ready yet. Add products to your store to get one.') }}</p>
            </x-filament::section>
        @endunless

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            @php($balances = $this->balances())
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Available to withdraw') }}</div>
                <div class="text-xl font-semibold">{{ MoneyFormatter::currency($balances['available'], $currency) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Waiting for delivery') }}</div>
                <div class="text-xl font-semibold">{{ $this->waitingForDeliveryCount() }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('This month\'s orders') }}</div>
                <div class="text-xl font-semibold">{{ $this->thisMonthOrdersCount() }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('This month\'s sales') }}</div>
                <div class="text-xl font-semibold">{{ MoneyFormatter::currency($this->thisMonthSales(), $currency) }}</div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('This month\'s commission') }}</div>
                <div class="text-xl font-semibold">{{ MoneyFormatter::currency($this->thisMonthCommission(), $currency) }}</div>
            </x-filament::section>
        </div>

        @if ($this->hasStoreSlug())
            <x-filament::section>
                <div class="flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Your store is live at:') }}</div>
                        <div class="font-medium">{{ $this->storeUrl() }}</div>
                    </div>
                    <div class="flex gap-2">
                        <x-filament::button
                            size="sm"
                            color="gray"
                            x-on:click="window.navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($this->storeUrl()) }}); $tooltip({{ \Illuminate\Support\Js::from(__('Copied to clipboard')) }}, { theme: $store.theme, timeout: 1500 })"
                        >
                            {{ __('Copy link') }}
                        </x-filament::button>
                        <x-filament::button
                            tag="a"
                            size="sm"
                            color="success"
                            href="https://wa.me/?text={{ urlencode($this->storeUrl()) }}"
                            target="_blank"
                        >
                            {{ __('Share on WhatsApp') }}
                        </x-filament::button>
                        <x-filament::button
                            tag="a"
                            size="sm"
                            color="info"
                            href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($this->storeUrl()) }}"
                            target="_blank"
                        >
                            {{ __('Share on Facebook') }}
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif

        @livewire(\App\Filament\Reseller\Widgets\CommissionTrend::class)

        <x-filament::section>
            <x-slot name="heading">{{ __('Recent orders') }}</x-slot>

            @php($recentOrders = $this->recentOrders())

            @if ($recentOrders->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No orders yet.') }}</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="pb-1">{{ __('Order') }}</th>
                            <th class="pb-1">{{ __('Date') }}</th>
                            <th class="pb-1 text-right">{{ __('Sale amount') }}</th>
                            <th class="pb-1">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentOrders as $order)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                <td class="py-1">{{ $order->order_number }}</td>
                                <td class="py-1">{{ $order->order_date?->format('d M Y') }}</td>
                                <td class="py-1 text-right">{{ MoneyFormatter::currency($order->total_amount, $currency) }}</td>
                                <td class="py-1">{{ Order::WORKFLOW_STAGES[$order->workflowStage()] ?? Order::STATUSES[$order->status] ?? $order->status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
