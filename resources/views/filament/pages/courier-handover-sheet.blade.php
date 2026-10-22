<x-filament-panels::page>
    @php
        $sheet = $this->sheet();
        $currency = app(\App\Services\CompanyContext::class)->company()?->currency ?: 'BDT';
        $money = fn (float $amount): string => \App\Support\MoneyFormatter::currency($amount, $currency);
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-end gap-4">
                <label class="block text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('Courier') }}</span>
                    <select wire:model.live="providerId" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        @foreach ($this->providers() as $provider)
                            <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('Day') }}</span>
                    <input type="date" wire:model.live="date" class="mt-1 block rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                </label>
                @if ($sheet && $this->printUrl())
                    <x-filament::button tag="a" :href="$this->printUrl()" target="_blank" rel="noopener" icon="heroicon-o-printer">{{ __('Print sheet') }}</x-filament::button>
                @endif
            </div>
        </x-filament::section>

        @if ($sheet)
            <x-filament::section>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Parcels') }}</p>
                        <p class="text-3xl font-semibold tracking-tight">{{ $sheet['parcels'] }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Cash to collect') }}</p>
                        <p class="text-3xl font-semibold tracking-tight">{{ $money($sheet['cod_total']) }}</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                @if ($sheet['rows']->isEmpty())
                    <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No parcels were booked with this courier on this day.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="py-2 pr-3">#</th>
                                    <th class="py-2 pr-3">{{ __('Invoice') }}</th>
                                    <th class="py-2 pr-3">{{ __('Customer') }}</th>
                                    <th class="py-2 pr-3">{{ __('Phone') }}</th>
                                    <th class="py-2 pr-3">{{ __('Address') }}</th>
                                    <th class="py-2 pr-3 text-right">{{ __('Cash to collect') }}</th>
                                    <th class="py-2">{{ __('Consignment / tracking') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sheet['rows'] as $row)
                                    <tr class="border-t border-gray-200 align-top dark:border-white/10">
                                        <td class="py-2 pr-3">{{ $loop->iteration }}</td>
                                        <td class="py-2 pr-3 font-medium">{{ $row['invoice'] }}</td>
                                        <td class="py-2 pr-3">{{ $row['name'] }}</td>
                                        <td class="py-2 pr-3">{{ $row['phone'] }}</td>
                                        <td class="py-2 pr-3">{{ $row['address'] }}</td>
                                        <td class="py-2 pr-3 text-right">{{ $money($row['cod']) }}</td>
                                        <td class="py-2">{{ collect([$row['consignment'], $row['tracking']])->filter()->unique()->implode(' / ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @else
            <x-filament::section>
                <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('Set up a courier first.') }}</p>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
