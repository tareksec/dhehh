{{--
    Shipment Advice (SUMMARY.md document 9, with the vessel and B/L lines of
    document 7 sheet A). Wording as in the samples; the cover note no. and
    date are filled (mandatory, SUMMARY.md §5.7), as are the shipment date and
    ETA the sample left blank.
--}}
@php
    $origin = mb_strtoupper((string) $purchase->country_of_origin);
    $net = number_format($file->netWeight(), 0, '.', '');
    $gross = number_format($file->grossWeight(), 0, '.', '');
    $shipped = $file->bl_date ?? $file->etd;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Shipment Advice {{ $purchase->lc_number }}</title>
    @include('trade.lc.partials.styles')
</head>
<body>
    @include('trade.lc.partials.supplier-header')

    <div class="title">Shipment Advice</div>

    <table>
        <tr>
            <td style="width: 70%;" class="upper">
                <div>TO: {{ $file->bank_branch }}</div>
                <div>BIN: {{ $file->bank_bin }}</div>
                @if ($file->insurer_name)
                    <div style="margin-top: 6px;">COPY TO: {{ $file->insurer_name }}@if ($file->insurer_address), {{ $file->insurer_address }}@endif</div>
                @endif
            </td>
            <td class="right">@if ($file->shipment_advice_date)DATE: {{ $file->shipment_advice_date->format('jS M Y') }}@endif</td>
        </tr>
    </table>

    <div class="upper" style="margin-top: 12px;">
        <p class="para">DESCRIPTION OF GOODS: {{ $file->goodsDescription() }}<br>TOTAL NET WEIGHT: {{ $net }} KGS AND TOTAL GROSS WEIGHT: {{ $gross }} KGS AND TOTAL PACKAGES: {{ $file->total_packages }}</p>
        <p class="para">{{ $purchase->delivery_terms }} (INCOTERMS 2020) AS PER PROFORMA INVOICE NO. {{ $purchase->pi_number }}, DATE: {{ $purchase->pi_date->format('Y.m.d') }} OF THE BENEFICIARY. THE PRICE, QUALITY, QUANTITY, CLASSIFICATION, DESCRIPTION, SPECIFICATION AND ALL OTHER DETAILS OF THE GOODS MUST BE IN ACCORDANCE WITH THE ABOVE PROFORMA INVOICE.</p>
        <p class="para">IRC NUMBER {{ $company?->irc_number }}, TIN NUMBER {{ $company?->tin_number }}, BIN NUMBER {{ $company?->bin_number }} AND LC NUMBER {{ $purchase->lc_number }} DATE: {{ $purchase->lc_date->format('d M Y') }} BIN NO. {{ $file->bank_bin }} OF {{ $file->bank_branch }}.</p>
        <p class="para">COUNTRY OF ORIGIN: {{ $origin }}</p>
        <p class="para">CONTAINER NO. {{ $file->container_number }} SEAL NO. {{ $file->seal_number }} TOTAL NET WEIGHT: {{ $net }}KGS AND TOTAL GROSS WEIGHT: {{ $gross }} KGS AND TOTAL PACKAGES: {{ $file->total_packages }}{{ $file->cbm ? ' AND TOTAL CBM '.rtrim(rtrim(number_format((float) $file->cbm, 2, '.', ''), '0'), '.').' CBM' : '' }}.</p>
        <p class="para">WE HEREBY CERTIFY THAT THE MERCHANDISE IS {{ $origin }} ORIGIN.</p>
        <p class="para">PORT OF LOADING: {{ $purchase->port_of_loading }}</p>
        @if ($file->port_of_shipment)<p class="para">NAME OF PORT OF SHIPMENT: {{ $file->port_of_shipment }}</p>@endif
        <p class="para">
            PORT OF DISCHARGE: {{ $purchase->port_of_discharge }}<br>
            PLACE OF DELIVERY: {{ $file->place_of_delivery ?: $purchase->port_of_discharge }}<br>
            SHIPMENT DATE: {{ $shipped?->format('d M Y') }}<br>
            ETA DESTINATION PORT: {{ $file->eta?->format('d M Y') }}<br>
            THE NAME OF CARRIER: {{ $file->carrier }}
            @if ($file->vessel_name)<br>NAME OF VESSEL: {{ $file->vessel_name }}@endif
            @if ($file->bl_number)
                <br>BILL OF LADING NO.: {{ $file->bl_number }}
                @if ($file->bl_date)
                    &nbsp; BILL DATE: {{ $file->bl_date->format('jS M Y') }}
                @endif
            @endif
        </p>
        <p class="para">WE HEREBY CERTIFY THAT THE GOODS ARE OF {{ $origin }} ORIGIN.</p>
        <p class="para">QUOTING INSURANCE COVER NOTE NO: {{ $file->cover_note_number }} DTD. {{ $file->cover_note_date->format('d/m/Y') }}</p>
    </div>

    <div class="sign" style="margin-top: 24px;">
        @if ($supplierSignaturePath)<img src="{{ $supplierSignaturePath }}" alt="Seal"><br>@endif
        <span class="upper">{{ $supplier?->company_name ?: $supplier?->name }}</span>
    </div>
</body>
</html>
