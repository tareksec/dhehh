<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $purchase->pi_number ?? $purchase->purchase_number }}</title>
    @include('purchases.documents.partials.styles')
</head>
<body>
    @php
        $dateFormat = $company['date_format'] ?? 'd M Y';
        $items = $purchase->items;
    @endphp

    <table>
        <tr>
            <td style="width: 55%;">
                <div class="bold" style="font-size: 13px;">{{ $supplier?->company_name ?: $supplier?->name }}</div>
                <div class="muted">Address: {{ $supplier?->address }}@if ($supplier?->country), {{ $supplier->country }}@endif</div>
                @if ($supplier?->phone)<div class="muted">Tel: {{ $supplier->phone }}</div>@endif
                @if ($supplier?->fax)<div class="muted">Fax: {{ $supplier->fax }}</div>@endif
            </td>
            <td style="width: 45%;" class="right">
                <div><strong>PI No.:</strong> {{ $purchase->pi_number ?? '-' }}</div>
                <div><strong>Date:</strong> {{ optional($purchase->pi_date)->format($dateFormat) ?? '-' }}</div>
                <div><strong>Port Of Loading:</strong> {{ $purchase->port_of_loading ?: '-' }}</div>
                <div><strong>Discharge Port:</strong> {{ $purchase->port_of_discharge ?: '-' }}</div>
                <div><strong>Delivery Terms:</strong> {{ $purchase->delivery_terms ?: '-' }}</div>
                <div><strong>Country Of Origin:</strong> {{ $purchase->country_of_origin ?: '-' }}</div>
            </td>
        </tr>
    </table>

    <table style="margin-top: 12px;">
        <tr>
            <td>
                <strong>Buyer:</strong><br>
                TO: {{ $companyRecord?->name }}<br>
                {{ $companyRecord?->address }}<br>
                @if ($companyRecord?->bin_number)BIN: {{ $companyRecord->bin_number }}<br>@endif
                @if ($companyRecord?->irc_number)IRC: {{ $companyRecord->irc_number }}<br>@endif
                @if ($companyRecord?->email)Email: {{ $companyRecord->email }}<br>@endif
                @if ($companyRecord?->phone)Phone: {{ $companyRecord->phone }}@endif
            </td>
        </tr>
    </table>

    <p class="muted" style="margin: 12px 0;">The undersigned Sellers and Buyers have agreed to close the following transactions according to the terms and conditions stipulated below:</p>

    <table class="bordered">
        <thead>
            <tr>
                <th>SL</th>
                <th>Item / Model</th>
                <th>HS Code</th>
                <th class="right">QTY (PCS)</th>
                <th class="right">FOB Price (USD)</th>
                <th class="right">Amount (USD)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>
                        {{ $item->product?->name }}
                        @if ($item->spec_note)<br><span class="muted">{{ $item->spec_note }}</span>@endif
                    </td>
                    <td>{{ $item->hs_code ?: $item->product?->hs_code ?: '-' }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ \App\Support\MoneyFormatter::number((float) $item->fob_unit_price_usd) }}</td>
                    <td class="right">{{ \App\Support\MoneyFormatter::number((int) $item->quantity * (float) $item->fob_unit_price_usd) }}</td>
                </tr>
            @endforeach
            @if ($purchase->freight_usd)
                <tr>
                    <td colspan="4">Freight</td>
                    <td></td>
                    <td class="right">{{ \App\Support\MoneyFormatter::number((float) $purchase->freight_usd) }}</td>
                </tr>
            @endif
            <tr class="bold">
                <td colspan="5">TOTAL :</td>
                <td class="right">{{ \App\Support\MoneyFormatter::number($purchase->cfrTotalUsd()) }}</td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 10px;">In Word: {{ $purchase->cfrAmountInWords() }}</p>

    <table style="margin-top: 14px;">
        <tr>
            <td style="width: 50%;">
                <strong>THE DETAIL OF BANK:</strong><br>
                ADD: {{ $supplier?->address }}
            </td>
            <td style="width: 50%;">
                <strong>BENEFICIARY:</strong> {{ $supplier?->bank_account_name ?: $supplier?->company_name ?: $supplier?->name }}<br>
                BENEFICIARY'S A/C NO.: {{ $supplier?->bank_account_number ?: '-' }}<br>
                {{ $supplier?->bank_name ?: '-' }}<br>
                @if ($supplier?->bank_address)ADD: {{ $supplier->bank_address }}<br>@endif
                SWIFT CODE: {{ $supplier?->bank_swift_code ?: '-' }}<br>
                @if ($supplier?->bank_extra_note){{ $supplier->bank_extra_note }}@endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 12px;">
        <strong>Payment Terms:</strong>
        <div class="whitespace">{{ $purchase->paymentTermsText() }}</div>
    </div>

    <table class="signature-block">
        <tr>
            <td style="width: 50%;">
                <strong>The Buyer:</strong><br>
                @if ($companySignaturePath)
                    <img src="{{ $companySignaturePath }}" alt="Signature">
                @endif
                <div>{{ $companyRecord?->signatory_name }}</div>
                <div class="muted">{{ $companyRecord?->signatory_title }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
