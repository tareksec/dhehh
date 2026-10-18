<x-filament-panels::page>
    @php
        $report = $this->report();
        $summary = $report['summary'] ?? null;
        $th = 'py-1.5 pe-3 font-medium text-right';
        $td = 'py-1.5 pe-3 text-right';
    @endphp

    <div class="space-y-6">
        <x-filament::section :heading="__('Choose a supplier')">
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <label class="space-y-1 text-sm">
                    <span class="font-medium">{{ __('Supplier') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="supplierId">
                            <option value="">{{ __('Select a supplier') }}</option>
                            @foreach ($this->supplierOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium">{{ __('Product') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="productId">
                            <option value="">{{ __('All products') }}</option>
                            @foreach ($this->productOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium">{{ __('Purchased from') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="dateFrom" />
                    </x-filament::input.wrapper>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="font-medium">{{ __('Purchased to') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="dateTo" />
                    </x-filament::input.wrapper>
                </label>
            </div>

            @if ($this->pdfUrl())
                <div class="mt-4">
                    <x-filament::button tag="a" :href="$this->pdfUrl()" icon="heroicon-o-document-arrow-down">
                        {{ __('Download PDF') }}
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

        @if (! $report)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Select a supplier to see the report.') }}</p>
        @else
            <x-filament::section :heading="__('Supplier info')">
                <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Supplier') }}</dt><dd class="font-medium">{{ $report['supplier']['name'] }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Company') }}</dt><dd>{{ $report['supplier']['company_name'] ?: '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Phone') }}</dt><dd>{{ $report['supplier']['phone'] ?: '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Email') }}</dt><dd>{{ $report['supplier']['email'] ?: '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Address') }}</dt><dd>{{ $report['supplier']['address'] ?: '—' }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Payable to supplier') }}</dt><dd class="font-medium">{{ $this->money($report['supplier']['payable']) }}</dd></div>
                </dl>
            </x-filament::section>

            @if ($summary['purchase_count'] === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No received purchases from this supplier match these filters.') }}</p>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        [__('Purchases'), $this->number($summary['purchase_count']), $summary['first_date']?->format('d M Y').' – '.$summary['last_date']?->format('d M Y')],
                        [__('Total landed cost'), $this->money($summary['landed_cost']), __('Purchase :purchase + import :import', ['purchase' => $this->money($summary['purchase_value']), 'import' => $this->money($summary['import_cost'])])],
                        [__('Sales'), $this->money($summary['revenue']), __(':sold units sold', ['sold' => $this->number($summary['sold'])])],
                        [__('Average sale price'), $summary['avg_price'] === null ? '—' : $this->money($summary['avg_price']), __('per unit')],
                        [__('Short received'), $this->number($summary['short']), __('billed, never delivered')],
                        [__('Damaged'), $this->number($summary['damaged']), __('delivered, not sellable')],
                        [__('Gross profit'), $this->money($summary['gross_profit']), null],
                        [__('Net profit'), $this->money($summary['net_profit']), null],
                    ] as [$label, $value, $hint])
                        <x-filament::section>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                            <p class="mt-1 text-2xl font-semibold tracking-tight">{{ $value }}</p>
                            @if ($hint)
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                            @endif
                        </x-filament::section>
                    @endforeach
                </div>

                <x-filament::section :heading="__('How the profit is worked out')">
                    <table class="w-full max-w-xl text-sm">
                        <tbody>
                            <tr><td class="py-1">{{ __('Sales') }}</td><td class="py-1 text-right">{{ $this->money($summary['revenue']) }}</td></tr>
                            <tr><td class="py-1">− {{ __('Landed cost of units sold') }}</td><td class="py-1 text-right">{{ $this->money($summary['cogs']) }}</td></tr>
                            <tr><td class="py-1">− {{ __('Loss on short and damaged units') }}</td><td class="py-1 text-right">{{ $this->money($summary['loss']) }}</td></tr>
                            <tr class="border-t border-gray-200 font-semibold dark:border-white/10"><td class="py-1">{{ __('Gross profit') }}</td><td class="py-1 text-right">{{ $this->money($summary['gross_profit']) }}</td></tr>
                            @foreach ($summary['cost_lines'] as $label => $amount)
                                <tr><td class="py-1">− {{ $label }}</td><td class="py-1 text-right">{{ $this->money($amount) }}</td></tr>
                            @endforeach
                            <tr><td class="py-1">− {{ __('Ad cost (Meta ad spend)') }}</td><td class="py-1 text-right">{{ $this->money($summary['ad_cost']) }}</td></tr>
                            <tr class="border-t border-gray-200 font-semibold dark:border-white/10"><td class="py-1">{{ __('Net profit') }}</td><td class="py-1 text-right">{{ $this->money($summary['net_profit']) }}</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Sales are matched to purchases oldest first. Costs after arrival are counted in proportion to the units sold. Purchase discount and VAT are not part of the landed cost.') }}
                    </p>
                </x-filament::section>

                <x-filament::section :heading="__('Purchase dates')">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-gray-500 dark:text-gray-400">
                                    <th class="py-1.5 pe-3 text-left font-medium">{{ __('Date') }}</th>
                                    <th class="py-1.5 pe-3 text-left font-medium">{{ __('Purchase') }}</th>
                                    <th class="{{ $th }}">{{ __('Billed qty') }}</th>
                                    <th class="{{ $th }}">{{ __('Short') }}</th>
                                    <th class="{{ $th }}">{{ __('Damaged') }}</th>
                                    <th class="{{ $th }}">{{ __('Sold') }}</th>
                                    <th class="{{ $th }}">{{ __('Landed cost') }}</th>
                                    <th class="{{ $th }}">{{ __('Sales') }}</th>
                                    <th class="{{ $th }}">{{ __('Gross profit') }}</th>
                                    <th class="{{ $th }}">{{ __('Net profit') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['purchases'] as $row)
                                    <tr class="border-t border-gray-200 dark:border-white/10">
                                        <td class="py-1.5 pe-3">{{ $row['purchase_date']?->format('d M Y') }}</td>
                                        <td class="py-1.5 pe-3">{{ $row['purchase_number'] }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['ordered']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['short']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['damaged']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['sold']) }}</td>
                                        <td class="{{ $td }}">{{ $this->money($row['landed_cost']) }}</td>
                                        <td class="{{ $td }}">{{ $this->money($row['revenue']) }}</td>
                                        <td class="{{ $td }}">{{ $this->money($row['gross_profit']) }}</td>
                                        <td class="{{ $td }}">{{ $this->money($row['net_profit']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>

                <x-filament::section :heading="__('Products')">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-gray-500 dark:text-gray-400">
                                    <th class="py-1.5 pe-3 text-left font-medium">{{ __('Product') }}</th>
                                    <th class="{{ $th }}">{{ __('Billed qty') }}</th>
                                    <th class="{{ $th }}">{{ __('Short') }}</th>
                                    <th class="{{ $th }}">{{ __('Damaged') }}</th>
                                    <th class="{{ $th }}">{{ __('Sold') }}</th>
                                    <th class="{{ $th }}">{{ __('In stock') }}</th>
                                    <th class="{{ $th }}">{{ __('Landed cost') }}</th>
                                    <th class="{{ $th }}">{{ __('Sales') }}</th>
                                    <th class="{{ $th }}">{{ __('Average sale price') }}</th>
                                    <th class="{{ $th }}">{{ __('Costs after arrival') }}</th>
                                    <th class="{{ $th }}">{{ __('Gross profit') }}</th>
                                    <th class="{{ $th }}">{{ __('Net profit') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['products'] as $row)
                                    <tr class="border-t border-gray-200 dark:border-white/10">
                                        <td class="py-1.5 pe-3">{{ $row['product'] }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['ordered']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['short']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['damaged']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['sold']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['in_stock']) }}</td>
                                        <td class="{{ $td }}">{{ $this->money($row['landed_cost']) }}</td>
                                        <td class="{{ $td }}">{{ $this->money($row['revenue']) }}</td>
                                        <td class="{{ $td }}">{{ $row['avg_price'] === null ? '—' : $this->money($row['avg_price']) }}</td>
                                        <td class="{{ $td }}">{{ $this->money($row['extra_cost'] + $row['ad_cost']) }}</td>
                                        <td class="{{ $td }}">{{ $this->money($row['gross_profit']) }}</td>
                                        <td class="{{ $td }}">{{ $this->money($row['net_profit']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
