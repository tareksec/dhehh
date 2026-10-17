<x-filament-panels::page>
    @php($months = $this->monthlyTotals())

    @if ($months !== [])
        <x-filament::section :heading="__('Monthly totals')" collapsible>
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="pb-1">{{ __('Month') }}</th>
                        <th class="pb-1 text-right">{{ __('Orders') }}</th>
                        <th class="pb-1 text-right">{{ __('Pending delivery') }}</th>
                        <th class="pb-1 text-right">{{ __('Payable') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($months as $month)
                        <tr class="border-t border-gray-100 dark:border-white/10">
                            <td class="py-1.5 font-medium">{{ $month['month'] }}</td>
                            <td class="py-1.5 text-right">{{ $month['orders'] }}</td>
                            <td class="py-1.5 text-right">{{ $month['pending'] }}</td>
                            <td class="py-1.5 text-right">{{ $month['payable'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
