<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @include('investments.reports.partials.report-styles')
    <style>
        @page { size: A4 landscape; }
        .sheet { max-width: none; }
        td.wrap { white-space: normal; }
        .period { color: #4b5563; }
    </style>
</head>
<body>
    <div class="print-actions">
        <button class="print-button" id="report-print" type="button">{{ __('Print') }}</button>
    </div>

    <div class="sheet">
        <div class="head">
            <div class="brand">
                {{ $company['name'] }}
                @if (! empty($company['address']))<div class="sub">{{ $company['address'] }}</div>@endif
            </div>
            @if (! empty($company['logo_url']))
                <img src="{{ $company['logo_url'] }}" alt="{{ $company['name'] }}">
            @endif
        </div>

        <h1 class="title">{{ $title }}</h1>
        <div class="deal period">
            @if ($asOf)
                {{ __('As of :date', ['date' => $asOf->format($company['date_format'] ?? 'd M Y')]) }}
            @elseif ($dateFrom && $dateTo)
                {{ $dateFrom->format($company['date_format'] ?? 'd M Y') }} {{ __('to') }} {{ $dateTo->format($company['date_format'] ?? 'd M Y') }}
            @else
                {{ __('As of :date', ['date' => now()->format($company['date_format'] ?? 'd M Y')]) }}
            @endif
        </div>

        @if (count($summary) > 0)
            <table>
                <thead>
                    <tr><th colspan="2">{{ $summaryTitle }}</th></tr>
                </thead>
                <tbody>
                    @foreach ($summary as $line)
                        <tr @class(['total' => $line['strong']])>
                            <td>
                                {{ $line['label'] }}
                                @if ($line['note'])<div class="period">{{ $line['note'] }}</div>@endif
                            </td>
                            <td class="num">{{ $line['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th @class(['num' => $column->isNumeric()])>{{ $column->label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td @class(['num' => $column->isNumeric(), 'wrap' => $column->wrap])>{{ $row[$column->key] }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $columns->count() }}">{{ __('No data found.') }}</td>
                    </tr>
                @endforelse

                @if (count($rows) > 0 && count($totals) > 0)
                    <tr class="total">
                        @foreach ($columns as $index => $column)
                            <td @class(['num' => $column->isNumeric()])>
                                @if ($index === 0)
                                    {{ __('Total') }}
                                @else
                                    {{ $totals[$column->label] ?? '' }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>

        <div class="foot">
            {{ $company['name'] }} &mdash; {{ __('Generated :time', ['time' => now()->format('d M Y, h:i A')]) }}
        </div>
    </div>

    @include('investments.reports.partials.print-script', ['autoPrint' => $autoPrint])
</body>
</html>
