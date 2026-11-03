<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Filament\Concerns\HasGuardedNavigationBadge;
use App\Filament\Pages\Concerns\ComposesEmail;
use App\Filament\Pages\Concerns\ManagesEmailConversation;
use App\Filament\Pages\Concerns\SchedulesEmail;
use App\Filament\Pages\Concerns\SnoozesEmail;
use App\Models\Company;
use App\Models\EmailLabel;
use App\Models\EmailMailbox;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Email\Inbox\EmailMessageView;
use App\Services\Email\Inbox\EmailThreadActions;
use App\Services\Email\Inbox\EmailThreadQuery;
use App\Services\StorefrontNotificationService;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * CRM → Email (owner request 2026-11-02): the shared mailbox of the company, laid out like Gmail. Folders and labels on
 * the left, the list of conversations in the middle, the open conversation on the right; on a phone one of the three at a
 * time. Reading and organising live here (folders, stars, read / unread, labels, search, quick filters, bulk actions);
 * writing, the customer panel, assigning and notes are added by the next steps. Everything is the current company's.
 */
class EmailInbox extends Page
{
    use ComposesEmail;
    use HasGuardedNavigationBadge;
    use ManagesEmailConversation;
    use SchedulesEmail;
    use SnoozesEmail;

    public const PAGE_SIZE = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Email';

    protected static ?string $slug = 'email';

    protected string $view = 'filament.pages.email-inbox';

    #[Url(as: 'mailbox', history: true)]
    public ?int $mailboxId = null;

    #[Url(as: 'folder', history: true)]
    public string $folder = EmailThreadQuery::VIEW_INBOX;

    #[Url(as: 'label', history: true)]
    public ?int $labelId = null;

    #[Url(as: 'thread', history: true)]
    public ?int $threadId = null;

    #[Url(as: 'q')]
    public string $search = '';

    /** @var array<int, string> */
    #[Url(as: 'filter')]
    public array $filters = [];

    /** @var array<int, int|string> The conversations ticked in the list. */
    public array $selected = [];

    public int $limit = self::PAGE_SIZE;

    /** @var array<int, int> Messages the person opened (or closed) by hand in the open conversation. */
    public array $opened = [];

    public array $closed = [];

    /** @var array<int, int> Messages whose remote pictures the person asked to see. */
    public array $picturesShown = [];

    public bool $showLabelForm = false;

    public string $labelName = '';

    public string $labelColor = 'blue';

    /** The last move or snooze, so a mistake can be taken back: {message: string, moves: array<int, string>, snoozed?: array<int, int>}. */
    public ?array $undo = null;

    /** What the last poll saw; an unchanged value means the page need not be drawn again. */
    public ?string $signature = null;

    protected static function navigationBadge(): ?string
    {
        $count = (int) EmailThread::query()->where('folder', EmailThread::FOLDER_INBOX)->whereNull('snoozed_until')->where('unread_count', '>', 0)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('crm.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! in_array($this->folder, EmailThreadQuery::VIEWS, true)) {
            $this->folder = EmailThreadQuery::VIEW_INBOX;
        }

        $this->filters = array_values(array_intersect($this->filters, EmailThreadQuery::FILTERS));

        if ($this->labelId !== null && $this->label() === null) {
            $this->labelId = null;
        }

        if ($this->threadId !== null) {
            $this->openThread($this->threadId);
        }
    }

    // --- What is on the screen --------------------------------------------------------------------------

    public function canManage(): bool
    {
        return Auth::user()?->hasPermission('crm.manage') ?? false;
    }

    #[Computed]
    public function mailboxes(): Collection
    {
        // The oldest mailbox (the company's main address) opens first.
        return EmailMailbox::query()->orderBy('id')->get();
    }

    #[Computed]
    public function mailbox(): ?EmailMailbox
    {
        return $this->mailboxes->firstWhere('id', $this->mailboxId) ?? $this->mailboxes->first();
    }

