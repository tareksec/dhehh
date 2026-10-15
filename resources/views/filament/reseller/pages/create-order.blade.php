@php
    use App\Support\MoneyFormatter;
    $currency = $this->currency();
    $summary = $this->summary();
    $ownCourier = $this->isOwnCourier();
@endphp

<x-filament-panels::page>
    <form wire:submit="create" class="space-y-6">
        {{ $this->form }}

        @if ($summary)
            <x-filament::section :heading="__('Order summary')">
                <dl class="grid max-w-md grid-cols-2 gap-y-1 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">{{ $ownCourier ? __('Store price') : __('Subtotal') }}</dt>
                    <dd class="text-right">{{ MoneyFormatter::currency($summary['subtotal'], $currency) }}</dd>
                    @unless ($ownCourier)
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Delivery charge') }}</dt>
                        <dd class="text-right">{{ MoneyFormatter::currency($summary['shipping_fee'], $currency) }}</dd>
                    @endunless
                    @if ($ownCourier && $summary['packaging_fee'] > 0)
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Packaging charge') }}</dt>
                        <dd class="text-right">{{ MoneyFormatter::currency($summary['packaging_fee'], $currency) }}</dd>
                    @endif
                    @if ($summary['vat'] > 0)
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('VAT') }}</dt>
                        <dd class="text-right">{{ MoneyFormatter::currency($summary['vat'], $currency) }}</dd>
                    @endif
                    @if ($ownCourier)
                        <dt class="font-semibold">{{ __('You pay in advance') }}</dt>
                        <dd class="text-right font-semibold">{{ MoneyFormatter::currency($summary['total'], $currency) }}</dd>
                        @if ($summary['deposit_used'] > 0)
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('From your deposit') }}</dt>
                            <dd class="text-right">{{ MoneyFormatter::currency(-$summary['deposit_used'], $currency) }}</dd>
                            <dt class="font-semibold">{{ __('Send now') }}</dt>
                            <dd class="text-right font-semibold">{{ MoneyFormatter::currency($summary['to_send'], $currency) }}</dd>
                        @endif
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Your customer\'s price (on their invoice)') }}</dt>
                        <dd class="text-right">{{ MoneyFormatter::currency($summary['customer_total'], $currency) }}</dd>
                    @else
                        <dt class="font-semibold">{{ __('Customer pays (cash on delivery)') }}</dt>
                        <dd class="text-right font-semibold">{{ MoneyFormatter::currency($summary['total'], $currency) }}</dd>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('My profit') }}</dt>
                        <dd class="text-right">{{ MoneyFormatter::currency($summary['commission'], $currency) }}</dd>
                    @endif
                </dl>
            </x-filament::section>
        @endif

        <div>
            <x-filament::button type="submit">
                {{ __('Create order') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
