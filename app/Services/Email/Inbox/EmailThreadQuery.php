<?php

namespace App\Services\Email\Inbox;

use App\Models\EmailLabel;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The conversations the Email page lists (owner request 2026-11-02): one view at a time (Inbox, Starred, Snoozed, Sent,
 * Drafts, Spam, Trash, All mail or a label), narrowed by the quick filters and the search words. Sent, Drafts, Starred and All
 * mail are views over the same rows, not folders. A search looks through all the mail (not Spam or Trash, unless that is
 * the view) so an answer sent last month can be found from the Inbox.
 */
class EmailThreadQuery
{
    public const VIEW_INBOX = 'inbox';

    public const VIEW_STARRED = 'starred';

    public const VIEW_SNOOZED = 'snoozed';

    public const VIEW_SCHEDULED = 'scheduled';

    public const VIEW_SENT = 'sent';

    public const VIEW_DRAFTS = 'drafts';

    public const VIEW_SPAM = 'spam';

    public const VIEW_TRASH = 'trash';

    public const VIEW_ALL = 'all';

    public const VIEWS = [self::VIEW_INBOX, self::VIEW_STARRED, self::VIEW_SNOOZED, self::VIEW_SCHEDULED, self::VIEW_SENT, self::VIEW_DRAFTS, self::VIEW_SPAM, self::VIEW_TRASH, self::VIEW_ALL];

    public const FILTER_UNREAD = 'unread';

    public const FILTER_NEEDS_REPLY = 'needs_reply';

    public const FILTER_MINE = 'mine';

    public const FILTER_FILES = 'files';

    public const FILTERS = [self::FILTER_UNREAD, self::FILTER_NEEDS_REPLY, self::FILTER_MINE, self::FILTER_FILES];

    /** Statuses of a message that was written and sent (or is on its way). */
    protected const OUTGOING = [EmailMessage::STATUS_PENDING, EmailMessage::STATUS_SENDING, EmailMessage::STATUS_SENT, EmailMessage::STATUS_FAILED];

    /**
     * @param  array<int, string>  $filters
     * @return Builder<EmailThread>
     */
    public function list(EmailMailbox $mailbox, string $view, ?EmailLabel $label, array $filters, string $search, User $user): Builder
    {
        $query = EmailThread::query()
            ->where('company_id', $mailbox->company_id)
            ->where('mailbox_id', $mailbox->getKey());

        $search = trim($search);
        $this->scopeView($query, $search !== '' && ! in_array($view, [self::VIEW_SPAM, self::VIEW_TRASH], true) ? self::VIEW_ALL : $view, $label, $user);

        foreach (array_intersect($filters, self::FILTERS) as $filter) {
            match ($filter) {
                self::FILTER_UNREAD => $query->where('unread_count', '>', 0),
                self::FILTER_NEEDS_REPLY => $query->whereNotNull('last_inbound_at')
                    ->where('status', '!=', EmailThread::STATUS_CLOSED)
                    ->where(fn (Builder $inner) => $inner->whereNull('last_outbound_at')->orWhereColumn('last_outbound_at', '<', 'last_inbound_at')),
                self::FILTER_MINE => $query->where('assigned_to', $user->getKey()),
                self::FILTER_FILES => $query->where('has_attachments', true),
            };
        }

        if ($search !== '') {
            $this->scopeSearch($query, $search);
        }

        // Drafts have no message time yet; they are listed by when they were last edited. Snoozed ones come back soonest-first.
        if ($search === '' && $view === self::VIEW_DRAFTS) {
            return $query->orderByDesc('updated_at')->orderByDesc('id');
        }

        // Scheduled ones go out soonest-first; the time of the next one is read as `next_scheduled_at`.
        if ($search === '' && $view === self::VIEW_SCHEDULED) {
            return $query->withMin(['messages as next_scheduled_at' => fn (Builder $inner) => $inner
                ->where('kind', EmailMessage::KIND_MESSAGE)->where('status', EmailMessage::STATUS_SCHEDULED)], 'send_after')
                ->orderBy('next_scheduled_at')->orderByDesc('id');
        }

        if ($search === '' && $view === self::VIEW_SNOOZED && $label === null) {
            return $query->orderBy('snoozed_until')->orderByDesc('id');
        }

        return $query->orderByDesc('last_message_at')->orderByDesc('id');
    }

