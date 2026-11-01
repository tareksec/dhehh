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
        .strong td { font-weight: 700; }
        .sub td { color: #6b7280; font-size: 8px; padding-left: 14px; }
        .info td { border: 0; padding: 2px 6px 2px 0; }
        .info .k { color: #6b7280; width: 90px; }
        .profit { width: 55%; }
        .profit td { border-left: 0; border-right: 0; }
        .layout, .layout > tbody > tr > td { border: 0; padding: 0; vertical-align: top; background: none !important; }
        .gap { width: 16px; }
        .page { page-break-before: always; }
        .product-title { background: #111827; color: #fff; font-size: 13px; font-weight: 700; margin: 0 0 10px; padding: 7px 9px; }
        .product-title small { font-size: 8px; font-weight: 400; }
        .check { margin-top: 5px; font-size: 8px; }
        .ok { color: #047857; }
        .bad { color: #b91c1c; font-weight: 700; }
        .note { color: #6b7280; font-size: 8px; margin-top: 8px; }
        .footer { color: #6b7280; font-size: 8px; margin-top: 10px; text-align: right; }
    </style>
</head>
<body>
    @php
        $currency = $company['currency'] ?? 'BDT';
        $n = fn ($amount) => \App\Support\MoneyFormatter::number($amount);
        $m = $n;
        $dateFormat = $company['date_format'] ?? 'd M Y';
        $s = $report['summary'];
        $supplier = $report['supplier'];
        $period = $report['period'];
        $periodText = ($period['from'] ? $period['from']->format($dateFormat) : __('beginning'))
            .' - '.($period['to'] ? $period['to']->format($dateFormat) : __('today'));
    @endphp

    <div class="header">
        @if (! empty($company['logo_path']))
            <img src="{{ $company['logo_path'] }}" alt="{{ $company['name'] }}" style="max-height: 38px; max-width: 140px; margin-bottom: 6px;">
        @endif
        <div class="meta">{{ $company['name'] }}</div>
        <div class="brand">{{ __('Supplier Report') }}: {{ $supplier['name'] }}</div>
        <div class="meta">
            {{ $report['product'] ? __('Product').': '.$report['product']['name'] : __('All products') }}
            | {{ __('Purchased') }}: {{ $periodText }}
            | {{ __('Amounts in :currency', ['currency' => $currency]) }}
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
                    <th class="r">{{ __('Damaged after arrival') }}</th>
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
                    <td class="r">{{ $n($s['damaged_after']) }}</td>
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
                <tr><td>- {{ __('Loss on units damaged after arrival') }}</td><td class="r">{{ $m($s['loss_after']) }}</td></tr>
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
                        <td class="r">{{ $m($row['loss'] + $row['loss_after']) }}</td>
                        <td class="r">{{ $m($row['gross_profit']) }}</td>
                        <td class="r">{{ $m($row['extra_cost'] + $row['ad_cost']) }}</td>
                        <td class="r">{{ $m($row['net_profit']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2 class="page" style="margin-top: 0;">{{ __('Products overview') }} ({{ __('one page for each product follows') }})</h2>
        <table>
            <thead>
                <tr>
                    <th>{{ __('Product') }}</th>
                    <th class="r">{{ __('Billed qty') }}</th>
                    <th class="r">{{ __('Short') }}</th>
                    <th class="r">{{ __('Damaged') }}</th>
                    <th class="r">{{ __('Damaged after arrival') }}</th>
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
                        <td class="r">{{ $n($row['damaged_after']) }}</td>
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
                    <td class="r">{{ $n($s['damaged_after']) }}</td>
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

        @foreach ($report['product_pages'] as $page)
            @php
                $units = $page['units'];
                $totals = $page['totals'];
                $split = $page['sales_split'];
                $check = $page['stock_check'];
            @endphp

            <div class="page">
                <div class="product-title">
                    {{ $page['product'] }}
                    <small>
                        @if ($page['sku']) {{ __('SKU') }}: {{ $page['sku'] }} | @endif
                        {{ __('Supplier') }}: {{ $supplier['name'] }} | {{ __('Purchased') }}: {{ $periodText }}
                    </small>
                </div>

                <table class="layout">
                    <tbody>
                        <tr>
                            <td style="width: 46%;">
                                <h2 style="margin-top: 0;">{{ __('Units account') }}</h2>
                                <table>
                                    <tbody>
                                        <tr class="strong"><td>{{ __('Billed by the supplier') }}</td><td class="r">{{ $n($units['billed']) }}</td></tr>
                                        <tr><td>- {{ __('Short (billed, never delivered)') }}</td><td class="r">{{ $n($units['short']) }}</td></tr>
                                        <tr><td>- {{ __('Damaged on arrival') }}</td><td class="r">{{ $n($units['damaged']) }}</td></tr>
                                        <tr class="total"><td>{{ __('Received in good condition') }}</td><td class="r">{{ $n($units['received']) }}</td></tr>
                                        <tr><td>- {{ __('Sold to customers') }}</td><td class="r">{{ $n($units['sold']) }}</td></tr>
                                        <tr><td>- {{ __('Damaged after arrival') }}</td><td class="r">{{ $n($units['damaged_after']) }}</td></tr>
                                        @foreach ($units['damage_sources'] as $source => $pieces)
                                            <tr class="sub"><td>{{ $report['damage_labels'][$source] ?? $source }}</td><td class="r">{{ $n($pieces) }}</td></tr>
                                        @endforeach
                                        <tr><td>- {{ __('Taken out by stock adjustments') }}</td><td class="r">{{ $n($units['adjusted_out']) }}</td></tr>
                                        <tr class="total"><td>{{ __('In stock now (this supplier)') }}</td><td class="r">{{ $n($units['in_stock']) }}</td></tr>
                                    </tbody>
                                </table>
                                <div class="check {{ $units['difference'] === 0 ? 'ok' : 'bad' }}">
                                    {{ __('Check') }}: {{ $n($units['billed']) }} = {{ $n($units['short']) }} + {{ $n($units['damaged']) }} + {{ $n($units['sold']) }} + {{ $n($units['damaged_after']) }} + {{ $n($units['adjusted_out']) }} + {{ $n($units['in_stock']) }}
                                    {{ $units['difference'] === 0 ? '- OK' : '- NOT EQUAL ('.$n($units['difference']).')' }}
                                </div>
                            </td>
                            <td class="gap"></td>
                            <td>
                                <h2 style="margin-top: 0;">{{ __('Sold at these prices') }}</h2>
                                @if ($page['prices'] === [])
                                    <p class="note">{{ __('Nothing sold yet.') }}</p>
                                @else
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>{{ __('Price per piece') }}</th>
                                                <th class="r">{{ __('Pieces') }}</th>
                                                <th class="r">{{ __('Amount') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($page['prices'] as $price)
                                                <tr>
                                                    <td>{{ $m($price['price']) }}</td>
                                                    <td class="r">{{ $n($price['pieces']) }}</td>
                                                    <td class="r">{{ $m($price['amount']) }}</td>
                                                </tr>
                                            @endforeach
                                            <tr class="total">
                                                <td>{{ __('Total') }} ({{ __('average') }} {{ $m($totals['avg_price']) }})</td>
                                                <td class="r">{{ $n($totals['sold']) }}</td>
                                                <td class="r">{{ $m($totals['revenue']) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                @endif

                                <h2>{{ __('Where the sales of this product came from') }}</h2>
                                <table>
                                    <tbody>
                                        <tr class="strong"><td>{{ __('All sales of this product') }}</td><td class="r">{{ $n($split['all']) }}</td></tr>
                                        <tr><td>- {{ __('From this supplier') }}</td><td class="r">{{ $n($split['this_supplier']) }}</td></tr>
                                        <tr><td>- {{ __('From opening stock, other suppliers or other purchase dates') }}</td><td class="r">{{ $n($split['other_lots']) }}</td></tr>
                                        <tr><td>- {{ __('Not matched to any recorded stock') }}</td><td class="r">{{ $n($split['unmatched']) }}</td></tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h2>{{ __('Purchases of this product') }}</h2>
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Purchase') }}</th>
                            <th class="r">{{ __('Billed qty') }}</th>
                            <th class="r">{{ __('Unit cost') }}</th>
                            <th class="r">{{ __('Short') }}</th>
                            <th class="r">{{ __('Damaged') }}</th>
                            <th class="r">{{ __('Received good') }}</th>
                            <th class="r">{{ __('Sold') }}</th>
                            <th class="r">{{ __('Damaged after arrival') }}</th>
                            <th class="r">{{ __('Adjusted out') }}</th>
                            <th class="r">{{ __('In stock') }}</th>
                            <th class="r">{{ __('Landed cost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($page['batches'] as $batch)
                            <tr>
                                <td>{{ $batch['purchase_date']?->format($dateFormat) }}</td>
                                <td>{{ $batch['purchase_number'] }}</td>
                                <td class="r">{{ $n($batch['ordered']) }}</td>
                                <td class="r">{{ $m($batch['unit_cost']) }}</td>
                                <td class="r">{{ $n($batch['short']) }}</td>
                                <td class="r">{{ $n($batch['damaged']) }}</td>
                                <td class="r">{{ $n($batch['sellable']) }}</td>
                                <td class="r">{{ $n($batch['sold']) }}</td>
                                <td class="r">{{ $n($batch['damaged_after']) }}</td>
                                <td class="r">{{ $n($batch['adjusted_out']) }}</td>
                                <td class="r">{{ $n($batch['in_stock']) }}</td>
                                <td class="r">{{ $m($batch['landed_cost']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="total">
                            <td colspan="2">{{ __('Total') }}</td>
                            <td class="r">{{ $n($totals['ordered']) }}</td>
                            <td></td>
                            <td class="r">{{ $n($totals['short']) }}</td>
                            <td class="r">{{ $n($totals['damaged']) }}</td>
                            <td class="r">{{ $n($totals['sellable']) }}</td>
                            <td class="r">{{ $n($totals['sold']) }}</td>
                            <td class="r">{{ $n($totals['damaged_after']) }}</td>
                            <td class="r">{{ $n($totals['adjusted_out']) }}</td>
                            <td class="r">{{ $n($totals['in_stock']) }}</td>
                            <td class="r">{{ $m($totals['landed_cost']) }}</td>
                        </tr>
                    </tbody>
                </table>

                <table class="layout" style="margin-top: 4px;">
                    <tbody>
                        <tr>
                            <td style="width: 46%;">
                                <h2>{{ __('Profit on this product') }}</h2>
                                <table>
                                    <tbody>
                                        <tr><td>{{ __('Sales') }}</td><td class="r">{{ $m($totals['revenue']) }}</td></tr>
                                        <tr><td>- {{ __('Landed cost of units sold') }}</td><td class="r">{{ $m($totals['cogs']) }}</td></tr>
                                        <tr><td>- {{ __('Loss on short and damaged units') }}</td><td class="r">{{ $m($totals['loss']) }}</td></tr>
                                        <tr><td>- {{ __('Loss on units damaged after arrival') }}</td><td class="r">{{ $m($totals['loss_after']) }}</td></tr>
                                        <tr class="total"><td>{{ __('Gross profit') }}</td><td class="r">{{ $m($totals['gross_profit']) }}</td></tr>
                                        @foreach ($totals['cost_lines'] as $label => $amount)
                                            <tr><td>- {{ $label }}</td><td class="r">{{ $m($amount) }}</td></tr>
                                        @endforeach
                                        <tr><td>- {{ __('Ad cost (Meta ad spend)') }}</td><td class="r">{{ $m($totals['ad_cost']) }}</td></tr>
                                        <tr class="total"><td>{{ __('Net profit') }}</td><td class="r">{{ $m($totals['net_profit']) }}</td></tr>
                                    </tbody>
                                </table>
                            </td>
                            <td class="gap"></td>
                            <td>
                                <h2>{{ __('Stock today') }}</h2>
                                <table>
                                    <tbody>
                                        <tr><td>{{ __('In stock from this supplier') }}</td><td class="r">{{ $n($check['this_supplier']) }}</td></tr>
                                        <tr><td>+ {{ __('In stock from opening stock, other suppliers or other purchase dates') }}</td><td class="r">{{ $n($check['other_lots']) }}</td></tr>
                                        <tr class="strong"><td>{{ __('Stock this report accounts for') }}</td><td class="r">{{ $n($check['reported']) }}</td></tr>
                                        <tr><td>{{ __('Stock in the system now') }}</td><td class="r">{{ $n($check['system']) }}</td></tr>
                                        <tr class="total"><td>{{ __('Difference') }}</td><td class="r">{{ $n($check['difference']) }}{{ $check['difference'] === 0 ? ' - OK' : '' }}</td></tr>
                                    </tbody>
                                </table>
                                @if ($check['difference'] !== 0)
                                    <p class="note">{{ __('A difference is stock the report cannot place on a supplier, for example returned orders not checked yet, imported order history or pooled stock.') }}</p>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif

    <div class="footer">{{ $company['name'] }} - {{ __('Supplier Report') }}</div>
</body>
</html>
