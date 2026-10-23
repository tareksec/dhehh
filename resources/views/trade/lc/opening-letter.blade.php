{{-- LC Opening Letter (SUMMARY.md document 2), on the importer's letterhead. Wording as in the sample. --}}
@php
    $value = $file->lcValue();
    $usd = fn (float $amount): string => fmod($amount, 1.0) == 0.0 ? number_format($amount, 0) : number_format($amount, 2);
    $margin = rtrim(rtrim(number_format((float) $file->margin_percent, 2, '.', ''), '0'), '.');
    $supplierName = $supplier?->company_name ?: $supplier?->name;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>LC Open Letter - ${{ $usd($value) }}</title>
    @include('trade.lc.partials.styles')
</head>
<body class="letter">
    @include('trade.lc.partials.letterhead')

    <p style="margin-top: 18px;">{{ $file->opening_letter_date->format('d.m.Y') }}</p>

    <p>To<br>The Manager<br>{!! nl2br(e($file->lc_bank)) !!}</p>

    <p class="bold">Subject: Request for Opening Letter of Credit (L/C) against of PI-{{ $purchase->pi_number }} for USD {{ $usd($value) }} with {{ $margin }}% Margin.</p>

    <p>Dear Sir,</p>

    <p class="para">I would like to request you to kindly open a Letter of Credit (L/C) amounting to USD {{ $usd($value) }} ({{ $file->lcValueInWords() }}) in favor of our supplier {{ $supplierName }} for import purposes with {{ $margin }}% margin.</p>

    <p class="para">I would be grateful if you would process the L/C opening as per bank rules and necessary formalities at your earliest convenience. I shall comply with all required documentation, margin deposit, charges and other banking formalities accordingly.</p>

    <p>Thank you for your kind cooperation.</p>

    <p>Sincerely,</p>

    <div class="sign" style="margin-top: 24px;">
        <p>{{ $company?->signatory_name }}</p>
        <p>Account Name: {{ $file->lc_account_name }}</p>
        <p>Account Number: {{ $file->lc_account_number }}</p>
        <p>Signature: @if ($signaturePath)<br><img src="{{ $signaturePath }}" alt="Signature">@endif</p>
    </div>
</body>
</html>
