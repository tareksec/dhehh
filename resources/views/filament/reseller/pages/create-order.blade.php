@php
    use App\Support\MoneyFormatter;
    $currency = $this->currency();
    $summary = $this->summary();
@endphp

<x-filament-panels::page>
    <form wire:submit="create" class="space-y-6">
        {{ $this->form }}

        @if ($summary)
            <x-filament::section :heading="__('Order summary')">
                <dl class="grid max-w-md grid-cols-2 gap-y-1 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Subtotal') }}</dt>
                    <dd class="text-right">{{ MoneyFormatter::currency($summary['subtotal'], $currency) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Delivery charge') }}</dt>
                    <dd class="text-right">{{ MoneyFormatter::currency($summary['shipping_fee'], $currency) }}</dd>
                    @if ($summary['vat'] > 0)
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('VAT') }}</dt>
                        <dd class="text-right">{{ MoneyFormatter::currency($summary['vat'], $currency) }}</dd>
                    @endif
                    <dt class="font-semibold">{{ __('Customer pays (cash on delivery)') }}</dt>
                    <dd class="text-right font-semibold">{{ MoneyFormatter::currency($summary['total'], $currency) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Your commission') }}</dt>
                    <dd class="text-right">{{ MoneyFormatter::currency($summary['commission'], $currency) }}</dd>
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