    #[Computed]
    public function labels(): Collection
    {
        return EmailLabel::query()->orderBy('name')->get();
    }

    public function label(): ?EmailLabel
    {
        return $this->labelId === null ? null : $this->labels->firstWhere('id', $this->labelId);
    }

    /** @return array{inbox: int, spam: int, drafts: int, labels: array<int, int>} */
    #[Computed]
    public function counts(): array
    {
        return $this->mailbox === null
            ? ['inbox' => 0, 'spam' => 0, 'drafts' => 0, 'labels' => []]
            : app(EmailThreadQuery::class)->counts($this->mailbox, $this->user());
    }

    /** @return Collection<int, EmailThread> One more than a page, so the page knows whether "Load more" is needed. */
    #[Computed]
    public function threads(): Collection
    {
        if ($this->mailbox === null) {
            return new Collection;
        }

        return app(EmailThreadQuery::class)
            ->list($this->mailbox, $this->folder, $this->label(), $this->filters, $this->search, $this->user())
            ->with(['labels:id,name,color', 'assignee:id,name'])
            ->limit($this->limit + 1)
            ->get();
    }

    public function hasMore(): bool
    {
        return $this->threads->count() > $this->limit;
    }

    #[Computed]
    public function thread(): ?EmailThread
    {
        if ($this->threadId === null || $this->mailbox === null) {
            return null;
        }

        return EmailThread::query()
            ->where('mailbox_id', $this->mailbox->getKey())
            ->with(['labels:id,name,color', 'assignee:id,name'])
            ->find($this->threadId);
    }

    /** @return Collection<int, EmailMessage> The open conversation, oldest first; other people's drafts are not shown. */
    #[Computed]
    public function messages(): Collection
    {
        if ($this->thread === null) {
            return new Collection;
        }

        return EmailMessage::query()
            ->where('thread_id', $this->thread->getKey())
            ->where(fn ($query) => $query->where('kind', '!=', EmailMessage::KIND_DRAFT)->orWhere('user_id', $this->user()->getKey()))
            ->with('attachments')
            ->orderByRaw('COALESCE(sent_at, created_at)')
            ->orderBy('id')
            ->get();
    }

    /** "Reply all" is offered when someone besides the sender (and ourselves) is on the message. */
    public function replyAllMakesSense(EmailMessage $message): bool
    {
        $own = mb_strtolower((string) $this->mailbox?->address);
        $others = collect([...(array) $message->to_addresses, ...(array) $message->cc_addresses])
            ->pluck('address')->map(fn ($address): string => mb_strtolower((string) $address))
            ->reject(fn (string $address): bool => $address === $own)->unique();

        return $message->direction === EmailMessage::IN ? $others->isNotEmpty() : $others->count() > 1 || ! empty($message->cc_addresses);
    }

    public function messageView(): EmailMessageView
    {
        return app(EmailMessageView::class);
    }

    public function company(): ?Company
    {
        return app(CompanyContext::class)->company();
    }

    /** Whether the mailbox's own site has an email account to send through. */
    public function sendingIsSetUp(): bool
    {
        $mailbox = $this->mailbox;

        if ($mailbox === null) {
            return false;
        }

        $setting = $mailbox->storefront_setting_id
            ? StorefrontSetting::withoutGlobalScopes()->where('company_id', $mailbox->company_id)->find($mailbox->storefront_setting_id)
            : StorefrontSetting::primaryFor((int) $mailbox->company_id);

        return $setting !== null && app(StorefrontNotificationService::class)->emailDeliveryAvailable($setting);
    }

