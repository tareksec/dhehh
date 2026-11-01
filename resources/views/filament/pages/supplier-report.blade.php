<x-filament-panels::page>
    @php
        $report = $this->report();
        $summary = $report['summary'] ?? null;
        $th = 'py-1.5 ps-3 pe-3 font-medium text-right align-bottom';
        $td = 'py-1.5 ps-3 pe-3 text-right';
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
                            <tr><td class="py-1">− {{ __('Loss on units damaged after arrival') }}</td><td class="py-1 text-right">{{ $this->money($summary['loss_after']) }}</td></tr>
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

                <x-filament::section :heading="__('Products overview')">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-gray-500 dark:text-gray-400">
                                    <th class="py-1.5 pe-3 text-left font-medium">{{ __('Product') }}</th>
                                    <th class="{{ $th }}">{{ __('Billed qty') }}</th>
                                    <th class="{{ $th }}">{{ __('Short') }}</th>
                                    <th class="{{ $th }}">{{ __('Damaged') }}</th>
                                    <th class="{{ $th }}">{{ __('Damaged after arrival') }}</th>
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
                                        <td class="py-1.5 pe-3"><a href="#product-{{ $row['product_id'] }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['product'] }}</a></td>
                                        <td class="{{ $td }}">{{ $this->number($row['ordered']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['short']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['damaged']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($row['damaged_after']) }}</td>
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

                @foreach ($report['product_pages'] as $page)
                    @php
                        $units = $page['units'];
                        $totals = $page['totals'];
                        $split = $page['sales_split'];
                        $check = $page['stock_check'];
                        $unitRows = [
                            [__('Billed by the supplier'), $units['billed'], true, null],
                            ['− '.__('Short (billed, never delivered)'), $units['short'], false, null],
                            ['− '.__('Damaged on arrival'), $units['damaged'], false, null],
                            [__('Received in good condition'), $units['received'], true, null],
                            ['− '.__('Sold to customers'), $units['sold'], false, null],
                            ['− '.__('Damaged after arrival'), $units['damaged_after'], false, $units['damage_sources']],
                            ['− '.__('Taken out by stock adjustments'), $units['adjusted_out'], false, null],
                            [__('In stock now (this supplier)'), $units['in_stock'], true, null],
                        ];
                    @endphp

                    <x-filament::section :heading="$page['product']" :description="$page['sku'] ? __('SKU').': '.$page['sku'] : null" id="product-{{ $page['product_id'] }}">
                        <div class="grid gap-6 lg:grid-cols-2">
                            <div>
                                <h3 class="mb-2 text-sm font-semibold">{{ __('Units account') }}</h3>
                                <table class="w-full text-sm">
                                    <tbody>
                                        @foreach ($unitRows as [$label, $value, $strong, $sources])
                                            <tr class="{{ $strong ? 'border-t border-gray-200 font-semibold dark:border-white/10' : '' }}">
                                                <td class="py-1">{{ $label }}</td>
                                                <td class="py-1 text-right">{{ $this->number($value) }}</td>
                                            </tr>
                                            @foreach ($sources ?? [] as $source => $pieces)
                                                <tr class="text-gray-500 dark:text-gray-400">
                                                    <td class="py-0.5 ps-5 text-xs">{{ $report['damage_labels'][$source] ?? $source }}</td>
                                                    <td class="py-0.5 text-right text-xs">{{ $this->number($pieces) }}</td>
                                                </tr>
                                            @endforeach
                                        @endforeach
                                    </tbody>
                                </table>
                                <p class="mt-2 text-xs {{ $units['difference'] === 0 ? 'text-success-600 dark:text-success-400' : 'font-semibold text-danger-600 dark:text-danger-400' }}">
                                    {{ __('Check') }}: {{ $this->number($units['billed']) }} = {{ $this->number($units['short']) }} + {{ $this->number($units['damaged']) }} + {{ $this->number($units['sold']) }} + {{ $this->number($units['damaged_after']) }} + {{ $this->number($units['adjusted_out']) }} + {{ $this->number($units['in_stock']) }}
                                    {{ $units['difference'] === 0 ? '✓' : '✗ ('.$this->number($units['difference']).')' }}
                                </p>
                            </div>

                            <div>
                                <h3 class="mb-2 text-sm font-semibold">{{ __('Sold at these prices') }}</h3>
                                @if ($page['prices'] === [])
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing sold yet.') }}</p>
                                @else
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-gray-500 dark:text-gray-400">
                                                <th class="py-1.5 pe-3 text-left font-medium">{{ __('Price per piece') }}</th>
                                                <th class="{{ $th }}">{{ __('Pieces') }}</th>
                                                <th class="{{ $th }}">{{ __('Amount') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($page['prices'] as $price)
                                                <tr class="border-t border-gray-200 dark:border-white/10">
                                                    <td class="py-1 pe-3">{{ $this->money($price['price']) }}</td>
                                                    <td class="{{ $td }}">{{ $this->number($price['pieces']) }}</td>
                                                    <td class="{{ $td }}">{{ $this->money($price['amount']) }}</td>
                                                </tr>
                                            @endforeach
                                            <tr class="border-t border-gray-200 font-semibold dark:border-white/10">
                                                <td class="py-1 pe-3">{{ __('Total') }} ({{ __('average') }} {{ $this->money($totals['avg_price']) }})</td>
                                                <td class="{{ $td }}">{{ $this->number($totals['sold']) }}</td>
                                                <td class="{{ $td }}">{{ $this->money($totals['revenue']) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                @endif

                                <h3 class="mb-2 mt-4 text-sm font-semibold">{{ __('Where the sales of this product came from') }}</h3>
                                <table class="w-full text-sm">
                                    <tbody>
                                        <tr><td class="py-1">{{ __('All sales of this product') }}</td><td class="py-1 text-right">{{ $this->number($split['all']) }}</td></tr>
                                        <tr><td class="py-1">− {{ __('From this supplier') }}</td><td class="py-1 text-right">{{ $this->number($split['this_supplier']) }}</td></tr>
                                        <tr><td class="py-1">− {{ __('From opening stock, other suppliers or other purchase dates') }}</td><td class="py-1 text-right">{{ $this->number($split['other_lots']) }}</td></tr>
                                        <tr><td class="py-1">− {{ __('Not matched to any recorded stock') }}</td><td class="py-1 text-right">{{ $this->number($split['unmatched']) }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="mt-6 overflow-x-auto">
                            <h3 class="mb-2 text-sm font-semibold">{{ __('Purchases of this product') }}</h3>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-gray-500 dark:text-gray-400">
                                        <th class="py-1.5 pe-3 text-left font-medium">{{ __('Date') }}</th>
                                        <th class="py-1.5 pe-3 text-left font-medium">{{ __('Purchase') }}</th>
                                        <th class="{{ $th }}">{{ __('Billed qty') }}</th>
                                        <th class="{{ $th }}">{{ __('Unit cost') }}</th>
                                        <th class="{{ $th }}">{{ __('Short') }}</th>
                                        <th class="{{ $th }}">{{ __('Damaged') }}</th>
                                        <th class="{{ $th }}">{{ __('Received good') }}</th>
                                        <th class="{{ $th }}">{{ __('Sold') }}</th>
                                        <th class="{{ $th }}">{{ __('Damaged after arrival') }}</th>
                                        <th class="{{ $th }}">{{ __('Adjusted out') }}</th>
                                        <th class="{{ $th }}">{{ __('In stock') }}</th>
                                        <th class="{{ $th }}">{{ __('Landed cost') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($page['batches'] as $batch)
                                        <tr class="border-t border-gray-200 dark:border-white/10">
                                            <td class="py-1.5 pe-3">{{ $batch['purchase_date']?->format('d M Y') }}</td>
                                            <td class="py-1.5 pe-3">{{ $batch['purchase_number'] }}</td>
                                            <td class="{{ $td }}">{{ $this->number($batch['ordered']) }}</td>
                                            <td class="{{ $td }}">{{ $this->money($batch['unit_cost']) }}</td>
                                            <td class="{{ $td }}">{{ $this->number($batch['short']) }}</td>
                                            <td class="{{ $td }}">{{ $this->number($batch['damaged']) }}</td>
                                            <td class="{{ $td }}">{{ $this->number($batch['sellable']) }}</td>
                                            <td class="{{ $td }}">{{ $this->number($batch['sold']) }}</td>
                                            <td class="{{ $td }}">{{ $this->number($batch['damaged_after']) }}</td>
                                            <td class="{{ $td }}">{{ $this->number($batch['adjusted_out']) }}</td>
                                            <td class="{{ $td }}">{{ $this->number($batch['in_stock']) }}</td>
                                            <td class="{{ $td }}">{{ $this->money($batch['landed_cost']) }}</td>
                                        </tr>
                                    @endforeach
                                    <tr class="border-t border-gray-200 font-semibold dark:border-white/10">
                                        <td class="py-1.5 pe-3" colspan="2">{{ __('Total') }}</td>
                                        <td class="{{ $td }}">{{ $this->number($totals['ordered']) }}</td>
                                        <td class="{{ $td }}"></td>
                                        <td class="{{ $td }}">{{ $this->number($totals['short']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($totals['damaged']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($totals['sellable']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($totals['sold']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($totals['damaged_after']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($totals['adjusted_out']) }}</td>
                                        <td class="{{ $td }}">{{ $this->number($totals['in_stock']) }}</td>
                                        <td class="{{ $td }}">{{ $this->money($totals['landed_cost']) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-6 grid gap-6 lg:grid-cols-2">
                            <div>
                                <h3 class="mb-2 text-sm font-semibold">{{ __('Profit on this product') }}</h3>
                                <table class="w-full text-sm">
                                    <tbody>
                                        <tr><td class="py-1">{{ __('Sales') }}</td><td class="py-1 text-right">{{ $this->money($totals['revenue']) }}</td></tr>
                                        <tr><td class="py-1">− {{ __('Landed cost of units sold') }}</td><td class="py-1 text-right">{{ $this->money($totals['cogs']) }}</td></tr>
                                        <tr><td class="py-1">− {{ __('Loss on short and damaged units') }}</td><td class="py-1 text-right">{{ $this->money($totals['loss']) }}</td></tr>
                                        <tr><td class="py-1">− {{ __('Loss on units damaged after arrival') }}</td><td class="py-1 text-right">{{ $this->money($totals['loss_after']) }}</td></tr>
                                        <tr class="border-t border-gray-200 font-semibold dark:border-white/10"><td class="py-1">{{ __('Gross profit') }}</td><td class="py-1 text-right">{{ $this->money($totals['gross_profit']) }}</td></tr>
                                        @foreach ($totals['cost_lines'] as $label => $amount)
                                            <tr><td class="py-1">− {{ $label }}</td><td class="py-1 text-right">{{ $this->money($amount) }}</td></tr>
                                        @endforeach
                                        <tr><td class="py-1">− {{ __('Ad cost (Meta ad spend)') }}</td><td class="py-1 text-right">{{ $this->money($totals['ad_cost']) }}</td></tr>
                                        <tr class="border-t border-gray-200 font-semibold dark:border-white/10"><td class="py-1">{{ __('Net profit') }}</td><td class="py-1 text-right">{{ $this->money($totals['net_profit']) }}</td></tr>
                                    </tbody>
                                </table>
                            </div>

                            <div>
                                <h3 class="mb-2 text-sm font-semibold">{{ __('Stock today') }}</h3>
                                <table class="w-full text-sm">
                                    <tbody>
                                        <tr><td class="py-1">{{ __('In stock from this supplier') }}</td><td class="py-1 text-right">{{ $this->number($check['this_supplier']) }}</td></tr>
                                        <tr><td class="py-1">+ {{ __('In stock from opening stock, other suppliers or other purchase dates') }}</td><td class="py-1 text-right">{{ $this->number($check['other_lots']) }}</td></tr>
                                        <tr class="border-t border-gray-200 font-semibold dark:border-white/10"><td class="py-1">{{ __('Stock this report accounts for') }}</td><td class="py-1 text-right">{{ $this->number($check['reported']) }}</td></tr>
                                        <tr><td class="py-1">{{ __('Stock in the system now') }}</td><td class="py-1 text-right">{{ $this->number($check['system']) }}</td></tr>
                                        <tr class="border-t border-gray-200 font-semibold dark:border-white/10 {{ $check['difference'] === 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                                            <td class="py-1">{{ __('Difference') }}</td>
                                            <td class="py-1 text-right">{{ $this->number($check['difference']) }} {{ $check['difference'] === 0 ? '✓' : '' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                                @if ($check['difference'] !== 0)
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('A difference is stock the report cannot place on a supplier, for example returned orders not checked yet, imported order history or pooled stock.') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </x-filament::section>
                @endforeach
            @endif
        @endif
    </div>
</x-filament-panels::page>
