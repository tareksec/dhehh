<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InvestmentProject;
use App\Models\SettlementPayout;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * v3 gap analysis P1.2 — self-contained printable HTML (open in a tab, print /
 * Save as PDF from the browser). Browser text rendering handles Bengali
 * correctly, which dompdf does not.
 */
class InvestmentReportController extends Controller
{
    public function investorPayout(Request $request, int $payout, CompanyContext $context, CompanySettingsService $settings): View
    {
        $record = SettlementPayout::withoutGlobalScopes()
            ->with(['settlement.project.costItems', 'settlement.project.investments', 'investor'])
            ->findOrFail($payout);

        $company = $this->authorizeCompany($request, $context, $record);

        return self::investorPayoutView($record, $settings->profile($company), $request->query('print') === '1');
    }

    /**
     * The per-investor payout report. Shared with the investor portal
     * (InvestorPortalReportController), which checks ownership itself: the
     * report shows only this investor's own figures and the project's costs.
     */
    public static function investorPayoutView(SettlementPayout $record, array $companyProfile, bool $autoPrint): View
    {
        $record->loadMissing(['settlement.project.costItems', 'settlement.project.investments', 'investor']);
        $project = $record->settlement->project;

        return view('investments.reports.investor-payout', [
            'company' => $companyProfile,
            'payout' => $record,
            'settlement' => $record->settlement,
            'project' => $project,
            'investor' => $record->investor,
            'landedCosts' => $project->costItems->where('category', 'landed_cost')->values(),
            'localExpenses' => $project->costItems->where('category', 'local_expense')->values(),
            'grossProfit' => round((float) $record->settlement->total_revenue - $project->totalLandedCost(), 2),
            'autoPrint' => $autoPrint,
        ]);
    }

    public function projectRegister(Request $request, int $project, CompanyContext $context, CompanySettingsService $settings): View
    {
        $record = InvestmentProject::withoutGlobalScopes()
            ->with(['investments.investor', 'investments.securityInstruments'])
            ->findOrFail($project);

        $company = $this->authorizeCompany($request, $context, $record);

        return view('investments.reports.project-register', [
            'company' => $settings->profile($company),
            'project' => $record,
            'rows' => $record->investments->groupBy('investor_id')->map(function ($investments) {
                $investor = $investments->first()->investor;
                $instrument = $investments->flatMap(fn ($investment) => $investment->securityInstruments)->first();

                return [
                    'investor' => $investor,
                    'amount' => round((float) $investments->sum('amount'), 2),
                    'stamps' => $instrument?->stamp_serial_numbers ?? [],
                    'cheque_number' => $instrument?->cheque_number,
                ];
            })->values(),
            'totalInvested' => $record->totalInvested(),
            'autoPrint' => $request->query('print') === '1',
        ]);
    }

    private function authorizeCompany(Request $request, CompanyContext $context, Model $record): Company
    {
        $user = $request->user();
        $company = $context->company();

        if (! $company && $context->isAllCompanies() && $user?->isSuperAdmin()) {
            $company = Company::query()->find($record->company_id);
        }

        abort_unless(
            $user?->is_active
            && $company
            && (int) $record->company_id === (int) $company->getKey()
            && $user->canAccessCompany((int) $company->getKey()),
            404,
        );
        abort_unless($user->hasPermission('investments.view'), 403);

        return $company;
    }
}
