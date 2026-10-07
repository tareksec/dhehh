@php
    use App\Support\MoneyFormatter;
@endphp

<x-filament-panels::page>
    @php
        $balances = $this->balances();
        $currency = $this->currency();
        $minimum = $this->minimumWithdrawal();
    @endphp

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Available to withdraw') }}</div>
            <div class="text-xl font-semibold">{{ MoneyFormatter::currency($balances['available'], $currency) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Waiting for delivery') }}</div>
            <div class="text-xl font-semibold">{{ MoneyFormatter::currency($balances['pending'], $currency) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Withdrawal in process') }}</div>
            <div class="text-xl font-semibold">{{ MoneyFormatter::currency($balances['in_process'], $currency) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('Total withdrawn') }}</div>
            <div class="text-xl font-semibold">{{ MoneyFormatter::currency($balances['withdrawn'], $currency) }}</div>
        </x-filament::section>
    </div>

    @if ($minimum > 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('Minimum withdrawal: :amount', ['amount' => MoneyFormatter::currency($minimum, $currency)]) }}
        </p>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
