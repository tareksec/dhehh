<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $purchase->pl_number ?? $purchase->purchase_number }}</title>
    @include('purchases.documents.partials.styles')
</head>
<body>
    @php
        $dateFormat = $company['date_format'] ?? 'd M Y';
        $items = $purchase->items;
        $hsCodes = $items->map(fn ($item) => $item->hs_code ?: $item->product?->hs_code)->filter()->unique()->implode(', ');
    @endphp

    <div class="company-header">
        <div class="company-name">{{ $supplier?->company_name ?: $supplier?->name }}</div>
        <div class="muted">
            Address: {{ $supplier?->address }}@if ($supplier?->country), {{ $supplier->country }}@endif
            @if ($supplier?->phone) &nbsp; Tel: {{ $supplier->phone }}@endif
            @if ($supplier?->fax) &nbsp; Fax: {{ $supplier->fax }}@endif
        </div>
    </div>

    <div class="title">Packing List</div>

    <table>
        <tr>
            <td style="width: 55%;">
                <div><strong>TO:</strong> {{ $companyRecord?->name }}</div>
                <div>{{ $companyRecord?->address }}</div>
                @if ($companyRecord?->bin_number)<div>BIN No: {{ $companyRecord->bin_number }}</div>@endif
                @if ($companyRecord?->irc_number)<div>IRC No: {{ $companyRecord->irc_number }}</div>@endif
            </td>
            <td style="width: 45%;" class="right">
                @if ($purchase->lc_number)<div>LC/TT No: {{ $purchase->lc_number }} Date: {{ optional($purchase->lc_date)->format($dateFormat) ?? '-' }}</div>@endif
                <div>CI No: {{ $purchase->ci_number ?? '-' }}, Date: {{ optional($purchase->ci_date)->format($dateFormat) ?? '-' }}</div>
                <div>Payment: {{ $purchase->payment_method_summary ?: '-' }}</div>
                <div>Destination: {{ $purchase->port_of_discharge ?: '-' }}</div>
                <div>Delivery Terms: {{ $purchase->delivery_terms ?: '-' }}</div>
                <div>PI No: {{ $purchase->pi_number ?? '-' }}, Date: {{ optional($purchase->pi_date)->format($dateFormat) ?? '-' }}</div>
            </td>
        </tr>
    </table>

    <p class="bold" style="margin: 10px 0;">FROM {{ $purchase->port_of_loading ?: '-' }} TO {{ $purchase->port_of_discharge ?: '-' }}</p>

    <table class="bordered">
        <thead>
            <tr>
                <th>SL/No</th>
                <th>Description of Goods</th>
                <th class="right">PCS</th>
                <th class="right">T.N.W (KGS)</th>
                <th class="right">T.G.W (KGS)</th>
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
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ $item->net_weight_kg ? \App\Support\MoneyFormatter::number((float) $item->net_weight_kg, 3) : '' }}</td>
                    <td class="right">{{ $item->gross_weight_kg ? \App\Support\MoneyFormatter::number((float) $item->gross_weight_kg, 3) : '' }}</td>
                </tr>
            @endforeach
            <tr class="bold">
                <td colspan="2">Total</td>
                <td class="right">{{ $purchase->totalQuantity() }}</td>
                <td class="right">{{ \App\Support\MoneyFormatter::number($purchase->netWeightTotalKg(), 3) }}</td>
                <td class="right">{{ \App\Support\MoneyFormatter::number($purchase->grossWeightTotalKg(), 3) }}</td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 8px;">Total: {{ $purchase->totalQuantity() }} PCS</p>

    <div style="margin-top: 12px;" class="whitespace">
Importer IRC No: {{ $companyRecord?->irc_number ?: '-' }}, TIN No: {{ $companyRecord?->tin_number ?: '-' }}, BIN No: {{ $companyRecord?->bin_number ?: '-' }}, Net Weight of Merchandise {{ \App\Support\MoneyFormatter::number($purchase->netWeightTotalKg(), 3) }} KGS and Gross Weight of Merchandise {{ \App\Support\MoneyFormatter::number($purchase->grossWeightTotalKg(), 3) }} KGS and H.S Code No: {{ $hsCodes ?: '-' }}

{{ $purchase->plCertificationNoteText() }}
    </div>

    <table style="margin-top: 14px;">
        <tr>
            <td style="width: 50%;">
                @if ($supplierSignaturePath)
                    <img src="{{ $supplierSignaturePath }}" alt="Seller stamp">
                @endif
                <div class="muted">{{ $supplier?->company_name ?: $supplier?->name }}</div>
            </td>
            <td style="width: 50%;">
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
