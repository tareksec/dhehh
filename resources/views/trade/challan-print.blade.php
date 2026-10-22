{{-- The Delivery Challan to print (TradeChallanPrintController), laid out like the owner's pad. --}}
@php
    use App\Support\ImsNumbers;

    $money = fn ($amount): string => ImsNumbers::lakh((float) $amount, 0);
    $rows = max(12, $challan->items->count());
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Delivery Challan') }} {{ $challan->challan_number }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; font: 14px/1.4 Arial, "Hind Siliguri", sans-serif; background: #fff; }
        .sheet { max-width: 780px; margin: 0 auto; padding: 16px; }
        .letterhead img { display: block; width: 100%; height: auto; }
        .head-text { text-align: center; }
        .head-text h1 { margin: 0; font-size: 26px; letter-spacing: .5px; }
        .head-text p { margin: 2px 0; }
        .bin { text-align: center; font-weight: 700; margin: 6px 0; }
        .title-row { display: flex; align-items: center; justify-content: space-between; margin: 8px 0 12px; }
        .title { padding: 4px 18px; border-radius: 999px; background: #1e3a8a; color: #fff; font-weight: 700; letter-spacing: .5px; }
        .field { display: flex; gap: 6px; margin: 6px 0; }
        .field b { white-space: nowrap; }
        .field span { flex: 1; border-bottom: 1px dotted #555; min-height: 1.3em; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #333; padding: 5px 7px; vertical-align: top; }
        th { background: #e8edf7; }
        td.sl { width: 40px; text-align: center; }
        td.qty { width: 120px; text-align: center; }
        td.rem { width: 120px; }
        td.blank { height: 26px; }
        .price { color: #333; font-size: 13px; }
        .money { margin-top: 10px; width: 100%; }
        .money td { border: 0; padding: 2px 0; }
        .money td:last-child { text-align: right; font-weight: 700; }
        .terms { margin-top: 14px; white-space: pre-line; font-size: 13px; }
        .signs { display: flex; justify-content: space-between; margin-top: 48px; }
        .sign { width: 45%; border-top: 1px dotted #333; padding-top: 4px; }
        .sign.right { text-align: right; }
        .tools { text-align: center; margin: 12px; }
        @media print { .tools { display: none; } .sheet { padding: 0; } }
    </style>
</head>
<body>
<div class="tools"><button type="button" id="report-print">{{ __('Print') }}</button></div>
<div class="sheet">
    @if ($letterhead)
        <div class="letterhead"><img src="{{ $letterhead }}" alt="{{ $company?->name }}"></div>
    @else
        <div class="head-text">
            <h1>{{ $company?->name }}</h1>
            @if ($company?->factory_address ?: $company?->address)<p>{{ $company->factory_address ?: $company->address }}</p>@endif
            <p>{{ collect([$company?->phone, $company?->email])->filter()->implode(' · ') }}</p>
        </div>
    @endif

    @if ($company?->challanBin())
        <div class="bin">{{ __('BIN NO: :bin', ['bin' => $company->challanBin()]) }}</div>
    @endif

    <div class="title-row">
        <div><b>{{ __('SL. No.') }}</b> {{ $challan->challan_number }}</div>
        <div class="title">{{ __('DELIVERY CHALLAN') }}</div>
        <div><b>{{ __('Date') }}:</b> {{ $challan->challan_date?->format('d.m.Y') }}</div>
    </div>

    <div class="field"><b>{{ __('Buyer/Party Name') }}:</b><span>{{ $challan->buyer_name }}{{ $challan->buyer_phone ? ' · '.$challan->buyer_phone : '' }}</span></div>
    <div class="field"><b>{{ __('Address') }}:</b><span>{{ $challan->buyer_address }}</span></div>

    <table>
        <thead>
            <tr><th>{{ __('Sl.') }}</th><th>{{ __('Description of Goods') }}</th><th>{{ __('Quantity/Unit') }}</th><th>{{ __('Remarks') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($challan->items as $index => $item)
                <tr>
                    <td class="sl">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</td>
                    <td>
                        {!! nl2br(e($item->description)) !!}
                        @if ($item->unit_price !== null)
                            <div class="price">{{ __('Price: :price × :quantity = :total', ['price' => $money($item->unit_price), 'quantity' => $item->quantity, 'total' => $money($item->lineTotal())]) }}</div>
                        @endif
                    </td>
                    <td class="qty">{{ $item->quantity ?: '' }} {{ $item->unit }}</td>
                    <td class="rem">{{ $item->remarks }}</td>
                </tr>
            @endforeach
            @for ($i = $challan->items->count(); $i < $rows; $i++)
                <tr><td class="sl">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</td><td class="blank"></td><td></td><td></td></tr>
            @endfor
        </tbody>
    </table>

    <table class="money">
        <tr><td>{{ __('Total price') }}</td><td>{{ $money($challan->total) }}</td></tr>
        @if ((float) $challan->advance_amount > 0)
            <tr><td>{{ __('Advance') }} {{ $challan->advance_date?->format('d/m/Y') }} {{ $challan->advance_note }}</td><td>{{ $money($challan->advance_amount) }}</td></tr>
        @endif
        @if ((float) $challan->cash_amount > 0)
            <tr><td>{{ __('Cash paid') }} {{ $challan->cash_date?->format('d/m/Y') }}</td><td>{{ $money($challan->cash_amount) }}</td></tr>
        @endif
        <tr><td>{{ __('Due') }}</td><td>{{ $money($challan->dueOnPaper()) }}</td></tr>
        @if ($challan->cheque_number)
            <tr><td colspan="2">{{ __('Cheque no.') }} {{ $challan->cheque_number }} {{ $challan->cheque_bank }}</td></tr>
        @endif
        @if ($challan->due_terms)
            <tr><td colspan="2">{{ $challan->due_terms }}</td></tr>
        @endif
    </table>

    @if ($terms !== '')
        <div class="terms">{{ $terms }}</div>
    @endif

    <div class="signs">
        <div class="sign">{{ __('Received by') }}</div>
        <div class="sign right">
            {{ __('Authorised Signature') }}
            @if ($challan->authorised_name)<br>{{ $challan->authorised_name }}@endif
            @if ($challan->authorised_designation)<br>{{ $challan->authorised_designation }}@endif
            @if ($challan->authorised_phone)<br>{{ $challan->authorised_phone }}@endif
        </div>
    </div>
</div>
@include('investments.reports.partials.print-script')
</body>
</html>
