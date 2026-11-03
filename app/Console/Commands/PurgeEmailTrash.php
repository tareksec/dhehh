<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\EmailThread;
use App\Services\CompanyContext;
use App\Services\Email\Inbox\EmailInboxMaintenance;
use Illuminate\Console\Command;

/**
 * Empties the Email inbox Trash of conversations older than each mailbox's "empty Trash after" days (default 30).
 */
class PurgeEmailTrash extends Command
{
    protected $signature = 'email:purge-trash';

    protected $description = 'Delete Email inbox conversations that have been in Trash longer than the mailbox allows';

    public function handle(CompanyContext $context, EmailInboxMaintenance $maintenance): int
    {
        $companyIds = EmailThread::withoutGlobalScopes()
            ->where('folder', EmailThread::FOLDER_TRASH)
            ->distinct()
            ->pluck('company_id');

        $total = 0;

        foreach (Company::query()->whereIn('id', $companyIds)->get() as $company) {
            $context->set($company);

            try {
                $total += $maintenance->purgeTrash();
            } finally {
                $context->clear();
            }
        }

        $this->info("Deleted {$total} conversation(s) from Trash.");

        return self::SUCCESS;
    }
}
