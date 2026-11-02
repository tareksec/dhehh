<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\EmailThread;
use App\Services\CompanyContext;
use App\Services\Email\Inbox\EmailInboxMaintenance;
use Illuminate\Console\Command;

/**
 * Brings snoozed Email inbox conversations back to the Inbox when their time has come, for every company that has one due.
 */
class WakeSnoozedEmails extends Command
{
    protected $signature = 'email:wake-snoozed';

    protected $description = 'Bring snoozed Email inbox conversations back whose snooze time has passed';

    public function handle(CompanyContext $context, EmailInboxMaintenance $maintenance): int
    {
        $companyIds = EmailThread::withoutGlobalScopes()
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->distinct()
            ->pluck('company_id');

        $total = 0;

        foreach (Company::query()->whereIn('id', $companyIds)->get() as $company) {
            $context->set($company);

            try {
                $total += $maintenance->wakeSnoozed();
            } finally {
                $context->clear();
            }
        }

        $this->info("Brought back {$total} conversation(s).");

        return self::SUCCESS;
    }
}
