@php
    use App\Support\MoneyFormatter;
    $money = fn ($v) => MoneyFormatter::currency((float) $v);
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.').'%';
    $cycleDays = $project->trade_cycle_days;
    $lakhs = round((float) $payout->investment_amount / 100000, 4);
@endphp
<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Investor Report — {{ $investor->reportName() }} — {{ $project->project_code }}</title>
    @include('investments.reports.partials.report-styles')
</head>
<body>
    <div class="print-actions">
        <button class="print-button" id="report-print" type="button">Print</button>
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

        <h1 class="title">বিনিয়োগ মুনাফা রিপোর্ট</h1>
        <div class="deal">
            <strong>{{ $project->project_code }}</strong>
            @if ($project->deal_reference) &mdash; {{ $project->deal_reference }} @endif
        </div>

        <table>
            <thead><tr><th>Purchase / Landed Cost</th><th class="num">Amount (৳)</th></tr></thead>
            <tbody>
                @foreach ($landedCosts as $item)
                    <tr><td>{{ $item->label }}</td><td class="num">{{ $money($item->amount) }}</td></tr>
                @endforeach
                <tr class="total"><td>Total Landed Cost</td><td class="num">{{ $money($project->totalLandedCost()) }}</td></tr>
            </tbody>
        </table>

        <div class="kv"><span class="k">Selling Amount</span><span class="v">{{ $money($settlement->total_revenue) }}</span></div>
        <div class="kv"><span class="k">Gross Profit (Selling &minus; Landed Cost)</span><span class="v">{{ $money($grossProfit) }}</span></div>

        @if ($localExpenses->isNotEmpty())
            <table>
                <thead><tr><th>Local Expenses</th><th class="num">Amount (৳)</th></tr></thead>
                <tbody>
                    @foreach ($localExpenses as $item)
                        <tr><td>{{ $item->label }}</td><td class="num">{{ $money($item->amount) }}</td></tr>
                    @endforeach
                    <tr class="total"><td>Total Local Expenses</td><td class="num">{{ $money($project->totalLocalExpense()) }}</td></tr>
                </tbody>
            </table>
        @endif

        <div class="kv"><span class="k">Total Direct Cost</span><span class="v">{{ $money($settlement->total_cost) }}</span></div>
        <div class="kv"><span class="k">Net Profit</span><span class="v">{{ $money($settlement->net_profit) }}</span></div>

        <div class="callout">
            <div class="kv">
                <span class="k">{{ $pct($project->investor_share_percent) }} to investors (pool)</span>
                <span class="big">{{ $money($settlement->investor_pool_amount) }}</span>
            </div>
            <div class="kv">
                <span class="k">Trade cycle</span>
                <span class="v">{{ $cycleDays ? $cycleDays.' days' : '—' }}</span>
            </div>
            @if (! is_null($settlement->annualized_return_percent))
                <div class="kv">
                    <span class="k">Annualized (yearly) return</span>
                    <span class="v">{{ $pct($settlement->annualized_return_percent) }}</span>
                </div>
            @endif
            @if (! is_null($settlement->rate_per_lac))
                <div class="kv">
                    <span class="k">Rate per lac</span>
                    <span class="v">{{ $money($settlement->rate_per_lac) }}</span>
                </div>
            @endif
        </div>

        <table>
            <thead><tr><th>{{ $investor->reportName() }}</th><th class="num">Amount (৳)</th></tr></thead>
            <tbody>
                <tr>
                    <td>Invested {{ rtrim(rtrim(number_format($lakhs, 2), '0'), '.') }} lac
                        @if (! is_null($settlement->rate_per_lac)) &times; {{ $money($settlement->rate_per_lac) }} per lac @endif</td>
                    <td class="num">Principal {{ $money($payout->investment_amount) }}</td>
                </tr>
                @if (! is_null($payout->principal_returned_amount))
                    <tr>
                        <td>Principal returned with this payout
                            @if ((float) $payout->principal_returned_amount <= 0) (stays invested in the group) @endif</td>
                        <td class="num">{{ $money($payout->principal_returned_amount) }}</td>
                    </tr>
                @endif
                <tr><td>Profit share</td><td class="num">{{ $money($payout->profit_share_amount) }}</td></tr>
                <tr class="total"><td>Total Payout</td><td class="num">{{ $money($payout->total_payout) }}</td></tr>
            </tbody>
        </table>

        @if ($payout->recipient_bank_name || $payout->recipient_account_number)
            <div class="kv"><span class="k">Bank</span><span class="v">{{ collect([$payout->recipient_bank_name, $payout->recipient_branch, $payout->recipient_account_number])->filter()->implode(', ') }}</span></div>
        @endif

        <div class="status-line {{ $payout->payment_status === 'paid' ? 'paid' : 'pending' }}">
            @if ($payout->payment_status === 'paid')
                <strong>Paid</strong> @if ($payout->paid_at) on {{ $payout->paid_at->format($company['date_format'] ?? 'd M Y') }} @endif
                @if ($payout->payment_reference) &mdash; ref {{ $payout->payment_reference }} @endif
            @else
                <strong>Payment pending</strong>
            @endif
        </div>

        <div class="foot">
            {{ $company['name'] }} &mdash; generated {{ now()->format('d M Y, h:i A') }}
        </div>
    </div>

    @include('investments.reports.partials.print-script', ['autoPrint' => $autoPrint])
</body>
</html>
