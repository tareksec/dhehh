<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section
            icon="heroicon-o-funnel"
            :heading="__('Report Filters')"
            :description="__('Choose a report and date range, then apply the filters.')"
        >
            <x-slot name="afterHeader">
                <x-filament::badge color="gray" icon="heroicon-m-list-bullet">
                    {{ __(':count rows', ['count' => number_format($this->activeReportCount())]) }}
                </x-filament::badge>
            </x-slot>

            <form method="GET" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <x-filament-forms::field-wrapper id="report_type" :label="__('Report')">
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="report_type" name="report_type" autocomplete="off">
                            @foreach ($this->reportOptions() as $type => $label)
                                <option value="{{ $type }}" @selected($reportType === $type)>{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </x-filament-forms::field-wrapper>

                @if ($this->definition()->usesAsOfDate())
                    <x-filament-forms::field-wrapper id="date_to" :label="__('As of')">
                        <x-filament::input.wrapper>
                            <x-filament::input
                                id="date_to"
                                name="date_to"
                                type="date"
                                value="{{ $dateTo }}"
                                autocomplete="off"
                            />
                        </x-filament::input.wrapper>
                    </x-filament-forms::field-wrapper>
                @elseif ($this->definition()->usesDateRange())
                    <x-filament-forms::field-wrapper id="date_from" :label="__('From')">
                        <x-filament::input.wrapper>
                            <x-filament::input
                                id="date_from"
                                name="date_from"
                                type="date"
                                value="{{ $dateFrom }}"
                                autocomplete="off"
                            />
                        </x-filament::input.wrapper>
                    </x-filament-forms::field-wrapper>

                    <x-filament-forms::field-wrapper id="date_to" :label="__('To')">
                        <x-filament::input.wrapper>
                            <x-filament::input
                                id="date_to"
                                name="date_to"
                                type="date"
                                value="{{ $dateTo }}"
                                autocomplete="off"
                            />
                        </x-filament::input.wrapper>
                    </x-filament-forms::field-wrapper>
                @endif

                @if ($this->definition()->usesSupplier())
                    <x-filament-forms::field-wrapper id="supplier_id" :label="__('Supplier')">
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="supplier_id" name="supplier_id" autocomplete="off">
                                <option value="">{{ __('All suppliers') }}</option>
                                @foreach ($this->supplierOptions() as $id => $name)
                                    <option value="{{ $id }}" @selected((int) $supplierId === (int) $id)>{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </x-filament-forms::field-wrapper>
                @endif

                <div class="flex items-end">
                    <x-filament::button type="submit" icon="heroicon-m-funnel" class="w-full">
                        {{ __('Apply Filters') }}
                    </x-filament::button>
                </div>
            </form>

            @if ($this->definition()->usesAsOfDate())
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('This report is a running balance: it adds up everything until the chosen date.') }}
                </p>
            @elseif ($this->definition()->usesDateRange())
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($this->datePresets() as $label => [$presetFrom, $presetTo])
                        <x-filament::button
                            tag="a"
                            size="sm"
                            color="gray"
                            :href="$this->presetUrl($presetFrom, $presetTo)"
                        >
                            {{ $label }}
                        </x-filament::button>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('This report shows the current figures, so it has no date range.') }}
                </p>
            @endif
        </x-filament::section>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            @foreach ([
                ['label' => __('Today Sales'), 'value' => $this->money($summary['sales_today'] ?? 0), 'icon' => 'heroicon-o-shopping-bag'],
                ['label' => __('Purchases'), 'value' => $this->money($summary['purchases_today'] ?? 0), 'icon' => 'heroicon-o-truck'],
                ['label' => __('Expenses'), 'value' => $this->money($summary['expenses_today'] ?? 0), 'icon' => 'heroicon-o-receipt-percent'],
                ['label' => __('Customer Due'), 'value' => $this->money($summary['customer_due'] ?? 0), 'icon' => 'heroicon-o-user-group'],
                ['label' => __('Account Balance'), 'value' => $this->money($summary['account_balance'] ?? 0), 'icon' => 'heroicon-o-banknotes'],
                ['label' => __('Low Stock'), 'value' => number_format($summary['low_stock_count'] ?? 0), 'icon' => 'heroicon-o-exclamation-triangle'],
            ] as $metric)
                <x-filament::section compact :icon="$metric['icon']" :heading="$metric['label']">
                    <p class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                        {{ $metric['value'] }}
                    </p>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section
            icon="heroicon-o-rectangle-stack"
            :heading="__('Available Reports')"
            :description="__('Switch report types without losing the selected date range.')"
        >
            <div class="overflow-x-auto">
                <x-filament::tabs contained :label="__('Report type')" class="min-w-max">
                    @foreach ($this->reportGroups() as $group => $types)
                        @foreach ($types as $type)
                            <x-filament::tabs.item
                                tag="a"
                                :href="$this->reportUrl($type)"
                                :icon="$this->reportIcon($type)"
                                :active="$reportType === $type"
                                :badge="__($group)"
                            >
                                {{ $this->reportOptions()[$type] }}
                            </x-filament::tabs.item>
                        @endforeach
                    @endforeach
                </x-filament::tabs>
            </div>
        </x-filament::section>

        @if ($this->notice())
            <x-filament::section icon="heroicon-o-information-circle" :heading="__('Choose a supplier')">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $this->notice() }}</p>
            </x-filament::section>
        @endif

        @if (count($this->summaryLines()) > 0)
            <x-filament::section icon="heroicon-o-scale" :heading="$this->summaryTitle()">
                <div class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($this->summaryLines() as $line)
                        <div class="py-2">
                            <div @class(['flex items-baseline justify-between gap-4', 'font-semibold' => $line['strong']])>
                                <span>{{ $line['label'] }}</span>
                                <span class="text-end">{{ $this->money($line['value']) }}</span>
                            </div>
                            @if ($line['note'])
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $line['note'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        @if (count($this->totals()) > 0 && $this->activeReportCount() > 0)
            <x-filament::section compact icon="heroicon-o-calculator" :heading="__('Totals')">
                <div class="flex flex-wrap gap-3">
                    @foreach ($this->totals() as $label => $total)
                        <x-filament::badge color="gray" size="lg">
                            {{ $label }}: {{ $total }}
                        </x-filament::badge>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
