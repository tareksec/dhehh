<?php

namespace App\Listeners;

use App\Events\InvestorGroupProjectCompleted;
use App\Models\Investment;
use App\Services\AuditLogService;
use App\Services\InvestorProjectMessageService;
use App\Services\SmsTemplateService;
use Throwable;

/**
 * The investors' messages when a group project's two months are over
 * (16_INVESTOR_GROUPS_PLAN.md §3.8, owner 2026-10-12): every investor in the
 * project hears "Project N completed", and every investor of the next project
 * hears "Project N+1 started". The wording is the owner's, written on the SMS
 * Templates page; while a message is empty nothing is sent (the completion is
 * still written to the audit log).
 *
 * One message per investor per project and status, numbers on the opt-out list
 * are skipped, and one failed send never stops the others or the cycle. The
 * sending itself is InvestorProjectMessageService.
 */
class SendProjectCompletedMessages
{
    public function __construct(
        private InvestorProjectMessageService $messages,
        private AuditLogService $audit,
    ) {}

    public function handle(InvestorGroupProjectCompleted $event): void
    {
        $project = $event->project;
        $configured = false;
        $sent = 0;
        $skipped = 0;
        $startedSent = 0;

        try {
            $configured = $this->messages->configured($project, SmsTemplateService::PROJECT_CLOSED);
            [$sent, $skipped] = $this->messages->send($project, SmsTemplateService::PROJECT_CLOSED);

            if ($event->nextProject !== null) {
                [$startedSent] = $this->messages->send($event->nextProject, SmsTemplateService::PROJECT_RUNNING);
            }
        } catch (Throwable $exception) {
            // A problem with the SMS set-up never hides that the project finished.
            report($exception);
        }

        $this->audit->record('investor_group.project_completed', $project, null, [
            'group_id' => $event->group->getKey(),
            'investors' => Investment::query()->where('project_id', $project->getKey())->where('status', Investment::STATUS_ACTIVE)->distinct()->count('investor_id'),
            'message_configured' => $configured,
            'sms_sent' => $sent,
            'sms_not_sent' => $skipped,
            'started_sms_sent' => $startedSent,
        ]);
    }
}
