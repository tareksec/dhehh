@php
    use App\Support\MoneyFormatter;
    $currency = $this->currency();
    $summary = $this->summary();
    $sent = app(\App\Services\ResellerOwnCourierService::class)->sentAmount($this->order());
@endphp

<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        @if ($summary)
            <x-filament::section :heading="__('Order summary')">
                <dl class="grid max-w-md grid-cols-2 gap-y-1 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Store price') }}</dt>
                    <dd class="text-right">{{ MoneyFormatter::currency($summary['subtotal'], $currency) }}</dd>
                    @if ($summary['packaging_fee'] > 0)
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Packaging charge') }}</dt>
                        <dd class="text-right">{{ MoneyFormatter::currency($summary['packaging_fee'], $currency) }}</dd>
                    @endif
                    @if ($summary['vat'] > 0)
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('VAT') }}</dt>
                        <dd class="text-right">{{ MoneyFormatter::currency($summary['vat'], $currency) }}</dd>
                    @endif
                    <dt class="font-semibold">{{ __('You pay the store') }}</dt>
                    <dd class="text-right font-semibold">{{ MoneyFormatter::currency($summary['total'], $currency) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Already sent') }}</dt>
                    <dd class="text-right">{{ MoneyFormatter::currency($sent, $currency) }}</dd>
                    @if ($summary['deposit_used'] > 0)
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('From your deposit') }}</dt>
                        <dd class="text-right">{{ MoneyFormatter::currency(-$summary['deposit_used'], $currency) }}</dd>
                    @endif
                    <dt class="font-semibold">{{ __('Send now') }}</dt>
                    <dd class="text-right font-semibold">{{ MoneyFormatter::currency($summary['to_send'], $currency) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Your customer\'s price (on their invoice)') }}</dt>
                    <dd class="text-right">{{ MoneyFormatter::currency($summary['customer_total'], $currency) }}</dd>
                </dl>
            </x-filament::section>
        @endif

        <div>
            <x-filament::button type="submit">
                {{ __('Save changes') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
