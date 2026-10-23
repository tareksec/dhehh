{{--
    A Trade Portal report as a PDF file (TradeReportDownloadController). Written
    in English: DomPDF cannot shape Bangla, so the Bangla copy is the print page
    (trade.reports.print). Hind Siliguri stays as a fallback so Bangla names
    typed by the owner still show their letters.
--}}
@php
    use App\Support\TradeReportFormat;

    $columns = $report['columns'];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @font-face { font-family: 'Hind Siliguri'; font-weight: normal; src: url("{{ resource_path('fonts/ad-creative/HindSiliguri-Regular.ttf') }}") format('truetype'); }
        @font-face { font-family: 'Hind Siliguri'; font-weight: bold; src: url("{{ resource_path('fonts/ad-creative/HindSiliguri-Bold.ttf') }}") format('truetype'); }
        @page { margin: 22px 24px; }
        body { color: #111827; font-family: 'Hind Siliguri', DejaVu Sans, sans-serif; font-size: 10px; }
        .header { border-bottom: 2px solid #1d4ed8; margin-bottom: 10px; padding-bottom: 6px; }
        .brand { font-size: 15px; font-weight: bold; }
        .muted { color: #5b6472; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d6dbe3; padding: 4px 5px; text-align: left; vertical-align: top; }
        th { background: #e3ebfd; }
        .num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; background: #f3f5f8; }
        .note { margin-top: 8px; color: #5b6472; font-size: 9px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">{{ $company?->name }} — {{ $report['title'] }}</div>
        <div class="muted">{{ $report['period'] }}</div>
    </div>

    @if ($report['rows'] === [])
        <p>{{ __('Nothing to show for this period.') }}</p>
    @else
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
    @endif

    @foreach ($report['notes'] as $note)
        <div class="note">{{ $note }}</div>
    @endforeach
    <div class="note">{{ __('Made on :date.', ['date' => now()->format('d/m/Y H:i')]) }}</div>
</body>
</html>
