<?php

namespace App\Services\Email\Inbox;

use App\Filament\Pages\EmailInbox;
use App\Models\EmailMailbox;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\BusinessNotificationService;
use App\Services\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * The Email inbox's work that happens by the clock rather than by a click (Phase 2, docs/email-inbox-plan.md): bringing
 * snoozed conversations back and emptying the Trash after the number of days each mailbox sets. Both work on the current
 * company only; the commands loop over companies.
 */
class EmailInboxMaintenance
{
    public function __construct(
        protected CompanyContext $context,
        protected EmailThreadActions $actions,
    ) {}

    /**
     * Brings every snoozed conversation whose time has come back to the Inbox, unread, and tells the person who snoozed
     * it (else whoever it is assigned to) on the bell. Returns how many came back. One process wins each conversation.
     */
    public function wakeSnoozed(): int
    {
        $companyId = $this->companyId();
        $woken = 0;

        EmailThread::query()
            ->where('company_id', $companyId)
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->chunkById(100, function ($chunk) use (&$woken): void {
                foreach ($chunk as $thread) {
                    $claimed = EmailThread::query()
                        ->whereKey($thread->getKey())
                        ->whereNotNull('snoozed_until')
                        ->where('snoozed_until', '<=', now())
                        ->update(['snoozed_until' => null, 'snoozed_by' => null]);

                    if ($claimed === 0) {
                        continue;
                    }

                    $woken++;
                    $this->actions->markUnread([$thread->getKey()]);
                    $this->tellAboutIt($thread);
                }
            });

        return $woken;
    }

    /** Deletes for good what has sat in Trash longer than each mailbox's "empty Trash after" days (0 = never). Returns how many conversations went. */
    public function purgeTrash(): int
    {
        $total = 0;

        foreach (EmailMailbox::query()->where('company_id', $this->companyId())->get() as $mailbox) {
            $days = max(0, min(365, (int) $mailbox->setting('trash_days')));

            if ($days > 0) {
                $total += $this->actions->purgeTrashedBefore($mailbox, now()->subDays($days));
            }
        }

        return $total;
    }

    /** A bell message that must never stop the others from waking. */
    protected function tellAboutIt(EmailThread $thread): void
    {
        try {
            $company = $this->context->company();
            $user = collect([$thread->snoozed_by, $thread->assigned_to])
                ->filter()
                ->map(fn ($id) => User::query()->find($id))
                ->first(fn (?User $user): bool => $user !== null && $this->actions->isStaff($user));
            $body = filled($thread->subject) ? (string) $thread->subject : __('(no subject)');
            $url = EmailInbox::getUrl(['mailbox' => $thread->mailbox_id, 'thread' => $thread->getKey()], panel: 'admin');
            $notifications = app(BusinessNotificationService::class);

            if ($user !== null) {
                $notifications->notifyUser($user, 'email.snooze_due', __('Snoozed email is back'), $body, ['thread_id' => (string) $thread->getKey()], $url, __('Open email'));
            } else {
                $notifications->notifyCompany($company, 'email.snooze_due', 'crm.manage', __('Snoozed email is back'), $body, ['thread_id' => (string) $thread->getKey()], $url, __('Open email'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function companyId(): int
    {
        $company = $this->context->company();

        if ($company === null) {
            throw new AuthorizationException('No company is selected.');
        }

        return (int) $company->getKey();
    }
}
