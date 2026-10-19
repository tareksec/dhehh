<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Supplier Report') }}</title>
    <style>
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        .header { border-bottom: 2px solid #f59e0b; margin-bottom: 12px; padding-bottom: 8px; }
        .brand { font-size: 18px; font-weight: 700; }
        .meta { color: #4b5563; margin-top: 3px; }
        h2 { font-size: 11px; margin: 14px 0 5px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #111827; color: #fff; font-size: 8px; text-align: left; }
        th, td { border: 1px solid #d1d5db; padding: 3px 4px; }
        tr:nth-child(even) td { background: #f9fafb; }
        .r { text-align: right; }
        .total td { background: #fef3c7 !important; font-weight: 700; }
        .info td { border: 0; padding: 2px 6px 2px 0; }
        .info .k { color: #6b7280; width: 90px; }
        .profit { width: 55%; }
        .profit td { border-left: 0; border-right: 0; }
        .note { color: #6b7280; font-size: 8px; margin-top: 8px; }
        .footer { color: #6b7280; font-size: 8px; margin-top: 10px; text-align: right; }
    </style>
</head>
<body>
    @php
        $currency = $company['currency'] ?? 'BDT';
        $m = fn ($amount) => $currency.' '.\App\Support\MoneyFormatter::number($amount);
        $n = fn ($amount) => \App\Support\MoneyFormatter::number($amount);
        $dateFormat = $company['date_format'] ?? 'd M Y';
        $s = $report['summary'];
        $supplier = $report['supplier'];
        $period = $report['period'];
    @endphp

    <div class="header">
        @if (! empty($company['logo_path']))
            <img src="{{ $company['logo_path'] }}" alt="{{ $company['name'] }}" style="max-height: 38px; max-width: 140px; margin-bottom: 6px;">
        @endif
        <div class="meta">{{ $company['name'] }}</div>
        <div class="brand">{{ __('Supplier Report') }}: {{ $supplier['name'] }}</div>
        <div class="meta">
            {{ $report['product'] ? __('Product').': '.$report['product']['name'] : __('All products') }}
            | {{ __('Purchased') }}:
            {{ $period['from'] ? $period['from']->format($dateFormat) : __('beginning') }}
            – {{ $period['to'] ? $period['to']->format($dateFormat) : __('today') }}
            | {{ __('Generated') }} {{ $report['generated_at']->format($dateFormat.', h:i A') }}
        </div>
    </div>

    <h2>{{ __('Supplier info') }}</h2>
    <table class="info">
        <tr>
            <td class="k">{{ __('Supplier') }}</td><td>{{ $supplier['name'] }}</td>
            <td class="k">{{ __('Company') }}</td><td>{{ $supplier['company_name'] ?: '-' }}</td>
            <td class="k">{{ __('Phone') }}</td><td>{{ $supplier['phone'] ?: '-' }}</td>
        </tr>
        <tr>
            <td class="k">{{ __('Email') }}</td><td>{{ $supplier['email'] ?: '-' }}</td>
            <td class="k">{{ __('Address') }}</td><td>{{ $supplier['address'] ?: '-' }}</td>
            <td class="k">{{ __('Payable to supplier') }}</td><td>{{ $m($supplier['payable']) }}</td>
        </tr>
    </table>

    @if ($s['purchase_count'] === 0)
        <p class="note">{{ __('No received purchases from this supplier match these filters.') }}</p>
    @else
        <h2>{{ __('Summary') }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('Purchases') }}</th>
                    <th class="r">{{ __('Billed qty') }}</th>
                    <th class="r">{{ __('Short') }}</th>
                    <th class="r">{{ __('Damaged') }}</th>
                    <th class="r">{{ __('Sold') }}</th>
                    <th class="r">{{ __('In stock') }}</th>
                    <th class="r">{{ __('Purchase value') }}</th>
                    <th class="r">{{ __('Import costs') }}</th>
                    <th class="r">{{ __('Total landed cost') }}</th>
                    <th class="r">{{ __('Sales') }}</th>
                    <th class="r">{{ __('Average sale price') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $s['purchase_count'] }}</td>
                    <td class="r">{{ $n($s['ordered']) }}</td>
                    <td class="r">{{ $n($s['short']) }}</td>
                    <td class="r">{{ $n($s['damaged']) }}</td>
                    <td class="r">{{ $n($s['sold']) }}</td>
                    <td class="r">{{ $n($s['in_stock']) }}</td>
                    <td class="r">{{ $m($s['purchase_value']) }}</td>
                    <td class="r">{{ $m($s['import_cost']) }}</td>
                    <td class="r">{{ $m($s['landed_cost']) }}</td>
                    <td class="r">{{ $m($s['revenue']) }}</td>
                    <td class="r">{{ $s['avg_price'] === null ? '-' : $m($s['avg_price']) }}</td>
                </tr>
            </tbody>
        </table>

        <h2>{{ __('How the profit is worked out') }}</h2>
        <table class="profit">
            <tbody>
                <tr><td>{{ __('Sales') }}</td><td class="r">{{ $m($s['revenue']) }}</td></tr>
                <tr><td>- {{ __('Landed cost of units sold') }}</td><td class="r">{{ $m($s['cogs']) }}</td></tr>
                <tr><td>- {{ __('Loss on short and damaged units') }}</td><td class="r">{{ $m($s['loss']) }}</td></tr>
                <tr class="total"><td>{{ __('Gross profit') }}</td><td class="r">{{ $m($s['gross_profit']) }}</td></tr>
                @foreach ($s['cost_lines'] as $label => $amount)
                    <tr><td>- {{ $label }}</td><td class="r">{{ $m($amount) }}</td></tr>
                @endforeach
                <tr><td>- {{ __('Ad cost (Meta ad spend)') }}</td><td class="r">{{ $m($s['ad_cost']) }}</td></tr>
                <tr class="total"><td>{{ __('Net profit') }}</td><td class="r">{{ $m($s['net_profit']) }}</td></tr>
            </tbody>
        </table>

        <h2>{{ __('Purchase dates') }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Purchase') }}</th>
                    <th class="r">{{ __('Billed qty') }}</th>
                    <th class="r">{{ __('Short') }}</th>
                    <th class="r">{{ __('Damaged') }}</th>
                    <th class="r">{{ __('Sold') }}</th>
                    <th class="r">{{ __('Landed cost') }}</th>
                    <th class="r">{{ __('Sales') }}</th>
                    <th class="r">{{ __('Loss') }}</th>
                    <th class="r">{{ __('Gross profit') }}</th>
                    <th class="r">{{ __('Costs after arrival') }}</th>
                    <th class="r">{{ __('Net profit') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['purchases'] as $row)
                    <tr>
                        <td>{{ $row['purchase_date']?->format($dateFormat) }}</td>
                        <td>{{ $row['purchase_number'] }}</td>
                        <td class="r">{{ $n($row['ordered']) }}</td>
                        <td class="r">{{ $n($row['short']) }}</td>
                        <td class="r">{{ $n($row['damaged']) }}</td>
                        <td class="r">{{ $n($row['sold']) }}</td>
                        <td class="r">{{ $m($row['landed_cost']) }}</td>
                        <td class="r">{{ $m($row['revenue']) }}</td>
                        <td class="r">{{ $m($row['loss']) }}</td>
                        <td class="r">{{ $m($row['gross_profit']) }}</td>
                        <td class="r">{{ $m($row['extra_cost'] + $row['ad_cost']) }}</td>
                        <td class="r">{{ $m($row['net_profit']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>{{ __('Products') }}</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('Product') }}</th>
                    <th class="r">{{ __('Billed qty') }}</th>
                    <th class="r">{{ __('Short') }}</th>
                    <th class="r">{{ __('Damaged') }}</th>
                    <th class="r">{{ __('Sold') }}</th>
                    <th class="r">{{ __('In stock') }}</th>
                    <th class="r">{{ __('Landed cost') }}</th>
                    <th class="r">{{ __('Sales') }}</th>
                    <th class="r">{{ __('Average sale price') }}</th>
                    @foreach ($report['cost_labels'] as $label)
                        <th class="r">{{ $label }}</th>
                    @endforeach
                    <th class="r">{{ __('Ad cost (Meta ad spend)') }}</th>
                    <th class="r">{{ __('Gross profit') }}</th>
                    <th class="r">{{ __('Net profit') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['products'] as $row)
                    <tr>
                        <td>{{ $row['product'] }}</td>
                        <td class="r">{{ $n($row['ordered']) }}</td>
                        <td class="r">{{ $n($row['short']) }}</td>
                        <td class="r">{{ $n($row['damaged']) }}</td>
                        <td class="r">{{ $n($row['sold']) }}</td>
                        <td class="r">{{ $n($row['in_stock']) }}</td>
                        <td class="r">{{ $m($row['landed_cost']) }}</td>
                        <td class="r">{{ $m($row['revenue']) }}</td>
                        <td class="r">{{ $row['avg_price'] === null ? '-' : $m($row['avg_price']) }}</td>
                        @foreach ($report['cost_labels'] as $label)
                            <td class="r">{{ $m($row['cost_lines'][$label] ?? 0) }}</td>
                        @endforeach
                        <td class="r">{{ $m($row['ad_cost']) }}</td>
                        <td class="r">{{ $m($row['gross_profit']) }}</td>
                        <td class="r">{{ $m($row['net_profit']) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td>{{ __('Total') }}</td>
                    <td class="r">{{ $n($s['ordered']) }}</td>
                    <td class="r">{{ $n($s['short']) }}</td>
                    <td class="r">{{ $n($s['damaged']) }}</td>
                    <td class="r">{{ $n($s['sold']) }}</td>
                    <td class="r">{{ $n($s['in_stock']) }}</td>
                    <td class="r">{{ $m($s['landed_cost']) }}</td>
                    <td class="r">{{ $m($s['revenue']) }}</td>
                    <td class="r">{{ $s['avg_price'] === null ? '-' : $m($s['avg_price']) }}</td>
                    @foreach ($report['cost_labels'] as $label)
                        <td class="r">{{ $m($s['cost_lines'][$label] ?? 0) }}</td>
                    @endforeach
                    <td class="r">{{ $m($s['ad_cost']) }}</td>
                    <td class="r">{{ $m($s['gross_profit']) }}</td>
                    <td class="r">{{ $m($s['net_profit']) }}</td>
                </tr>
            </tbody>
        </table>

        <p class="note">
            {{ __('Sales are matched to purchases oldest first. Costs after arrival are counted in proportion to the units sold. Purchase discount and VAT are not part of the landed cost.') }}
        </p>
    @endif

    <div class="footer">{{ $company['name'] }} - {{ __('Supplier Report') }}</div>
</body>
</html>
