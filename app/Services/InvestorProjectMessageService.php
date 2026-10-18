<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestorGroup;
use App\Models\ProjectSettlement;
use App\Models\SettlementPayout;
use App\Services\Crm\ContactSuppressionService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The SMS to the investors of a project when the project changes status
 * (started, completed, profit settled), with the wording the owner wrote on
 * the SMS Templates page (owner request 2026-10-17; the "completed" message is
 * the one of 16_INVESTOR_GROUPS_PLAN.md §3.8).
 *
 * While a status has no wording nothing is sent. One message per investor per
 * project and status (a repeated trigger cannot send twice), numbers on the
 * opt-out list are skipped, and one failed send never stops the others.
 */
class InvestorProjectMessageService
{
    public function __construct(
        protected SmsTemplateService $templates,
        protected StorefrontNotificationService $notifications,
        protected ContactSuppressionService $suppression,
    ) {}

    /** Whether the company has written the message for this status. */
    public function configured(InvestmentProject $project, string $key): bool
    {
        return $this->templates->body((int) $project->company_id, $key) !== null;
    }

    /**
     * @return array{0: int, 1: int} messages sent, investors not messaged
     */
    public function send(InvestmentProject $project, string $key, ?ProjectSettlement $settlement = null): array
    {
        $companyId = (int) $project->company_id;

        if (! $this->configured($project, $key)) {
            return [0, 0];
        }

        $investors = Investment::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('project_id', $project->getKey())
            ->where('status', Investment::STATUS_ACTIVE)
            ->with('investor')
            ->get()
            ->groupBy('investor_id');

        $group = $project->group_id ? InvestorGroup::withoutGlobalScopes()->find($project->group_id) : null;
        $companyName = (string) $project->company?->name;
        $setting = $this->templates->gatewayFor($companyId);
        $sent = 0;
        $skipped = 0;

        foreach ($investors as $investorId => $rows) {
            $investor = $rows->first()->investor;
            $phone = trim((string) $investor?->phone);

            if ($setting === null || $phone === '' || $this->suppression->suppressed($companyId, $phone)
                || ! Cache::add($this->onceKey($project, $key, (int) $investorId), true, now()->addDays(30))) {
                $skipped++;

                continue;
            }

            try {
                $message = $this->templates->render($companyId, $key, [
                    'name' => (string) $investor->name,
                    'project' => (string) ($project->sequence_in_group ?: $project->project_code),
                    'group' => (string) $group?->name,
                    'amount' => $this->money($rows->sum('amount')),
                    'company' => $companyName,
                    ...$this->settlementValues($settlement, (int) $investorId),
                ]);

                $ok = filled($message) && $this->notifications->sendSms($setting, $phone, $message);
            } catch (Throwable $exception) {
                report($exception);
                $ok = false;
            }

            $ok ? $sent++ : $skipped++;
        }

        return [$sent, $skipped];
    }

    /** The "completed" message kept its old once-only key, so a repeated close cannot text anyone twice. */
    protected function onceKey(InvestmentProject $project, string $key, int $investorId): string
    {
        return $key === SmsTemplateService::PROJECT_CLOSED
            ? "project-completed-sms:{$project->getKey()}:{$investorId}"
            : "project-status-sms:{$key}:{$project->getKey()}:{$investorId}";
    }

    /** @return array{profit: string, payout: string} */
    protected function settlementValues(?ProjectSettlement $settlement, int $investorId): array
    {
        $payouts = $settlement
            ? SettlementPayout::withoutGlobalScopes()->where('settlement_id', $settlement->getKey())->where('investor_id', $investorId)->get()
            : collect();

        return [
            'profit' => $this->money($payouts->sum('profit_share_amount')),
            'payout' => $this->money($payouts->sum('total_payout')),
        ];
    }

    protected function money(mixed $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }
}
