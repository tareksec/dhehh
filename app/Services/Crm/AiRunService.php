<?php

namespace App\Services\Crm;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CrmAiRun;
use Illuminate\Support\Facades\DB;

class AiRunService
{
    public function start(Conversation $conversation, ?ConversationMessage $source, array $settings): CrmAiRun
    {
        return DB::transaction(function () use ($conversation, $source, $settings): CrmAiRun {
            Company::query()->whereKey($conversation->company_id)->lockForUpdate()->firstOrFail();
            $today = now($conversation->company->timezone ?: 'UTC')->startOfDay()->utc();
            $allRuns = CrmAiRun::query()->where('company_id', $conversation->company_id)->where('created_at', '>=', $today)->whereNotIn('status', ['budget_blocked', 'skipped']);
            // Agent Desk test chats cost money but do not use up the daily run limit.
            $runs = (clone $allRuns)->where('is_test', false);
            $rate = max((float) $settings['input_cost_per_million'], (float) $settings['output_cost_per_million']);
            $reservation = $rate > 0 ? (int) $settings['max_run_tokens'] * $rate / 1000000 : null;
            $reason = null;
            if ((clone $runs)->count() >= (int) $settings['daily_run_limit']) {
                $reason = 'Daily AI run limit reached.';
            } elseif ((float) $settings['daily_budget_usd'] > 0 && ($reservation === null
                || (float) (clone $allRuns)->sum('estimated_cost_usd') + $reservation > (float) $settings['daily_budget_usd'])) {
                $reason = 'Daily budget unavailable: check remaining budget and configured token rates.';
            }

            return CrmAiRun::query()->create([
                'company_id' => $conversation->company_id, 'conversation_id' => $conversation->getKey(),
                'source_message_id' => $source?->getKey(), 'model' => $settings['model'],
                'status' => $reason ? 'budget_blocked' : 'running', 'reason' => $reason,
                'estimated_cost_usd' => $reason ? 0 : $reservation,
            ]);
        });
    }
}
