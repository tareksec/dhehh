{{--
    Compact fraud-check card for the create-order forms (admin OrderForm and
    the reseller portal's CreateOrder). Same numbers and verdict as the full
    Fraud Checker page (filament.fraud-check.page), via FraudCheckReport.

    @var \App\Support\FraudCheckReport $report
    @var string|null $fullReportUrl
    @var bool $storeData  admin only: blacklist hit and this store's order history
--}}
@php
    use App\Support\MoneyFormatter;

    $summary = $report->summary();
    $verdict = $report->verdict();
    $threshold = $report->threshold();
    $tone = [
        'safe' => ['panel' => 'border-success-300 bg-success-50 dark:border-success-500/40 dark:bg-success-500/10', 'text' => 'text-success-700 dark:text-success-400', 'glyph' => 'heroicon-o-shield-check'],
        'risky' => ['panel' => 'border-danger-300 bg-danger-50 dark:border-danger-500/40 dark:bg-danger-500/10', 'text' => 'text-danger-700 dark:text-danger-400', 'glyph' => 'heroicon-o-shield-exclamation'],
        'none' => ['panel' => 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5', 'text' => 'text-gray-700 dark:text-gray-300', 'glyph' => 'heroicon-o-question-mark-circle'],
    ][$verdict['key']];
    $blacklist = $storeData ? $report->blacklistEntry() : null;
    $history = $storeData ? $report->storeHistory() : null;
@endphp

<div class="w-full min-w-0 space-y-2 rounded-xl border p-3 text-sm {{ $tone['panel'] }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="flex items-center gap-2 font-semibold {{ $tone['text'] }}">
            <x-filament::icon :icon="$tone['glyph']" class="h-5 w-5 shrink-0" />
            <span>
                {{ $verdict['label'] }}
                @if ($summary['ratio'] !== null)
                    — {{ __(':ratio% delivered', ['ratio' => MoneyFormatter::number($summary['ratio'], 1)]) }}
                @endif
            </span>
        </p>

        @if ($fullReportUrl)
            <a href="{{ $fullReportUrl }}" target="_blank" rel="noopener" class="text-xs font-medium text-primary-600 underline dark:text-primary-400">
                {{ __('Full report') }}
            </a>
        @endif
    </div>

    @if ($summary['total'] > 0)
        <p class="tabular-nums text-gray-700 dark:text-gray-300">
            {{ __('Total Parcels') }}: <span class="font-semibold">{{ MoneyFormatter::number($summary['total']) }}</span>
            · <span class="text-success-700 dark:text-success-400">{{ __('Delivered') }}: {{ MoneyFormatter::number($summary['success']) }}</span>
            · <span class="text-danger-700 dark:text-danger-400">{{ __('Cancelled') }}: {{ MoneyFormatter::number($summary['cancel']) }}</span>
        </p>
    @endif

    @php($cards = $report->courierCards(onlyConfigured: true))
    @if ($cards !== [])
        <div class="flex flex-wrap gap-1.5">
            @foreach ($cards as $card)
                <span class="inline-flex items-center gap-1 rounded-md bg-white px-2 py-0.5 text-xs tabular-nums ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                    <span class="font-semibold">{{ $card['label'] }}</span>
                    @if ($card['stats'] === null)
                        <span class="text-gray-500 dark:text-gray-400">{{ __('No response') }}</span>
                    @elseif ($card['ratio'] === null)
                        <span class="text-gray-500 dark:text-gray-400">{{ __('No history') }}</span>
                    @else
                        <span class="{{ $card['ratio'] < $threshold ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                            {{ MoneyFormatter::number($card['stats']['success']) }}/{{ MoneyFormatter::number($card['stats']['total']) }}
                            ({{ MoneyFormatter::number($card['ratio'], 1) }}%)
                        </span>
                    @endif
                </span>
            @endforeach
        </div>
    @endif

    @if ($summary['reports'] > 0)
        <p class="flex items-center gap-1.5 text-xs text-warning-700 dark:text-warning-400">
            <x-filament::icon icon="heroicon-o-flag" class="h-4 w-4 shrink-0" />
            {{ __('Other merchants have filed :count fraud reports against this number.', ['count' => MoneyFormatter::number($summary['reports'])]) }}
        </p>
    @endif

    @if ($blacklist)
        <p class="flex items-start gap-1.5 text-xs font-medium text-danger-700 dark:text-danger-400">
            <x-filament::icon icon="heroicon-o-no-symbol" class="h-4 w-4 shrink-0" />
            <span>
                {{ __('This number is on your blacklist') }}@if (filled($blacklist->reason)): {{ $blacklist->reason }}@endif
            </span>
        </p>
    @endif

    @if ($history)
        <p class="text-xs tabular-nums text-gray-600 dark:text-gray-400">
            {{ __('Your store history') }}:
            {{ __('Orders') }} {{ MoneyFormatter::number($history['total']) }}
            · {{ __('Completed') }} {{ MoneyFormatter::number($history['completed']) }}
            · {{ __('Cancelled') }} {{ MoneyFormatter::number($history['cancelled']) }}
            · {{ __('Returned') }} {{ MoneyFormatter::number($history['returned']) }}
        </p>
    @endif
</div>
