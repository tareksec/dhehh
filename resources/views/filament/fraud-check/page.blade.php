@php
    use App\Support\MoneyFormatter;

    $tones = [
        'safe' => [
            'panel' => 'border-success-300 bg-success-50 dark:border-success-500/40 dark:bg-success-500/10',
            'icon' => 'bg-success-100 text-success-600 dark:bg-success-500/20 dark:text-success-400',
            'text' => 'text-success-700 dark:text-success-400',
            'bar' => 'bg-success-500',
            'glyph' => 'heroicon-o-shield-check',
        ],
        'risky' => [
            'panel' => 'border-danger-300 bg-danger-50 dark:border-danger-500/40 dark:bg-danger-500/10',
            'icon' => 'bg-danger-100 text-danger-600 dark:bg-danger-500/20 dark:text-danger-400',
            'text' => 'text-danger-700 dark:text-danger-400',
            'bar' => 'bg-danger-500',
            'glyph' => 'heroicon-o-shield-exclamation',
        ],
        'none' => [
            'panel' => 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5',
            'icon' => 'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400',
            'text' => 'text-gray-700 dark:text-gray-300',
            'bar' => 'bg-gray-400',
            'glyph' => 'heroicon-o-question-mark-circle',
        ],
    ];
@endphp

@php
    $report = $this->report();
    $storeData = $this->showsStoreData();
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Search --}}
        <x-filament::section>
            <form wire:submit="check" class="mx-auto flex max-w-2xl flex-col gap-3 sm:flex-row sm:items-start">
                <div class="min-w-0 flex-1">
                    {{ $this->form }}
                </div>

                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" size="lg" class="shrink-0">
                    {{ __('Check Now') }}
                </x-filament::button>
            </form>

            <p class="mx-auto mt-3 max-w-2xl text-center text-xs text-gray-500 dark:text-gray-400">
                {{ $storeData
                    ? __('Checks the delivery history of this number on your connected couriers (Pathao, Steadfast, RedX).')
                    : __('Checks the delivery history of this number on the store\'s couriers.') }}
            </p>
        </x-filament::section>

        <div wire:loading.flex wire:target="check,refreshCheck" class="items-center justify-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            <x-filament::loading-indicator class="h-5 w-5" />
            {{ __('Checking couriers…') }}
        </div>

        @if ($report)
            @php
                $summary = $report->summary();
                $verdict = $report->verdict();
                $threshold = $report->threshold();
                $tone = $tones[$verdict['key']];
                $history = $storeData ? $report->storeHistory() : null;
                $blacklist = $storeData ? $report->blacklistEntry() : null;
            @endphp

            <div wire:loading.remove wire:target="check,refreshCheck" class="space-y-6">
                {{-- Header --}}
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-950 dark:text-white">
                            <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5 text-primary-500" />
                            {{ __('Customer verification result') }}
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Detailed report for :phone', ['phone' => $checkedPhone]) }}
                            @if ($checkedAt)
                                · {{ __('Checked at :time', ['time' => \Illuminate\Support\Carbon::parse($checkedAt)->format('d M Y, h:i A')]) }}
                            @endif
                        </p>
                    </div>

                    @if ($this->canRefresh())
                        <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-path" wire:click="refreshCheck">
                            {{ __('Refresh live data') }}
                        </x-filament::button>
                    @endif
                </div>

                @if ($blacklist)
                    <div class="flex items-start gap-3 rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-400">
                        <x-filament::icon icon="heroicon-o-no-symbol" class="mt-0.5 h-5 w-5 shrink-0" />
                        <div>
                            <p class="font-semibold">{{ __('This number is on your blacklist') }}</p>
                            @if (filled($blacklist->reason))
                                <p class="mt-1">{{ $blacklist->reason }}</p>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Verdict banner --}}
                <div class="flex flex-col gap-4 rounded-xl border p-5 sm:flex-row sm:items-center sm:justify-between {{ $tone['panel'] }}">
                    <div class="flex items-start gap-4">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full {{ $tone['icon'] }}">
                            <x-filament::icon :icon="$tone['glyph']" class="h-7 w-7" />
                        </div>
                        <div>
                            <p class="text-xl font-bold {{ $tone['text'] }}">{{ $verdict['label'] }}</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $verdict['description'] }}</p>
                        </div>
                    </div>

                    <div class="shrink-0 rounded-xl bg-white px-6 py-3 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Delivery success rate') }}</p>
                        <p class="text-3xl font-bold tabular-nums {{ $tone['text'] }}">
                            {{ $summary['ratio'] === null ? '—' : MoneyFormatter::number($summary['ratio'], 1).'%' }}
                        </p>
                    </div>
                </div>

                {{-- Summary tiles --}}
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach ([
                        ['label' => __('Total Parcels'), 'value' => MoneyFormatter::number($summary['total']), 'icon' => 'heroicon-o-cube', 'class' => 'text-gray-950 dark:text-white'],
                        ['label' => __('Delivered'), 'value' => MoneyFormatter::number($summary['success']), 'icon' => 'heroicon-o-check-circle', 'class' => 'text-success-600 dark:text-success-400'],
                        ['label' => __('Cancelled'), 'value' => MoneyFormatter::number($summary['cancel']), 'icon' => 'heroicon-o-x-circle', 'class' => 'text-danger-600 dark:text-danger-400'],
                        ['label' => __('Success rate'), 'value' => $summary['ratio'] === null ? '—' : MoneyFormatter::number($summary['ratio'], 1).'%', 'icon' => 'heroicon-o-chart-bar', 'class' => $tone['text']],
                    ] as $tile)
                        <div class="flex flex-col gap-1 rounded-xl bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:gap-2 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <span class="flex min-w-0 items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                                <x-filament::icon :icon="$tile['icon']" class="h-5 w-5 shrink-0" />
                                <span class="truncate">{{ $tile['label'] }}</span>
                            </span>
                            <span class="text-xl font-bold tabular-nums {{ $tile['class'] }}">{{ $tile['value'] }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div class="h-full rounded-full {{ $tone['bar'] }}" style="width: {{ min(100, max(0, (float) ($summary['ratio'] ?? 0))) }}%"></div>
                </div>

                @if ($summary['reports'] > 0)
                    <div class="flex items-start gap-3 rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-700 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-400">
                        <x-filament::icon icon="heroicon-o-flag" class="mt-0.5 h-5 w-5 shrink-0" />
                        <p>{{ __('Other merchants have filed :count fraud reports against this number.', ['count' => MoneyFormatter::number($summary['reports'])]) }}</p>
                    </div>
                @endif

                {{-- Per-courier cards --}}
                <div>
                    <h3 class="mb-3 flex items-center gap-2 text-base font-semibold text-gray-950 dark:text-white">
                        <x-filament::icon icon="heroicon-o-truck" class="h-5 w-5 text-primary-500" />
                        {{ __('Courier-wise performance') }}
                    </h3>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($report->courierCards(onlyConfigured: ! $storeData) as $driver => $card)
                            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <div class="mb-3 flex items-center justify-between gap-2">
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $card['label'] }}</p>

                                    @if (! $card['configured'])
                                        <x-filament::badge color="gray">{{ __('Not configured') }}</x-filament::badge>
                                    @elseif ($card['stats'] === null)
                                        <x-filament::badge color="warning">{{ __('No response') }}</x-filament::badge>
                                    @elseif ($card['ratio'] === null)
                                        <x-filament::badge color="gray">{{ __('No history') }}</x-filament::badge>
                                    @else
                                        <x-filament::badge :color="$card['ratio'] < $threshold ? 'danger' : 'success'">
                                            {{ $card['ratio'] < $threshold ? __('Risky') : __('Trusted') }}
                                        </x-filament::badge>
                                    @endif
                                </div>

                                @if ($card['stats'] !== null)
                                    <div class="grid grid-cols-3 gap-2 rounded-lg bg-gray-50 p-3 text-center dark:bg-white/5">
                                        <div>
                                            <p class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Total') }}</p>
                                            <p class="text-lg font-bold tabular-nums text-gray-950 dark:text-white">{{ MoneyFormatter::number($card['stats']['total']) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[11px] uppercase tracking-wide text-success-600 dark:text-success-400">{{ __('Delivered') }}</p>
                                            <p class="text-lg font-bold tabular-nums text-success-600 dark:text-success-400">{{ MoneyFormatter::number($card['stats']['success']) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[11px] uppercase tracking-wide text-danger-600 dark:text-danger-400">{{ __('Cancelled') }}</p>
                                            <p class="text-lg font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ MoneyFormatter::number($card['stats']['cancel']) }}</p>
                                        </div>
                                    </div>

                                    <div class="mt-3 flex items-center justify-between text-xs">
                                        <span class="text-gray-500 dark:text-gray-400">{{ __('Success rate') }}</span>
                                        <span class="font-semibold tabular-nums">{{ $card['ratio'] === null ? '—' : MoneyFormatter::number($card['ratio'], 1).'%' }}</span>
                                    </div>
                                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                        <div
                                            class="h-full rounded-full {{ $card['ratio'] === null ? 'bg-gray-400' : ($card['ratio'] < $threshold ? 'bg-danger-500' : 'bg-success-500') }}"
                                            style="width: {{ min(100, max(0, (float) ($card['ratio'] ?? 0))) }}%"
                                        ></div>
                                    </div>

                                    @if (($card['stats']['reports'] ?? 0) > 0)
                                        <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">
                                            {{ __(':count fraud reports from other merchants', ['count' => MoneyFormatter::number($card['stats']['reports'])]) }}
                                        </p>
                                    @endif
                                @elseif (! $card['configured'])
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('Add this courier\'s login on Courier → Providers to include it in the check.') }}
                                    </p>
                                    <a href="{{ \App\Filament\Resources\CourierProviders\CourierProviderResource::getUrl() }}" class="mt-2 inline-block text-sm font-medium text-primary-600 underline dark:text-primary-400">{{ __('Open Providers') }}</a>
                                @else
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ $storeData
                                            ? __('The courier did not answer. Check its login on Courier → Providers, then refresh.')
                                            : __('The courier did not answer. Try again later.') }}
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- This store's own history --}}
                @if ($storeData)
                <x-filament::section icon="heroicon-o-building-storefront" :heading="__('Your store history')">
                    @if ($history)
                        <p class="mb-3 text-sm">
                            <a href="{{ \App\Filament\Resources\Customers\CustomerResource::getUrl('view', ['record' => $history['customer']]) }}" class="font-medium text-primary-600 underline dark:text-primary-400">
                                {{ $history['customer']->name }}
                            </a>
                        </p>

                        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            @foreach ([
                                ['label' => __('Orders'), 'value' => $history['total'], 'class' => ''],
                                ['label' => __('Completed'), 'value' => $history['completed'], 'class' => 'text-success-600 dark:text-success-400'],
                                ['label' => __('Cancelled'), 'value' => $history['cancelled'], 'class' => 'text-danger-600 dark:text-danger-400'],
                                ['label' => __('Returned'), 'value' => $history['returned'], 'class' => 'text-warning-600 dark:text-warning-400'],
                            ] as $stat)
                                <div>
                                    <p class="text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</p>
                                    <p class="text-lg font-semibold tabular-nums {{ $stat['class'] }}">{{ MoneyFormatter::number($stat['value']) }}</p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This number has not ordered from your store yet.') }}</p>
                    @endif
                </x-filament::section>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
