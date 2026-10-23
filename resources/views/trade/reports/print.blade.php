{{--
    A Trade Portal report to print, in the portal's language
    (TradeReportDownloadController, format "print"). The browser draws Bangla
    correctly; "Save as PDF" in the print window makes a Bangla PDF. The print
    window opens by itself (also in the Android app, via the shared print script).
--}}
@php
    use App\Support\TradeReportFormat;

    $columns = $report['columns'];
    $wide = count($columns) > 6;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report['title'] }} — {{ $report['period'] }}</title>
    <style>
        @page { size: A4 {{ $wide ? 'landscape' : 'portrait' }}; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; color: #111827; font: 13px/1.45 'Hind Siliguri', 'Noto Sans Bengali', 'Nikosh', 'SolaimanLipi', Arial, sans-serif; background: #fff; }
        .header { border-bottom: 2px solid #1d4ed8; margin-bottom: 10px; padding-bottom: 6px; }
        .brand { font-size: 18px; font-weight: 700; }
        .muted { color: #5b6472; }
        .wrap { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d6dbe3; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #e3ebfd; }
        .num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: 700; background: #f3f5f8; }
        .note { margin-top: 8px; color: #5b6472; font-size: 12px; }
        .tools { text-align: center; margin-bottom: 12px; }
        .tools button { font: inherit; padding: 8px 18px; border-radius: 8px; border: 1px solid #1d4ed8; background: #1d4ed8; color: #fff; }
        @media print { .tools { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="tools"><button type="button" id="report-print">{{ __('Print') }}</button></div>

    <div class="header">
        <div class="brand">{{ $company?->name }} — {{ $report['title'] }}</div>
        <div class="muted">{{ $report['period'] }}</div>
    </div>

    @if ($report['rows'] === [])
        <p>{{ __('Nothing to show for this period.') }}</p>
    @else
        <div class="wrap">
            <table>
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th @class(['num' => TradeReportFormat::isNumeric($column['type'])])>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['rows'] as $row)
                        <tr>
                            @foreach ($columns as $column)
                                <td @class(['num' => TradeReportFormat::isNumeric($column['type'])])>{{ TradeReportFormat::text($row[$column['key']] ?? null, $column['type']) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if ($report['totals'])
                    <tfoot>
                        <tr>
                            @foreach ($columns as $column)
                                <td @class(['num' => TradeReportFormat::isNumeric($column['type'])])>{{ TradeReportFormat::text($report['totals'][$column['key']] ?? null, $column['type']) }}</td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif

    @foreach ($report['notes'] as $note)
        <div class="note">{{ $note }}</div>
    @endforeach
    <div class="note">{{ __('Made on :date.', ['date' => now()->format('d/m/Y H:i')]) }}</div>

    @include('investments.reports.partials.print-script', ['autoPrint' => true])
</body>
</html>
