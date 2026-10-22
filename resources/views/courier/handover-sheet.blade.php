@php
    $currency = $company['currency'] ?? 'BDT';
    $money = fn (float $amount): string => \App\Support\MoneyFormatter::currency($amount, $currency);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Courier handover sheet') }} · {{ $provider->name }} · {{ $sheet['date']->format('d M Y') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Noto Sans Bengali', sans-serif; font-size: 13px; color: #111; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        .meta { margin: 0 0 16px; color: #444; }
        .totals { display: flex; gap: 32px; margin: 0 0 16px; }
        .totals strong { display: block; font-size: 22px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; border: 1px solid #999; text-align: left; vertical-align: top; }
        th { background: #f0f0f0; font-size: 11px; text-transform: uppercase; }
        td.num, th.num { text-align: right; }
        .actions { margin-bottom: 16px; }
        button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" id="print-button">{{ __('Print') }}</button></div>

    <h1>{{ __('Courier handover sheet') }}</h1>
    <p class="meta">{{ $company['name'] ?? '' }} · {{ $provider->name }} · {{ $sheet['date']->format('d M Y') }}</p>

    <div class="totals">
        <div>{{ __('Parcels') }}<strong>{{ $sheet['parcels'] }}</strong></div>
        <div>{{ __('Cash to collect') }}<strong>{{ $money($sheet['cod_total']) }}</strong></div>
    </div>

    @if ($sheet['rows']->isEmpty())
        <p>{{ __('No parcels were booked with this courier on this day.') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('Invoice') }}</th>
                    <th>{{ __('Customer') }}</th>
                    <th>{{ __('Phone') }}</th>
                    <th>{{ __('Address') }}</th>
                    <th class="num">{{ __('Cash to collect') }}</th>
                    <th>{{ __('Consignment / tracking') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sheet['rows'] as $row)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $row['invoice'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['phone'] }}</td>
                        <td>{{ $row['address'] }}</td>
                        <td class="num">{{ $money($row['cod']) }}</td>
                        <td>{{ collect([$row['consignment'], $row['tracking']])->filter()->unique()->implode(' / ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <script>
        (function () {
            // The Android app's WebView has no window.print(); the native
            // bridge is the same one the invoice print page uses.
            var triggerPrint = function () {
                if (window.ZzPrintBridge && typeof window.ZzPrintBridge.print === 'function') {
                    window.ZzPrintBridge.print();
                    return;
                }
                window.print();
            };
            document.getElementById('print-button').addEventListener('click', function () {
                window.focus();
                triggerPrint();
            });
            @if ($autoPrint)
                window.addEventListener('load', function () {
                    window.focus();
                    setTimeout(triggerPrint, 50);
                });
            @endif
        })();
    </script>
</body>
</html>
