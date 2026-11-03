<?php

namespace App\Services\Email\Inbox;

use App\Models\EmailAttachment;
use App\Models\EmailLabel;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Services\CompanyContext;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * What staff do to conversations in the Email page (owner request 2026-11-02): move them between Inbox, archive,
 * Spam and Trash, star them, mark them read or unread, put labels on them, delete them for good. Every method takes
 * the ids of the conversations the person picked and works only on those of the current company, so a forged id
 * from another company changes nothing. Each returns how many conversations it changed.
 */
class EmailThreadActions
{
    public function __construct(
        protected CompanyContext $context,
        protected EmailThreadStats $stats,
        protected EmailFiles $files,
    ) {}

    /** Moves conversations to the Inbox, the archive, Spam or Trash. A conversation that is only a draft is thrown away instead of put in Trash. */
    public function move(array $ids, string $folder): int
    {
        if (! in_array($folder, EmailThread::FOLDERS, true)) {
            return 0;
        }

        $threads = $this->threads($ids);

        if ($folder === EmailThread::FOLDER_TRASH) {
            $drafts = $threads->filter(fn (EmailThread $thread): bool => $thread->message_count === 0);

            if ($drafts->isNotEmpty()) {
                $this->destroy($drafts);
                $threads = $threads->diff($drafts);
            }
        }

        // Leaving the Inbox ends a snooze; Trash starts the clock of the "empty Trash after N days" setting.
        $changes = ['folder' => $folder, 'snoozed_until' => null, 'snoozed_by' => null];

        if ($folder !== EmailThread::FOLDER_TRASH) {
            $changes['trashed_at'] = null;
        }

        $changed = EmailThread::query()
            ->whereKey($threads->modelKeys())
            ->where('company_id', $this->companyId())
            ->update($changes);

        if ($folder === EmailThread::FOLDER_TRASH) {
            EmailThread::query()
                ->whereKey($threads->modelKeys())
                ->where('company_id', $this->companyId())
                ->whereNull('trashed_at')
                ->update(['trashed_at' => now()]);
        }

        return $changed;
    }

    /**
     * Hides conversations of the Inbox until the time, then they come back unread (email:wake-snoozed). Only a
     * conversation in the Inbox can be snoozed, and the time must still be ahead: anything else is left alone.
     */
    public function snooze(array $ids, CarbonInterface $until, User $by): int
    {
        if ($until->lte(now())) {
            return 0;
        }

        $threads = $this->threads($ids)->filter(fn (EmailThread $thread): bool => $thread->folder === EmailThread::FOLDER_INBOX);

        return EmailThread::query()
            ->whereKey($threads->modelKeys())
            ->where('company_id', $this->companyId())
            ->update(['snoozed_until' => $until->copy()->timezone(config('app.timezone')), 'snoozed_by' => $by->getKey()]);
    }

    /** Brings snoozed conversations back to the Inbox now. */
    public function unsnooze(array $ids): int
    {
        return EmailThread::query()
            ->whereKey($this->threads($ids)->modelKeys())
            ->where('company_id', $this->companyId())
            ->whereNotNull('snoozed_until')
            ->update(['snoozed_until' => null, 'snoozed_by' => null]);
    }

    public function star(array $ids, bool $on): int
    {
        return EmailThread::query()
            ->whereKey($this->threads($ids)->modelKeys())
            ->where('company_id', $this->companyId())
            ->update(['is_starred' => $on]);
    }

    /** Marks every received message of the conversations as read. */
    public function markRead(array $ids): int
    {
        $threads = $this->threads($ids);

        EmailMessage::query()
            ->whereIn('thread_id', $threads->modelKeys())
            ->where('direction', EmailMessage::IN)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $threads->each(fn (EmailThread $thread) => $this->stats->refresh($thread));

        return $threads->count();
    }

    /** Marks the conversation unread by its newest received message, as Gmail does. */
    public function markUnread(array $ids): int
    {
        $threads = $this->threads($ids);
        $changed = 0;

        foreach ($threads as $thread) {
            $latest = EmailMessage::query()
                ->where('thread_id', $thread->getKey())
                ->where('direction', EmailMessage::IN)
                ->where('kind', EmailMessage::KIND_MESSAGE)
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->first();

            if ($latest === null) {
                continue;
            }

            $latest->forceFill(['is_read' => false])->save();
            $this->stats->refresh($thread);
            $changed++;
        }

        return $changed;
    }

    /** Puts the label on the conversations, or takes it off. The label must be one of this company's. */
    public function label(array $ids, EmailLabel $label, bool $on): int
    {
        if ((int) $label->company_id !== $this->companyId()) {
            throw new AuthorizationException('That label belongs to another company.');
        }

        $threads = $this->threads($ids);

        foreach ($threads as $thread) {
            $on ? $thread->labels()->syncWithoutDetaching([$label->getKey()]) : $thread->labels()->detach($label->getKey());
        }

        return $threads->count();
    }

    /** Gives the conversation to one staff member of this company, or to nobody (null). */
    public function assign(EmailThread $thread, ?User $user): EmailThread
    {
        $this->guard($thread);

        if ($user !== null && ! $this->isStaff($user)) {
            throw new AuthorizationException('That person cannot be given email of this company.');
        }

        $thread->forceFill(['assigned_to' => $user?->getKey()])->save();

        return $thread;
    }

