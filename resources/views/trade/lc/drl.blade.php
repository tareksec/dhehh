{{-- Discrepancy Release Letter (SUMMARY.md document 6), on the importer's letterhead. Wording as in the sample. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>DRL-${{ number_format($file->lcValue(), 0, '.', '') }} - {{ substr((string) $purchase->lc_number, -3) }}</title>
    @include('trade.lc.partials.styles')
</head>
<body class="letter">
    @include('trade.lc.partials.letterhead')

    @if ($file->drl_date)<p style="margin-top: 18px;">{{ $file->drl_date->format('d.m.Y') }}</p>@endif

    <p style="margin-top: 18px;">To<br>The Manager<br>{!! nl2br(e($file->lc_bank)) !!}</p>

    <p class="bold">Subject: Request for Acceptance of Discrepancies and Release of Full Payment and Documents under L/C No. {{ $purchase->lc_number }}</p>

    <p>Dear Sir,</p>

    <p class="para">With reference to the above-mentioned Letter of Credit, we would like to request you to release below mentioned documents against the accepted documents under the following L/C:</p>

    <table class="list" style="width: auto; margin: 0 0 10px 18px;">
        <tr><td>LC Date:</td><td>{{ $purchase->lc_date->format('d-F-Y') }}</td></tr>
        <tr><td>LC No.:</td><td>{{ $purchase->lc_number }}</td></tr>
        <tr><td>Applicant Name:</td><td class="upper">{{ $company?->name }}</td></tr>
        <tr><td>Currency:</td><td>USD</td></tr>
        <tr><td>Documents Amount:</td><td>USD {{ number_format($file->lcValue(), 2, '.', '') }}</td></tr>
    </table>

    <p class="para">Since the documents with discrepancy have been accepted by us, kindly arrange to release the documents at your earliest convenience.</p>

    <p>Thanking you for your kind cooperation.</p>

    <p>Yours faithfully,</p>

    <div class="sign" style="margin-top: 18px;">
        @if ($signaturePath)<img src="{{ $signaturePath }}" alt="Signature"><br>@endif
        <p class="upper bold">{{ $company?->name }}</p>
        <p>{{ $company?->signatory_name }}@if ($company?->signatory_title)<br>{{ $company->signatory_title }}@endif</p>
    </div>
</body>
</html>
