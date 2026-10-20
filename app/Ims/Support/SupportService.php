<?php

namespace App\Ims\Support;

use App\Ims\Notifications\ImsNotifier;
use App\Models\InvestorPortalAccount;
use App\Models\InvestorRequest;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Support threads (IMS-V2/PRD.md FR-64): an investor asks, staff with
 * `ims.support.reply` answer, "unread" on both sides, and a closed thread
 * opens again when the investor writes. At most 10 messages per ten
 * minutes from one investor.
 */
class SupportService
{
    public function __construct(private ImsNotifier $notifier, private AuditLogService $audit) {}

    public function open(InvestorPortalAccount $account, string $subject, string $body, ?int $projectId = null): SupportThread
    {
        $this->limit($account);
        $body = $this->clean($body);

        return DB::transaction(function () use ($account, $subject, $body, $projectId): SupportThread {
            $thread = SupportThread::query()->create([
                'company_id' => $account->company_id,
                'investor_portal_account_id' => $account->getKey(),
                'investor_id' => $account->investor_id,
                'project_id' => $projectId,
                'subject' => mb_substr(trim($subject) ?: __('Question'), 0, 255),
                'last_message_at' => now(),
            ]);

            SupportMessage::query()->create(['company_id' => $account->company_id, 'thread_id' => $thread->getKey(), 'body' => $body, 'from_staff' => false]);

            return $thread;
        });
    }

    public function investorReply(SupportThread $thread, InvestorPortalAccount $account, string $body): SupportMessage
    {
        if ((int) $thread->investor_portal_account_id !== (int) $account->getKey()) {
            throw ValidationException::withMessages(['body' => __('This conversation is not yours.')]);
        }

        $this->limit($account);
        $body = $this->clean($body);

        return DB::transaction(function () use ($thread, $body): SupportMessage {
            $message = SupportMessage::query()->create(['company_id' => $thread->company_id, 'thread_id' => $thread->getKey(), 'body' => $body, 'from_staff' => false]);
            $thread->forceFill(['status' => SupportThread::STATUS_OPEN, 'staff_unread' => true, 'last_message_at' => now()])->save();

            return $message;
        });
    }

    public function staffReply(SupportThread $thread, User $by, string $body, bool $close = false): SupportMessage
    {
        if (! $by->hasPermission('ims.support.reply')) {
            throw ValidationException::withMessages(['body' => __('You do not have permission to answer support questions.')]);
        }

        $body = $this->clean($body);

        $message = DB::transaction(function () use ($thread, $by, $body, $close): SupportMessage {
            $message = SupportMessage::query()->create(['company_id' => $thread->company_id, 'thread_id' => $thread->getKey(), 'body' => $body, 'from_staff' => true, 'user_id' => $by->getKey()]);
            $thread->forceFill([
                'investor_unread' => true,
                'staff_unread' => false,
                'last_message_at' => now(),
                'status' => $close ? SupportThread::STATUS_CLOSED : SupportThread::STATUS_OPEN,
            ])->save();
            $this->audit->record('ims.support_replied', $thread, null, ['by' => $by->getKey(), 'closed' => $close]);

            return $message;
        });

        if ($thread->investor) {
            $this->notifier->send('support_reply', $thread->investor, ['title' => (string) $thread->subject]);
        }

        return $message;
    }

    public function close(SupportThread $thread, User $by): void
    {
        if (! $by->hasPermission('ims.support.reply')) {
            throw ValidationException::withMessages(['body' => __('You do not have permission to answer support questions.')]);
        }

        $thread->forceFill(['status' => SupportThread::STATUS_CLOSED, 'staff_unread' => false])->save();
    }

    public function markReadByInvestor(SupportThread $thread): void
    {
        if ($thread->investor_unread) {
            $thread->forceFill(['investor_unread' => false])->save();
        }
    }

    public function markReadByStaff(SupportThread $thread): void
    {
        if ($thread->staff_unread) {
            $thread->forceFill(['staff_unread' => false])->save();
        }
    }

    /**
     * The one-question support requests of the old portal become threads
     * (IMS-V2/TRD.md §11): copied once, the requests themselves are kept.
     *
     * @return int threads made
     */
    public function importOldRequests(): int
    {
        $made = 0;

        InvestorRequest::query()->where('type', InvestorRequest::TYPE_SUPPORT)->whereNotNull('portal_account_id')
            ->whereNotIn('id', SupportThread::query()->whereNotNull('legacy_request_id')->select('legacy_request_id'))
            ->orderBy('id')->get()
            ->each(function (InvestorRequest $request) use (&$made): void {
                DB::transaction(function () use ($request): void {
                    $thread = SupportThread::query()->create([
                        'company_id' => $request->company_id,
                        'investor_portal_account_id' => $request->portal_account_id,
                        'investor_id' => $request->investor_id,
                        'project_id' => $request->project_id,
                        'subject' => mb_strimwidth((string) ($request->message ?: __('Question')), 0, 80, '…'),
                        'status' => $request->isPending() ? SupportThread::STATUS_OPEN : SupportThread::STATUS_CLOSED,
                        'staff_unread' => $request->isPending(),
                        'investor_unread' => false,
                        'last_message_at' => $request->decided_at ?? $request->created_at,
                        'legacy_request_id' => $request->getKey(),
                    ]);

                    SupportMessage::query()->create(['company_id' => $request->company_id, 'thread_id' => $thread->getKey(), 'body' => (string) ($request->message ?: '—'), 'from_staff' => false, 'created_at' => $request->created_at]);

                    if (filled($request->admin_note)) {
                        SupportMessage::query()->create(['company_id' => $request->company_id, 'thread_id' => $thread->getKey(), 'body' => (string) $request->admin_note, 'from_staff' => true, 'user_id' => $request->decided_by, 'created_at' => $request->decided_at ?? $request->updated_at]);
                    }
                });

                $made++;
            });

        return $made;
    }

    private function clean(string $body): string
    {
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > 3000) {
            throw ValidationException::withMessages(['body' => __('Write a message of up to 3000 letters.')]);
        }

        return $body;
    }

    private function limit(InvestorPortalAccount $account): void
    {
        $key = 'ims-support:'.$account->getKey();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['body' => __('Slow down a little and try again in a few minutes.')]);
        }

        RateLimiter::hit($key, 600);
    }
}
