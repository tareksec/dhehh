<?php

namespace App\Http\Controllers\InvestorPortal;

use App\Http\Controllers\Admin\InvestmentReportController;
use App\Http\Controllers\Controller;
use App\Models\InvestorPortalAccount;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Services\InvestorPortalService;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Printable investor-portal papers (browser "Save as PDF"; dompdf cannot
 * shape Bangla): the payout report the admin side already prints, and the
 * statement. Both are limited to the signed-in investor's own records.
 */
class InvestorPortalReportController extends Controller
{
    public function payout(Request $request, int $payout, InvestorPortalService $portal, CompanyContext $context, CompanySettingsService $settings): View
    {
        $account = $this->investorAccount();
        $record = $portal->payouts($account)->whereKey($payout)->first();

        abort_if($record === null, 404);

        return InvestmentReportController::investorPayoutView($record, $settings->profile($context->company()), $request->query('print') === '1');
    }

    public function statement(Request $request, InvestorPortalService $portal, CompanyContext $context, CompanySettingsService $settings): View
    {
        $account = $this->investorAccount();
        $from = $this->date($request->query('from'));
        $until = $this->date($request->query('until'));
        $company = $context->company();

        return view('investor-portal.statement', [
            'company' => $settings->profile($company),
            'currency' => $company?->currency ?: 'BDT',
            'account' => $account,
            'investor' => $account->investor,
            'entries' => $portal->statement($account, $from, $until),
            'summary' => $portal->investorSummary($account),
            'from' => $from,
            'until' => $until,
            'autoPrint' => $request->query('print') === '1',
        ]);
    }

    protected function investorAccount(): InvestorPortalAccount
    {
        $account = Filament::auth()->user();
        abort_unless($account instanceof InvestorPortalAccount && $account->isInvestor(), 404);

        return $account;
    }

    protected function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
