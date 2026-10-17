@php
    use App\Support\MoneyFormatter;
@endphp

<x-filament-panels::page>
    @php($summary = $this->portal()->partnerSummary($this->account()))
    @php($currency = $this->currency())

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Total commission') }}</div>
            <div class="text-xl font-semibold">{{ MoneyFormatter::currency($summary['earned'], $currency) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Commission paid') }}</div>
            <div class="text-xl font-semibold">{{ MoneyFormatter::currency($summary['paid'], $currency) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Commission due') }}</div>
            <div class="text-xl font-semibold">{{ MoneyFormatter::currency($summary['due'], $currency) }}</div>
        </x-filament::section>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
