<?php

namespace App\Console\Commands\Ims;

use App\Ims\Notifications\ImsNotifier;
use App\Ims\Reports\StatementService;
use App\Ims\Settings\ImsSettings;
use App\Models\Company;
use App\Models\InvestorPortalAccount;
use App\Models\StatementDispatch;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * On the 1st: last month's statement to every investor (PRD FR-65), as a PDF
 * by e-mail when that is on, always as a portal notification; in the
 * investor's language; never twice for the same month (statement_dispatches).
 */
class SendMonthlyStatements extends ImsCompanyCommand
{
    protected $signature = 'ims:monthly-statements
        {--company= : Company id (default: every company with IMS on)}
        {--period= : The month (YYYY-MM, default: last month)}';

    protected $description = 'IMS v2: send each investor their monthly statement (once per month).';

    protected function job(): string
    {
        return 'monthly_statements';
    }

    protected function runFor(Company $company): int
    {
        $period = (string) ($this->option('period') ?: now()->subMonthNoOverflow()->format('Y-m'));
        $withPdf = ImsSettings::channelOn($company, 'monthly_statement', 'email');
        $sent = 0;

        $accounts = InvestorPortalAccount::query()->where('company_id', $company->getKey())->whereNotNull('investor_id')
            ->where('status', InvestorPortalAccount::STATUS_ACTIVE)->with('investor')->get();

        foreach ($accounts as $account) {
            if ($account->investor === null) {
                continue;
            }

            try {
                StatementDispatch::query()->create(['company_id' => $company->getKey(), 'investor_id' => $account->investor_id, 'period' => $period, 'sent_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                continue; // already sent for this month
            }

            app(ImsNotifier::class)->send('monthly_statement', $account->investor, [
                'month' => $period,
                'url' => $company->imsUrl('investor/ims/statements/'.$period.'/pdf'),
                'pdf_name' => $withPdf && filled($account->investor->email) ? "statement-{$period}.pdf" : null,
                'pdf_base64' => $withPdf && filled($account->investor->email) ? base64_encode(app(StatementService::class)->pdf($account, $period)) : null,
            ]);
            $sent++;
        }

        return $sent;
    }
}
