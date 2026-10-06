{{-- The reseller's deposit in the portal's top bar (ResellerPanelProvider::depositBadge()). --}}
<div
    class="flex items-center gap-1.5 rounded-lg bg-gray-50 px-3 py-1.5 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
    title="{{ __('Money you sent above your orders. It pays your next own-courier orders.') }}"
    data-reseller-deposit
>
    <x-filament::icon icon="heroicon-o-wallet" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
    <span class="hidden text-gray-500 dark:text-gray-400 sm:inline">{{ __('Deposit') }}</span>
    <span class="font-semibold">{{ \App\Support\MoneyFormatter::currency($balance, $currency) }}</span>
</div>
