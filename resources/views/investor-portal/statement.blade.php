@php
    use App\Support\MoneyFormatter;
    $money = fn ($v) => MoneyFormatter::currency((float) $v, $currency);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Investor statement') }} — {{ $investor?->reportName() ?? $account->name }}</title>
    @include('investments.reports.partials.report-styles')
</head>
<body>
    <div class="print-actions">
        <button class="print-button" id="report-print" type="button">{{ __('Print') }}</button>
    </div>

    <div class="sheet">
        <div class="head">
            <div class="brand">
                {{ $company['name'] }}
                @if (! empty($company['address']))<div class="sub">{{ $company['address'] }}</div>@endif
            </div>
            @if (! empty($company['logo_url']))
                <img src="{{ $company['logo_url'] }}" alt="{{ $company['name'] }}">
            @endif
        </div>

        <h1 class="title">{{ __('Investor statement') }}</h1>
        <div class="deal">
            <strong>{{ $investor?->reportName() ?? $account->name }}</strong>
            @if ($investor?->investorId()) &mdash; {{ __('Investor ID') }}: {{ $investor->investorId() }} @endif
            <div class="sub">
                {{ $from ? $from->format('d M Y') : __('From the start') }} &ndash; {{ ($until ?? now())->format('d M Y') }}
            </div>
        </div>

        <table>
            <thead><tr><th>{{ __('Summary') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
            <tbody>
                <tr><td>{{ __('Capital at work') }}</td><td class="num">{{ $money($summary['capital_at_work']) }}</td></tr>
                <tr><td>{{ __('Total invested') }}</td><td class="num">{{ $money($summary['total_invested']) }}</td></tr>
                <tr><td>{{ __('Profit received') }}</td><td class="num">{{ $money($summary['profit_received']) }}</td></tr>
                <tr><td>{{ __('Profit confirmed, not yet paid') }}</td><td class="num">{{ $money($summary['profit_pending']) }}</td></tr>
                <tr><td>{{ __('Total paid to you') }}</td><td class="num">{{ $money($summary['paid_out']) }}</td></tr>
            </tbody>
        </table>

        <table>
            <thead>
                <tr>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Entry') }}</th>
                    <th>{{ __('Project') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td>{{ $entry['date']->format('d M Y') }}</td>
                        <td>{{ $entry['label'] }}</td>
                        <td>{{ $entry['project'] }}</td>
                        <td class="num">
                            @if ($entry['info_amount'] !== null)
                                {{ $money($entry['info_amount']) }}
                            @else
                                {{ $entry['amount'] < 0 ? '−' : '+' }}{{ $money(abs($entry['amount'])) }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">{{ __('Nothing to show yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        <p class="sub">{{ __('Printed on :date. Profit and loss appear once the company confirms a project\'s settlement.', ['date' => now()->format('d M Y')]) }}</p>
    </div>

    @include('investments.reports.partials.print-script', ['autoPrint' => $autoPrint])
</body>
</html>
