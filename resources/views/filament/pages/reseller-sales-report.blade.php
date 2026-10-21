@php($totals = $this->totals())

<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Resellers with orders') }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ number_format($totals['resellers']) }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Orders') }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ number_format($totals['orders']) }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __(':cancelled cancelled, :returned returned', ['cancelled' => $totals['cancelled'], 'returned' => $totals['returned']]) }}
            </p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Sales') }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ $this->money($totals['sales']) }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Reseller cost') }}: {{ $this->money($totals['cost']) }}</p>
        </x-filament::section>
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Reseller profit') }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ $this->money($totals['profit']) }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Payable') }}: {{ $this->money($totals['payable']) }} · {{ __('Pending delivery') }}: {{ $this->money($totals['pending']) }} · {{ __('Paid out') }}: {{ $this->money($totals['paid_out']) }}
            </p>
        </x-filament::section>
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Only confirmed, processing and completed orders count as sales. Orders are placed in the period by their order date; "Paid out" is what was paid to resellers in the period.') }}
    </p>

    {{ $this->table }}
</x-filament-panels::page>
