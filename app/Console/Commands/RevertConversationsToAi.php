<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Conversation;
use App\Services\CompanyContext;
use App\Services\Crm\ConversationModeService;
use Illuminate\Console\Command;

class RevertConversationsToAi extends Command
{
    protected $signature = 'crm:revert-conversations';

    protected $description = 'Return chats whose team time is over to the AI';

    public function handle(CompanyContext $context, ConversationModeService $modes): int
    {
        $companyIds = Conversation::withoutGlobalScopes()
            ->where('automation_mode', Conversation::MODE_HUMAN)
            ->whereNotNull('human_handled_until')
            ->where('human_handled_until', '<=', now())
            ->distinct()
            ->pluck('company_id');

        $total = 0;

        foreach (Company::query()->whereIn('id', $companyIds)->get() as $company) {
            $context->set($company);
            try {
                $total += $modes->revertDue((int) $company->getKey());
            } finally {
                $context->clear();
            }
        }

        $this->info("Returned {$total} chat(s) to the AI.");

        return self::SUCCESS;
    }
}
