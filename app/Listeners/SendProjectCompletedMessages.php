<?php

namespace App\Listeners;

use App\Events\InvestorGroupProjectCompleted;
use App\Models\Company;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\StorefrontSetting;
use App\Services\AuditLogService;
use App\Services\Crm\ContactSuppressionService;
use App\Services\StorefrontNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The congratulation SMS when a group project's two months are over
 * (16_INVESTOR_GROUPS_PLAN.md §3.8, owner 2026-10-12): every investor in the
 * project hears "Project N completed". The wording is the owner's: the
 * company's "Project completed message" starts empty, and while it is empty
 * nothing is sent (the completion is still written to the audit log).
 *
 * One message per investor per project (a repeated event cannot send twice),
 * numbers on the opt-out list are skipped, and one failed send never stops the
 * others or the cycle.
 */
class SendProjectCompletedMessages
{
    public function __construct(
        private StorefrontNotificationService $notifications,
        private ContactSuppressionService $suppression,
        private AuditLogService $audit,
    ) {}

    public function handle(InvestorGroupProjectCompleted $event): void
    {
        $project = $event->project;
        $company = Company::query()->find($project->company_id);
        $template = trim((string) $company?->investor_project_completed_message);

        $investors = Investment::query()
            ->where('project_id', $project->getKey())
            ->where('status', Investment::STATUS_ACTIVE)
            ->with('investor')
            ->get()
            ->pluck('investor')
            ->filter()
            ->unique('id');

        $sent = 0;
        $skipped = 0;

        try {
            if ($template !== '' && $company !== null) {
                [$sent, $skipped] = $this->send($template, $company, $event, $investors);
            }
        } catch (Throwable $exception) {
            // A problem with the SMS set-up never hides that the project finished.
            report($exception);
        }

        $this->audit->record('investor_group.project_completed', $project, null, [
            'group_id' => $event->group->getKey(),
            'investors' => $investors->count(),
            'message_configured' => $template !== '',
            'sms_sent' => $sent,
            'sms_not_sent' => $skipped,
        ]);
    }

    /**
     * @param  Collection<int, Investor>  $investors
     * @return array{0: int, 1: int} messages sent, investors not messaged
     */
    private function send(string $template, Company $company, InvestorGroupProjectCompleted $event, Collection $investors): array
    {
        $project = $event->project;
        $sent = 0;
        $skipped = 0;
        $setting = StorefrontSetting::primaryFor((int) $company->getKey());
        $ready = $setting !== null && $this->notifications->smsConfigured($setting);

        foreach ($investors as $investor) {
            $phone = trim((string) $investor->phone);

            if (! $ready || $phone === '' || $this->suppression->suppressed((int) $company->getKey(), $phone)
                || ! Cache::add("project-completed-sms:{$project->getKey()}:{$investor->getKey()}", true, now()->addDays(30))) {
                $skipped++;

                continue;
            }

            try {
                $ok = $this->notifications->sendSms($setting, $phone, strtr($template, [
                    ':name' => (string) $investor->name,
                    ':group' => (string) $event->group->name,
                    ':project' => (string) $project->sequence_in_group,
                ]));
            } catch (Throwable $exception) {
                report($exception);
                $ok = false;
            }

            $ok ? $sent++ : $skipped++;
        }

        return [$sent, $skipped];
    }
}
