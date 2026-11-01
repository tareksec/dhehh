<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        .header { border-bottom: 2px solid #f59e0b; margin-bottom: 18px; padding-bottom: 12px; }
        .brand { font-size: 22px; font-weight: 700; }
        .meta { color: #4b5563; margin-top: 4px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #111827; color: #fff; font-size: 9px; text-align: left; text-transform: uppercase; }
        th, td { border: 1px solid #d1d5db; padding: 7px 8px; }
        tr:nth-child(even) td { background: #f9fafb; }
        .empty { color: #6b7280; padding: 24px; text-align: center; }
        .footer { color: #6b7280; font-size: 9px; margin-top: 12px; text-align: right; }
    </style>
</head>
<body>
    @php($company = $company ?? ['name' => config('app.name', 'Business Dashboard')])
    <div class="header">
        @if (! empty($company['logo_path']))
            <img src="{{ $company['logo_path'] }}" alt="{{ $company['name'] }}" style="max-height: 44px; max-width: 150px; margin-bottom: 8px;">
        @endif
        <div class="meta">{{ $company['name'] }}</div>
        <div class="brand">{{ $title }}</div>
        <div class="meta">
            @if ($export['as_of'] ?? false)
                {{ __('As of :date', ['date' => $export['date_to']->format($company['date_format'] ?? 'd M Y')]) }}
            @elseif ($export['uses_dates'] ?? true)
                {{ $export['date_from']->format($company['date_format'] ?? 'd M Y') }} {{ __('to') }} {{ $export['date_to']->format($company['date_format'] ?? 'd M Y') }}
            @else
                {{ __('As of :date', ['date' => now()->format($company['date_format'] ?? 'd M Y')]) }}
            @endif
            | {{ __('Generated :time', ['time' => now()->format('d M Y, h:i A')]) }}
        </div>
    </div>

    @if (! empty($export['summary']))
        <table style="margin-bottom: 14px;">
            <thead>
                <tr><th colspan="2">{{ $export['summary_title'] }}</th></tr>
            </thead>
            <tbody>
                @foreach ($export['summary'] as $line)
                    <tr>
                        <td style="{{ $line['strong'] ? 'font-weight: 700;' : '' }}">
                            {{ $line['label'] }}
                            @if ($line['note'])<br><span class="meta">{{ $line['note'] }}</span>@endif
                        </td>
                        <td style="text-align: right; {{ $line['strong'] ? 'font-weight: 700;' : '' }}">{{ \App\Support\MoneyFormatter::number($line['value']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table>
        <thead>
            <tr>
                @foreach ($export['headings'] as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($export['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ count($export['headings']) }}">{{ __('No data found.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">{{ __(':company PDF Export', ['company' => $company['name']]) }}</div>
</body>
</html>
