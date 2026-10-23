{{--
    One report (Report): the period, PDF / Excel buttons, then the rows — a
    table on wide screens, one card per row on a phone (first column as the
    card's title, the rest as label / value lines).
--}}
@php
    use App\Support\TradeReportFormat;

    $columns = $report['columns'];
    $first = $columns[0] ?? null;
@endphp

<x-filament-panels::page>
    <div class="trade-toolbar">
        <div class="trade-stepper">
            @if ($kind === 'day')
                <input type="date" class="trade-date-input" wire:model.live="from" max="{{ now()->toDateString() }}" aria-label="{{ __('Date') }}">
            @elseif ($kind === 'month')
                <input type="month" class="trade-date-input" wire:model.live="from" max="{{ now()->format('Y-m') }}" aria-label="{{ __('Month') }}">
            @elseif ($kind === 'range')
                <input type="date" class="trade-date-input" wire:model.live="from" aria-label="{{ __('From') }}">
                <span>–</span>
                <input type="date" class="trade-date-input" wire:model.live="to" aria-label="{{ __('To') }}">
            @else
                <span class="trade-card-note">{{ $report['period'] }}</span>
            @endif
        </div>
        <div class="trade-actions">
            <x-filament::button tag="a" :href="$printUrl" target="_blank" icon="heroicon-o-printer">{{ __('Print') }}</x-filament::button>
            <x-filament::button tag="a" :href="$pdfUrl" target="_blank" icon="heroicon-o-document-arrow-down" color="gray" :tooltip="__('PDF file in English')">PDF</x-filament::button>
            <x-filament::button tag="a" :href="$excelUrl" icon="heroicon-o-table-cells" color="gray">Excel</x-filament::button>
        </div>
    </div>

    @if ($report['rows'] === [])
        <section class="trade-card"><p class="trade-card-empty">{{ __('Nothing to show for this period.') }}</p></section>
    @else
        {{-- Wide screens: a table. --}}
        <div class="trade-card trade-report-table-wrap">
            <table class="trade-report-table">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th @class(['trade-num' => TradeReportFormat::isNumeric($column['type'])])>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['rows'] as $row)
                        <tr>
                            @foreach ($columns as $column)
                                <td @class(['trade-num' => TradeReportFormat::isNumeric($column['type'])])>{{ TradeReportFormat::text($row[$column['key']] ?? null, $column['type']) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if ($report['totals'])
                    <tfoot>
                        <tr>
                            @foreach ($columns as $column)
                                <td @class(['trade-num' => TradeReportFormat::isNumeric($column['type'])])>{{ TradeReportFormat::text($report['totals'][$column['key']] ?? null, $column['type']) }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        {{-- Phones: one card per row. --}}
        <div class="trade-report-cards">
            @foreach ($report['rows'] as $row)
                <section class="trade-card">
                    <h3 class="trade-card-title">{{ TradeReportFormat::text($row[$first['key']] ?? null, $first['type']) }}</h3>
                    <dl class="trade-report-lines">
                        @foreach (array_slice($columns, 1) as $column)
                            @php($value = TradeReportFormat::text($row[$column['key']] ?? null, $column['type']))
                            @if ($value !== '')
                                <div><dt>{{ $column['label'] }}</dt><dd>{{ $value }}</dd></div>
                            @endif
                        @endforeach
                    </dl>
                </section>
            @endforeach
            @if ($report['totals'])
                <section class="trade-card trade-report-total">
                    <h3 class="trade-card-title">{{ __('Total') }}</h3>
                    <dl class="trade-report-lines">
                        @foreach (array_slice($columns, 1) as $column)
                            @if (isset($report['totals'][$column['key']]))
                                <div><dt>{{ $column['label'] }}</dt><dd>{{ TradeReportFormat::text($report['totals'][$column['key']], $column['type']) }}</dd></div>
                            @endif
                        @endforeach
                    </dl>
                </section>
            @endif
        </div>
    @endif

    @foreach ($report['notes'] as $note)
        <p class="trade-card-note">{{ $note }}</p>
    @endforeach
</x-filament-panels::page>
