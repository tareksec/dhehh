<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $purchase->ci_number ?? $purchase->purchase_number }}</title>
    @include('purchases.documents.partials.styles')
</head>
<body>
    @php
        $dateFormat = $company['date_format'] ?? 'd M Y';
        $items = $purchase->items;
    @endphp

    <div class="company-header">
        <div class="company-name">{{ $supplier?->company_name ?: $supplier?->name }}</div>
        <div class="muted">{{ $supplier?->address }}@if ($supplier?->country), {{ $supplier->country }}@endif</div>
        @if ($supplier?->phone)<div class="muted">Tel: {{ $supplier->phone }}</div>@endif
    </div>

    <div class="title">Commercial Invoice</div>

    <table>
        <tr>
            <td style="width: 55%;">
                <div><strong>TO:</strong> {{ $companyRecord?->name }}</div>
                <div>{{ $companyRecord?->address }}</div>
                @if ($companyRecord?->email)<div>Email: {{ $companyRecord->email }}</div>@endif
                @if ($companyRecord?->phone)<div>Phone: {{ $companyRecord->phone }}</div>@endif
                @if ($companyRecord?->bin_number)<div>BIN No: {{ $companyRecord->bin_number }}</div>@endif
                @if ($companyRecord?->irc_number)<div>IRC: {{ $companyRecord->irc_number }}</div>@endif
                @if ($purchase->lc_number)<div>LC No: {{ $purchase->lc_number }} @if ($purchase->lc_date) Date: {{ $purchase->lc_date->format($dateFormat) }} @endif</div>@endif
                <div>Loading Port: {{ $purchase->port_of_loading ?: '-' }}</div>
            </td>
            <td style="width: 45%;" class="right">
                <div>CI No: {{ $purchase->ci_number ?? '-' }}, Date: {{ optional($purchase->ci_date)->format($dateFormat) ?? '-' }}</div>
                <div>Payment: {{ $purchase->payment_method_summary ?: '-' }}</div>
                <div>Destination: {{ $purchase->port_of_discharge ?: '-' }}</div>
                <div>Delivery Terms: {{ $purchase->delivery_terms ?: '-' }}</div>
                <div>PI No: {{ $purchase->pi_number ?? '-' }}, Date: {{ optional($purchase->pi_date)->format($dateFormat) ?? '-' }}</div>
            </td>
        </tr>
    </table>

    <table class="bordered" style="margin-top: 10px;">
        <thead>
            <tr>
                <th>SL</th>
                <th>Description of Goods</th>
                <th>HS Code</th>
                <th class="right">Qty</th>
                <th>Unit</th>
                <th class="right">Per Unit Price (USD)</th>
                <th class="right">Amount (USD)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>
                        {{ $item->product?->name }}
                        @if ($item->spec_note) — {{ $item->spec_note }}@endif
                    </td>
                    <td>{{ $item->hs_code ?: $item->product?->hs_code ?: '-' }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td>{{ $item->product?->unit ?: 'pcs' }}</td>
                    <td class="right">{{ \App\Support\MoneyFormatter::number((float) $item->fob_unit_price_usd) }}</td>
                    <td class="right">{{ \App\Support\MoneyFormatter::number((int) $item->quantity * (float) $item->fob_unit_price_usd) }}</td>
                </tr>
            @endforeach
            <tr class="bold">
                <td colspan="3">Total</td>
                <td class="right">{{ $purchase->totalQuantity() }}</td>
                <td></td>
                <td class="right">Total</td>
                <td class="right">{{ \App\Support\MoneyFormatter::number($purchase->fobTotalUsd()) }}</td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 10px;">Say USD$: {{ $purchase->fobAmountInWords() }}</p>

    <table style="margin-top: 12px;">
        <tr>
            <td style="width: 55%;">
                <strong>Terms &amp; Conditions:</strong>
                <div class="whitespace">{{ $purchase->termsConditionsText() }}</div>
            </td>
            <td style="width: 45%;">
                <table>
                    <tr><td>Total Amount FOB USD</td><td class="right">{{ \App\Support\MoneyFormatter::number($purchase->fobTotalUsd()) }}</td></tr>
                    <tr><td>Freight Charge USD</td><td class="right">{{ \App\Support\MoneyFormatter::number((float) $purchase->freight_usd) }}</td></tr>
                    <tr class="bold"><td>Total CFR {{ $purchase->port_of_discharge }}</td><td class="right">{{ \App\Support\MoneyFormatter::number($purchase->cfrTotalUsd()) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 14px;">
        <tr>
            <td>
                <strong>Account Name:</strong> {{ $supplier?->bank_account_name ?: $supplier?->company_name ?: $supplier?->name }}<br>
                <strong>Advising Bank:</strong> {{ $supplier?->bank_name ?: '-' }}<br>
                @if ($supplier?->bank_address)<strong>Bank Address:</strong> {{ $supplier->bank_address }}<br>@endif
                <strong>Account Number:</strong> {{ $supplier?->bank_account_number ?: '-' }}<br>
                @if ($supplier?->bank_swift_code)<strong>SWIFT Code:</strong> {{ $supplier->bank_swift_code }}<br>@endif
                @if ($supplier?->bank_extra_note){{ $supplier->bank_extra_note }}<br>@endif
                <strong>Country/Region:</strong> {{ $supplier?->country ?: '-' }}
            </td>
        </tr>
    </table>

    <table class="signature-block">
        <tr>
            <td style="width: 50%;">
                For and on behalf of<br>
                <strong>{{ strtoupper($supplier?->company_name ?: $supplier?->name ?: '') }}</strong><br>
                @if ($supplierSignaturePath)
                    <img src="{{ $supplierSignaturePath }}" alt="Signature">
                @endif
                <div class="muted">Authorized Signature(s)</div>
            </td>
        </tr>
    </table>
</body>
</html>