    /**
     * The numbers beside the folders and labels: unread conversations in the Inbox and in Spam, the person's own
     * drafts, and the unread ones under each label.
     *
     * @return array{inbox: int, spam: int, drafts: int, labels: array<int, int>}
     */
    public function counts(EmailMailbox $mailbox, User $user): array
    {
        $base = fn (): Builder => EmailThread::query()->where('company_id', $mailbox->company_id)->where('mailbox_id', $mailbox->getKey());

        $labels = EmailLabel::query()
            ->where('company_id', $mailbox->company_id)
            ->get(['id'])
            ->mapWithKeys(fn (EmailLabel $label): array => [$label->getKey() => $base()
                ->whereIn('folder', [EmailThread::FOLDER_INBOX, EmailThread::FOLDER_ARCHIVED])
                ->where('unread_count', '>', 0)
                ->whereHas('labels', fn (Builder $inner) => $inner->whereKey($label->getKey()))
                ->count()])
            ->all();

        return [
            'inbox' => $base()->where('folder', EmailThread::FOLDER_INBOX)->whereNull('snoozed_until')->where('unread_count', '>', 0)->count(),
            'spam' => $base()->where('folder', EmailThread::FOLDER_SPAM)->where('unread_count', '>', 0)->count(),
            'drafts' => $this->scopeDrafts($base(), $user)->count(),
            'labels' => $labels,
        ];
    }

    /** @param  Builder<EmailThread>  $query */
    protected function scopeView(Builder $query, string $view, ?EmailLabel $label, User $user): void
    {
        $live = [EmailThread::FOLDER_INBOX, EmailThread::FOLDER_ARCHIVED];

        match ($view) {
            self::VIEW_STARRED => $query->where('is_starred', true)->whereIn('folder', $live),
            self::VIEW_SNOOZED => $query->where('folder', EmailThread::FOLDER_INBOX)->whereNotNull('snoozed_until'),
            self::VIEW_SCHEDULED => $query->whereIn('folder', $live)->whereHas('messages', fn (Builder $inner) => $inner
                ->where('kind', EmailMessage::KIND_MESSAGE)->where('status', EmailMessage::STATUS_SCHEDULED)),
            self::VIEW_SENT => $query->whereIn('folder', $live)->whereHas('messages', fn (Builder $inner) => $inner
                ->where('direction', EmailMessage::OUT)->where('kind', EmailMessage::KIND_MESSAGE)->whereIn('status', self::OUTGOING)),
            self::VIEW_DRAFTS => $this->scopeDrafts($query, $user),
            self::VIEW_SPAM => $query->where('folder', EmailThread::FOLDER_SPAM),
            self::VIEW_TRASH => $query->where('folder', EmailThread::FOLDER_TRASH),
            self::VIEW_ALL => $query->whereIn('folder', $live)->where('message_count', '>', 0),
            default => $label !== null
                ? $query->whereIn('folder', $live)->whereHas('labels', fn (Builder $inner) => $inner->whereKey($label->getKey()))
                : $query->where('folder', EmailThread::FOLDER_INBOX)->whereNull('snoozed_until')->where('message_count', '>', 0),
        };
    }

    /**
     * Drafts are private to the person who is writing them.
     *
     * @param  Builder<EmailThread>  $query
     * @return Builder<EmailThread>
     */
    protected function scopeDrafts(Builder $query, User $user): Builder
    {
        return $query->where('folder', '!=', EmailThread::FOLDER_TRASH)->whereHas('messages', fn (Builder $inner) => $inner
            ->where('kind', EmailMessage::KIND_DRAFT)->where('user_id', $user->getKey()));
    }

    /** @param  Builder<EmailThread>  $query */
    protected function scopeSearch(Builder $query, string $search): void
    {
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($search, 0, 120)).'%';
        $matches = fn (string $column): string => "{$column} LIKE ? ESCAPE '!'";

        $query->where(function (Builder $outer) use ($like, $matches): void {
            $outer->whereRaw('('.implode(' OR ', array_map($matches, ['email_threads.subject', 'email_threads.snippet', 'email_threads.contact_email', 'email_threads.contact_name'])).')', array_fill(0, 4, $like))
                ->orWhereHas('messages', fn (Builder $inner) => $inner->where('kind', '!=', EmailMessage::KIND_DRAFT)->whereRaw('('.implode(' OR ', array_map($matches, ['from_address', 'from_name', 'subject', 'body_text', 'to_addresses', 'cc_addresses'])).')', array_fill(0, 6, $like)))
                ->orWhereHas('customer', fn (Builder $inner) => $inner->whereRaw('('.$matches('name').' OR '.$matches('phone').')', [$like, $like]))
                ->orWhereHas('order', fn (Builder $inner) => $inner->whereRaw($matches('order_number'), [$like]));
        });
    }
}