    /** Open, Pending (waiting for the customer or for us) or Closed (done). */
    public function setStatus(EmailThread $thread, string $status): EmailThread
    {
        $this->guard($thread);

        if (! in_array($status, EmailThread::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown status.');
        }

        $thread->forceFill(['status' => $status])->save();

        return $thread;
    }

    /** Ties the conversation to one of this company's orders, or to none (null). */
    public function linkOrder(EmailThread $thread, ?Order $order): EmailThread
    {
        $this->guard($thread);

        if ($order !== null && (int) $order->company_id !== $this->companyId()) {
            throw new AuthorizationException('That order belongs to another company.');
        }

        $thread->forceFill(['order_id' => $order?->getKey()])->save();

        return $thread;
    }

    /**
     * Makes a sales lead of the person on the other end of the conversation (name, phone, email, source "email") and
     * ties the conversation to it. A lead always has a phone number (the CRM works from it), so the person gives it.
     * A conversation that already has a lead keeps it.
     */
    public function createLead(EmailThread $thread, User $by, string $phone, ?string $name = null): Lead
    {
        $this->guard($thread);

        if ($thread->lead_id !== null && ($lead = Lead::query()->find($thread->lead_id)) !== null) {
            return $lead;
        }

        $lead = Lead::query()->create([
            'company_id' => $this->companyId(),
            'name' => mb_substr(trim((string) $name) !== '' ? trim((string) $name) : ($thread->contact_name ?: ($thread->contact_email ?: __('Email contact'))), 0, 190),
            'phone' => $phone,
            'email' => $thread->contact_email,
            'source' => 'email',
            'status' => 'new',
            'interest' => mb_substr((string) $thread->subject, 0, 190) ?: null,
            'created_by' => $by->getKey(),
        ]);

        $thread->forceFill(['lead_id' => $lead->getKey()])->save();

        return $lead;
    }

    /** Whether the person is an active member of the current company who may use CRM. */
    public function isStaff(User $user): bool
    {
        return $user->is_active !== false
            && $user->hasPermission('crm.view')
            && $user->canAccessCompany($this->companyId());
    }

    /** Deletes conversations that are in Trash for good, with their files. Anything not in Trash is left alone. */
    public function deleteForever(array $ids): int
    {
        $threads = $this->threads($ids)->filter(fn (EmailThread $thread): bool => $thread->folder === EmailThread::FOLDER_TRASH);

        return $this->destroy($threads);
    }

    /** Empties the Trash of one mailbox. */
    public function emptyTrash(EmailMailbox $mailbox): int
    {
        if ((int) $mailbox->company_id !== $this->companyId()) {
            throw new AuthorizationException('That mailbox belongs to another company.');
        }

        $total = 0;

        EmailThread::query()
            ->where('company_id', $this->companyId())
            ->where('mailbox_id', $mailbox->getKey())
            ->where('folder', EmailThread::FOLDER_TRASH)
            ->chunkById(100, function (Collection $chunk) use (&$total): void {
                $total += $this->destroy($chunk);
            });

        return $total;
    }

    /**
     * Deletes for good the conversations of a mailbox that have been in Trash since before the time ("empty Trash after
     * N days"). A conversation with no recorded Trash time counts from when it was last changed.
     */
    public function purgeTrashedBefore(EmailMailbox $mailbox, CarbonInterface $before): int
    {
        if ((int) $mailbox->company_id !== $this->companyId()) {
            throw new AuthorizationException('That mailbox belongs to another company.');
        }

        $total = 0;

        EmailThread::query()
            ->where('company_id', $this->companyId())
            ->where('mailbox_id', $mailbox->getKey())
            ->where('folder', EmailThread::FOLDER_TRASH)
            ->where(fn ($query) => $query->where('trashed_at', '<', $before)
                ->orWhere(fn ($inner) => $inner->whereNull('trashed_at')->where('updated_at', '<', $before)))
            ->chunkById(100, function (Collection $chunk) use (&$total): void {
                $total += $this->destroy($chunk);
            });

        return $total;
    }

    /** @return Collection<int, EmailThread> */
    protected function threads(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return new Collection;
        }

        return EmailThread::query()->where('company_id', $this->companyId())->whereKey($ids)->get();
    }

    /** @param  Collection<int, EmailThread>  $threads */
    protected function destroy(Collection $threads): int
    {
        if ($threads->isEmpty()) {
            return 0;
        }

        $company = $this->context->company();
        $files = EmailAttachment::query()
            ->whereIn('message_id', EmailMessage::query()->whereIn('thread_id', $threads->modelKeys())->select('id'))
            ->get();

        $this->files->deleteAll($files, $company);

        return EmailThread::query()->whereKey($threads->modelKeys())->where('company_id', $this->companyId())->delete();
    }

    protected function guard(EmailThread $thread): void
    {
        if ((int) $thread->company_id !== $this->companyId()) {
            throw new AuthorizationException('That conversation belongs to another company.');
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
