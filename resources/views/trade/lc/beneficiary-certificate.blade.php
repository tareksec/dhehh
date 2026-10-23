{{-- Beneficiary's Certificate (SUMMARY.md document 8): the five clauses, goods, weights, PI and statutory lines as in the sample. --}}
@php
    $tin = $company?->tin_number;
    $bin = $company?->bin_number;
    $origin = mb_strtoupper((string) $purchase->country_of_origin);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Beneficiary's Certificate {{ $purchase->lc_number }}</title>
    @include('trade.lc.partials.styles')
</head>
<body>
    @include('trade.lc.partials.supplier-header')

    <div class="title">Beneficiary's Certificate</div>

    <table>
        <tr>
            <td style="width: 65%;">
                <div>TO: <span class="upper">{{ $company?->name }} {{ $company?->registered_address ?: $company?->address }}</span></div>
                <div>BIN NO: {{ $bin }}</div>
            </td>
            <td class="right">DATE: {{ $file->certificate_date->format('jS M Y') }}</td>
        </tr>
    </table>

    <div class="upper" style="margin-top: 12px;">
        <p class="para">WE CERTIFY THAT,</p>
        <p class="para">A) EACH SET OF NON-NEGOTIABLE DOCUMENTS INCLUDE DETAILED PACKING LIST HAS BEEN DISPATCHED TO THE APPLICANT BY COURIER OR THROUGH EMAIL WITHIN 05(FIVE) DAYS AFTER SHIPMENT BY COURIER/EMAIL</p>
        <p class="para">B) THE QUANTITY, QUALITY, CLASSIFICATION, DESCRIPTION, SPECIFICATION AND WEIGHT OF THE GOODS ARE FULLY AS PER L/C TERMS.</p>
        <p class="para">C) COUNTRY OF ORIGIN HAS BEEN MENTIONED CLEARLY ON THE PACKAGES OF GOODS/POTS/BOX/CONTAINER ETC.</p>
        <p class="para">D) APPLICANT'S NAME, ADDRESS, TIN NO: {{ $tin }}, BIN NO: {{ $bin }} HAS BEEN INSCRIBED OR PRINTED IN INDELIBLE INK ON AT LEAST 2% OF THE LARGEST PACKET/COVER/TINNED PACKAGE/SACK PACK/WOODEN BOX/CASE/ROLL/OTHER PACKET CONTAINING THE IMPORTED GOODS.</p>
        @if ($file->goods_used)
            <p class="para">E) THE GOODS ARE USED MACHINE</p>
        @endif

        <p class="para" style="margin-top: 14px;">DESCRIPTION OF GOODS: {{ $file->goodsDescription() }}</p>
        <p class="para">TOTAL NET WEIGHT: {{ number_format($file->netWeight(), 0, '.', '') }} KGS AND TOTAL GROSS WEIGHT: {{ number_format($file->grossWeight(), 0, '.', '') }} KGS</p>
        <p class="para">{{ $purchase->delivery_terms }} (INCOTERMS 2020) AS PER PROFORMA INVOICE NO. {{ $purchase->pi_number }} DATE: {{ $purchase->pi_date->format('Y.m.d') }} OF THE BENEFICIARY. THE PRICE, QUALITY, QUANTITY, CLASSIFICATION, DESCRIPTION, SPECIFICATION AND ALL OTHER DETAILS OF THE GOODS MUST BE IN ACCORDANCE WITH THE ABOVE PROFORMA INVOICE.</p>
        <p class="para">IRC NO. {{ $company?->irc_number }}, TIN NUMBER: {{ $tin }}, BIN NO. {{ $bin }}, AND LC NUMBER {{ $purchase->lc_number }} DATE: {{ $purchase->lc_date->format('d M Y') }} BIN NO. {{ $file->bank_bin }} OF {{ $file->bank_branch }}</p>
        <p class="para">COUNTRY OF ORIGIN: {{ $origin }}</p>
    </div>

    <div class="sign" style="margin-top: 24px;">
        @if ($supplierSignaturePath)<img src="{{ $supplierSignaturePath }}" alt="Seal"><br>@endif
        <span class="upper">{{ $supplier?->company_name ?: $supplier?->name }}</span>
    </div>
</body>
</html>
