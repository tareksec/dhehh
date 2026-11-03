<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\EmailMessage;
use App\Services\CompanyContext;
use App\Services\Email\Inbox\OutboundEmailService;
use Illuminate\Console\Command;

/**
 * Sends the Email inbox messages whose undo window is over, for every company that has one waiting. The Email page
 * sends its own user's messages the moment the window ends; this is for the ones whose page was closed.
 */
class SendDueEmails extends Command
{
    protected $signature = 'email:send-due';

    protected $description = 'Send Email inbox messages whose undo-send window is over';

    public function handle(CompanyContext $context, OutboundEmailService $outbound): int
    {
        $companyIds = EmailMessage::withoutGlobalScopes()
            ->whereIn('status', [EmailMessage::STATUS_PENDING, EmailMessage::STATUS_SCHEDULED, EmailMessage::STATUS_SENDING])
            ->distinct()
            ->pluck('company_id');

        $total = 0;

        foreach (Company::query()->whereIn('id', $companyIds)->get() as $company) {
            $context->set($company);

            try {
                $total += $outbound->deliverDue();
            } finally {
                $context->clear();
            }
        }

        $this->info("Sent {$total} email(s).");

        return self::SUCCESS;
    }
}