    /** The one or two letters in a round avatar: the name's first letters, else the address's. */
    public function initials(?string $name, ?string $address): string
    {
        $source = trim((string) $name) !== '' ? (string) $name : (string) $address;
        $words = preg_split('/[\s._@-]+/u', trim($source), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $word): string => mb_substr($word, 0, 1), array_slice($words, 0, 2)))) ?: '?';
    }

    /** One of six colours, the same one every time for the same person. */
    public function avatarClass(?string $seed): string
    {
        return 'zz-mail-a'.(crc32(mb_strtolower((string) $seed)) % 6 + 1);
    }

    /** "3:41 PM" today, "Nov 2" this year, "Nov 2, 2025" before. */
    public function shortTime(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '';
        }

        $at = $at->copy()->timezone($this->company()?->timezone ?: config('app.timezone'));
        $now = now($at->getTimezone());

        return match (true) {
            $at->isSameDay($now) => $at->translatedFormat('g:i A'),
            $at->year === $now->year => $at->translatedFormat('M j'),
            default => $at->translatedFormat('M j, Y'),
        };
    }

    public function longTime(?CarbonInterface $at): string
    {
        return $at === null ? '' : $at->copy()->timezone($this->company()?->timezone ?: config('app.timezone'))->translatedFormat('D, M j, Y, g:i A');
    }

    // --- Moving around ----------------------------------------------------------------------------------

    public function showFolder(string $folder): void
    {
        $this->folder = in_array($folder, EmailThreadQuery::VIEWS, true) ? $folder : EmailThreadQuery::VIEW_INBOX;
        $this->labelId = null;
        $this->resetList();
    }

    public function showLabel(int $labelId): void
    {
        if ($this->labels->firstWhere('id', $labelId) === null) {
            return;
        }

        $this->folder = EmailThreadQuery::VIEW_INBOX;
        $this->labelId = $labelId;
        $this->resetList();
    }

    public function switchMailbox(int $mailboxId): void
    {
        if ($this->mailboxes->firstWhere('id', $mailboxId) === null) {
            return;
        }

        $this->mailboxId = $mailboxId;
        $this->folder = EmailThreadQuery::VIEW_INBOX;
        $this->labelId = null;
        $this->filters = [];
        $this->resetList();
        unset($this->mailbox);
    }

    public function toggleFilter(string $filter): void
    {
        if (! in_array($filter, EmailThreadQuery::FILTERS, true)) {
            return;
        }

        $this->filters = in_array($filter, $this->filters, true)
            ? array_values(array_diff($this->filters, [$filter]))
            : [...$this->filters, $filter];
        $this->limit = self::PAGE_SIZE;
        $this->selected = [];
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr(trim($this->search), 0, 120);
        $this->limit = self::PAGE_SIZE;
        $this->selected = [];
        $this->threadId = null;
    }

    public function loadMore(): void
    {
        $this->limit += self::PAGE_SIZE;
    }

    public function openThread(int $threadId): void
    {
        $thread = $this->mailbox === null ? null : EmailThread::query()->where('mailbox_id', $this->mailbox->getKey())->find($threadId);

        if ($thread === null) {
            $this->threadId = null;

            return;
        }

        $this->threadId = $thread->getKey();
        $this->opened = [];
        $this->closed = [];
        $this->picturesShown = [];

        if ($thread->unread_count > 0) {
            app(EmailThreadActions::class)->markRead([$thread->getKey()]);
        }

        $this->forgetData();
    }

    public function closeThread(): void
    {
        $this->threadId = null;
    }

    /** A message is open when the person opened it, or when it is the newest one and they did not close it. */
    public function isOpen(EmailMessage $message, bool $isLast): bool
    {
        if (in_array($message->getKey(), $this->closed, true)) {
            return false;
        }

        // A scheduled message is always open: its time and its Send now / Cancel buttons are what the person came for.
        return $isLast || $message->status === EmailMessage::STATUS_SCHEDULED || in_array($message->getKey(), $this->opened, true);
    }

    public function toggleMessage(int $messageId, bool $isLast): void
    {
        $open = $this->messages->firstWhere('id', $messageId);

        if ($open === null) {
            return;
        }

        if ($this->isOpen($open, $isLast)) {
            $this->opened = array_values(array_diff($this->opened, [$messageId]));
            $this->closed[] = $messageId;
        } else {
            $this->closed = array_values(array_diff($this->closed, [$messageId]));
            $this->opened[] = $messageId;
        }
    }

    public function showPictures(int $messageId): void
    {
        if ($this->messages->firstWhere('id', $messageId) !== null) {
            $this->picturesShown[] = $messageId;
        }
    }

    public function picturesAreShown(EmailMessage $message): bool
    {
        return in_array($message->getKey(), $this->picturesShown, true) || (bool) $this->mailbox?->setting('load_remote_images');
    }

    // --- Doing things to conversations ------------------------------------------------------------------

    public function toggleStar(int $threadId): void
    {
        $this->authorizeManage();
        $thread = $this->findThread($threadId);

        if ($thread !== null) {
            app(EmailThreadActions::class)->star([$thread->getKey()], ! $thread->is_starred);
            $this->forgetData();
        }
    }

    /** One of: archive, inbox, spam, notspam, trash, restore, delete, read, unread, unsnooze, star, unstar. */
    public function perform(string $action, array $ids): void
    {
        $this->authorizeManage();
        $ids = $this->ownIds($ids);

        if ($ids === []) {
            return;
        }

        $actions = app(EmailThreadActions::class);
        $before = EmailThread::query()->whereKey($ids)->pluck('folder', 'id')->all();
        $count = count($ids);

        $moved = match ($action) {
            'archive' => [$actions->move($ids, EmailThread::FOLDER_ARCHIVED), __(':count archived', ['count' => $count])],
            'inbox', 'restore' => [$actions->move($ids, EmailThread::FOLDER_INBOX), __(':count moved to the Inbox', ['count' => $count])],
            'spam' => [$actions->move($ids, EmailThread::FOLDER_SPAM), __(':count marked as spam', ['count' => $count])],
            'notspam' => [$actions->move($ids, EmailThread::FOLDER_INBOX), __(':count moved to the Inbox', ['count' => $count])],
            'trash' => [$actions->move($ids, EmailThread::FOLDER_TRASH), __(':count moved to Trash', ['count' => $count])],
            default => null,
        };

        if ($moved !== null) {
            $this->undo = $moved[0] > 0 ? ['message' => $moved[1], 'moves' => $before] : null;
        } else {
            $this->undo = null;

            match ($action) {
                'delete' => $actions->deleteForever($ids),
                'read' => $actions->markRead($ids),
                'unread' => $actions->markUnread($ids),
                'unsnooze' => $actions->unsnooze($ids),
                'star' => $actions->star($ids, true),
                'unstar' => $actions->star($ids, false),
                default => null,
            };
        }

        if (in_array($action, ['archive', 'inbox', 'restore', 'spam', 'notspam', 'trash', 'delete', 'unread'], true) && in_array($this->threadId, $ids, true)) {
            $this->threadId = null;
        }

        $this->selected = [];
        $this->forgetData();
    }

    /** The buttons above the list act on the ticked conversations. */
    public function performSelected(string $action): void
    {
        $this->perform($action, $this->selected);
    }

    /** Takes the last move back: every conversation goes to the folder it came from. */
    public function undoMove(): void
    {
        $this->authorizeManage();
        $undone = (array) $this->undo;
        $moves = (array) ($undone['moves'] ?? []);
        $this->undo = null;

        foreach (collect($moves)->groupBy(fn ($folder) => $folder, preserveKeys: true) as $folder => $group) {
            app(EmailThreadActions::class)->move($this->ownIds($group->keys()->all()), (string) $folder);
        }

        if ($snoozed = (array) ($undone['snoozed'] ?? [])) {
            app(EmailThreadActions::class)->unsnooze($this->ownIds($snoozed));
        }

        $this->forgetData();
    }

    public function dismissUndo(): void
    {
        $this->undo = null;
    }

    public function emptyTrash(): void
    {
        $this->authorizeManage();

        if ($this->mailbox === null) {
            return;
        }

        $count = app(EmailThreadActions::class)->emptyTrash($this->mailbox);
        $this->selected = [];
        $this->threadId = null;
        $this->undo = null;
        $this->forgetData();

        Notification::make()->title(__(':count deleted for good', ['count' => $count]))->success()->send();
    }

    /** The box above the list: tick every conversation shown, or clear the ticks when they are all ticked already. */
    public function selectAll(): void
    {
        $shown = $this->threads->take($this->limit)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ticked = array_map('intval', $this->selected);

        $this->selected = $shown === [] || array_diff($shown, $ticked) === [] ? [] : $shown;
    }

    public function applyLabel(int $labelId, array $ids, bool $on): void
    {
        $this->authorizeManage();
        $label = $this->labels->firstWhere('id', $labelId);
        $ids = $this->ownIds($ids);

        if ($label === null || $ids === []) {
            return;
        }

        app(EmailThreadActions::class)->label($ids, $label, $on);
        $this->forgetData();
    }

    public function applyLabelToSelected(int $labelId): void
    {
        $ids = $this->ownIds($this->selected);
        $allHave = $ids !== [] && EmailThread::query()->whereKey($ids)->whereHas('labels', fn ($query) => $query->whereKey($labelId))->count() === count($ids);

        $this->applyLabel($labelId, $ids, ! $allHave);
    }

    public function createLabel(): void
    {
        $this->authorizeManage();
        $this->labelName = trim(preg_replace('/\s+/u', ' ', $this->labelName) ?? '');

        $this->validate([
            'labelName' => ['required', 'string', 'max:60', function (string $attribute, mixed $value, \Closure $fail): void {
                // "Wholesale" and "wholesale" are the same label, whatever the database's collation says.
                if (EmailLabel::query()->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])->exists()) {
                    $fail(__('There is already a label with that name.'));
                }
            }],
            'labelColor' => ['required', Rule::in(EmailLabel::COLORS)],
        ], [], ['labelName' => __('Label name')]);

        EmailLabel::query()->create(['name' => $this->labelName, 'color' => $this->labelColor]);

        $this->labelName = '';
        $this->showLabelForm = false;
        unset($this->labels);
    }

    /** Called by the page every half minute: draws the page again only when something changed. */
    public function checkNew(): void
    {
        $mailbox = $this->mailbox;
        $latest = $mailbox === null ? null : EmailThread::query()->where('mailbox_id', $mailbox->getKey())
            ->selectRaw('COUNT(*) AS total, MAX(updated_at) AS changed, SUM(unread_count) AS unread')->first();
        $signature = $latest === null ? 'none' : implode('|', [$latest->total, $latest->changed, $latest->unread]);

        if ($signature === $this->signature) {
            $this->skipRender();

            return;
        }

        $this->signature = $signature;
        $this->forgetData();
    }

    // --- Helpers ----------------------------------------------------------------------------------------

    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    protected function findThread(int $id): ?EmailThread
    {
        return $this->mailbox === null ? null : EmailThread::query()->where('mailbox_id', $this->mailbox->getKey())->find($id);
    }

    /**
     * Only the ids of conversations of the open mailbox.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    protected function ownIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === [] || $this->mailbox === null) {
            return [];
        }

        return EmailThread::query()->where('mailbox_id', $this->mailbox->getKey())->whereKey($ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    protected function resetList(): void
    {
        $this->search = '';
        $this->limit = self::PAGE_SIZE;
        $this->selected = [];
        $this->threadId = null;
        $this->undo = null;
        $this->forgetData();
    }

    protected function forgetData(): void
    {
        unset($this->threads, $this->counts, $this->thread, $this->messages, $this->composeFiles, $this->quickReplies, $this->staff, $this->contact);
    }
}
