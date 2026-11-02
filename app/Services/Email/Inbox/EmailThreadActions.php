<?php

namespace App\Services\Email\Inbox;

use App\Models\EmailAttachment;
use App\Models\EmailLabel;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\CompanyContext;
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

        return EmailThread::query()
            ->whereKey($threads->modelKeys())
            ->where('company_id', $this->companyId())
            ->update(['folder' => $folder]);
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

    protected function companyId(): int
    {
        $company = $this->context->company();

        if ($company === null) {
            throw new AuthorizationException('No company is selected.');
        }

        return (int) $company->getKey();
    }
}
