{{--
    Detail Packing List (SUMMARY.md document 7, sheet DP). One purchase line:
    the file's description and this shipment's weights; several lines: each
    line with its own weights, the totals from the file.
--}}
@php
    $items = $purchase->items;
    $single = $items->count() === 1;
    $unit = mb_strtoupper((string) ($items->first()?->product?->unit ?: 'SET'));
    $kg = fn ($value): string => $value === null || (float) $value == 0.0 ? '' : number_format((float) $value, 0, '.', '');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Detail Packing List {{ $purchase->ci_number }}</title>
    @include('trade.lc.partials.styles')
</head>
<body>
    <div class="company-header">
        <div class="company-name">{{ $supplier?->company_name ?: $supplier?->name }}</div>
        <div class="upper">ADDRESS: {{ $supplier?->address }}@if ($supplier?->country), {{ $supplier->country }}@endif</div>
    </div>

    <div class="title">Detail Packing List</div>

    <table>
        <tr>
            <td style="width: 60%;" class="upper">
                <div>TO: {{ $company?->name }}</div>
                <div>{{ $company?->registered_address ?: $company?->address }}</div>
                <div style="margin-top: 6px;">TIN:{{ $company?->tin_number }} AND BIN:{{ $company?->bin_number }}</div>
            </td>
            <td>
                <div>DATE: {{ $file->certificate_date->format('jS M Y') }}</div>
                <div>CI NO: {{ $purchase->ci_number }}</div>
                <div>DATE: {{ $purchase->ci_date->format('jS F Y') }}</div>
                <div>LC NO.: {{ $purchase->lc_number }}</div>
                <div>LC DATE: {{ $purchase->lc_date->format('jS M Y') }}</div>
            </td>
        </tr>
    </table>

    <table class="bordered" style="margin-top: 14px;">
        <thead>
            <tr>
                <th>SL/NO</th>
                <th>DESCRIPTION OF GOODS</th>
                <th class="center">{{ $unit }}</th>
                <th class="right">T.N.W(KGS)</th>
                <th class="right">T.G.W(KGS)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $index => $item)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $single ? $file->goodsDescription() : trim(($item->product?->name ?? '').' '.($item->spec_note ?? '')) }}</td>
                    <td class="center">{{ $item->quantity }}</td>
                    <td class="right">{{ $single ? $kg($file->netWeight()) : $kg($item->net_weight_kg) }}</td>
                    <td class="right">{{ $single ? $kg($file->grossWeight()) : $kg($item->gross_weight_kg) }}</td>
                </tr>
            @endforeach
            <tr class="bold">
                <td colspan="2" class="center">TOTAL</td>
                <td class="center">{{ $purchase->totalQuantity() }}</td>
                <td class="right">{{ $kg($file->netWeight()) }}</td>
                <td class="right">{{ $kg($file->grossWeight()) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="sign" style="margin-top: 24px;">
        @if ($supplierSignaturePath)<img src="{{ $supplierSignaturePath }}" alt="Seal"><br>@endif
        <span class="upper">{{ $supplier?->company_name ?: $supplier?->name }}</span>
    </div>
</body>
</html>
