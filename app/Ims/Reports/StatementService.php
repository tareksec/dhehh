<?php

namespace App\Ims\Reports;

use App\Models\Company;
use App\Models\InvestorPortalAccount;
use App\Services\InvestorPortalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * An investor's statement for one month (IMS-V2/PRD.md FR-65): every money
 * event of that month from the portal's own statement (so the two never
 * differ), and where the investor stands at the end of it. In the
 * investor's language; as PDF for the e-mail and the portal download.
 */
class StatementService
{
    public function __construct(private InvestorPortalService $portal) {}

    /**
     * @return array{period: string, from: Carbon, until: Carbon, entries: Collection<int, array<string, mixed>>, money_in: float, money_out: float, summary: array<string, float>}
     */
    public function month(InvestorPortalAccount $account, string $period): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $until = $from->copy()->endOfMonth();
        $entries = $this->portal->statement($account, $from, $until);

        return [
            'period' => $period,
            'from' => $from,
            'until' => $until,
            'entries' => $entries,
            'money_in' => round((float) $entries->where('amount', '>', 0)->sum('amount'), 2),
            'money_out' => round((float) -$entries->where('amount', '<', 0)->sum('amount'), 2),
            'summary' => $this->portal->investorSummary($account),
        ];
    }

    public function pdf(InvestorPortalAccount $account, string $period): string
    {
        $previous = app()->getLocale();

        if (in_array($account->locale, ['en', 'bn'], true)) {
            app()->setLocale($account->locale);
        }

        try {
            return ImsPdf::render('ims.pdf.statement', [
                'account' => $account,
                'statement' => $this->month($account, $period),
                'company' => Company::query()->find($account->company_id),
            ]);
        } finally {
            app()->setLocale($previous);
        }
    }
}
