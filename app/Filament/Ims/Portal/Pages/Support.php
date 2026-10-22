<?php

namespace App\Filament\Ims\Portal\Pages;

use App\Filament\Concerns\HasGuardedNavigationBadge;
use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Ims\Support\SupportService;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\SupportThread;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/** Questions to the office (IMS-V2/PRD.md FR-64): the list, one conversation, a new question. */
class Support extends Page
{
    use HasGuardedNavigationBadge;
    use InvestorPortalPage;

    protected static ?string $portalRole = 'investor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.ims.portal.support';

    #[Url]
    public ?int $thread = null;

    public string $subject = '';

    public string $body = '';

    public ?int $projectId = null;

    public string $reply = '';

    public static function getNavigationGroup(): ?string
    {
        return __('Money');
    }

    public static function getNavigationLabel(): string
    {
        return __('Support');
    }

    protected static function navigationBadge(): ?string
    {
        $account = filament()->auth()->user();
        $count = $account ? SupportThread::query()->where('investor_portal_account_id', $account->getKey())->where('investor_unread', true)->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public function getTitle(): string
    {
        return __('Support');
    }

    /** @return Collection<int, SupportThread> */
    public function threads(): Collection
    {
        return SupportThread::query()->where('investor_portal_account_id', $this->account()->getKey())->latest('last_message_at')->get();
    }

    public function current(): ?SupportThread
    {
        if ($this->thread === null) {
            return null;
        }

        $thread = SupportThread::query()->where('investor_portal_account_id', $this->account()->getKey())->with('messages')->find($this->thread);

        if ($thread && ! $this->isStaffPreview()) {
            app(SupportService::class)->markReadByInvestor($thread);
        }

        return $thread;
    }

    /** @return array<int, string> */
    public function projectOptions(): array
    {
        return InvestmentProject::query()->whereIn('id', Investment::query()->where('investor_id', $this->account()->investor_id)->select('project_id'))->pluck('name', 'id')->all();
    }

    public function ask(): void
    {
        $this->refuseInStaffPreview();

        try {
            $projectId = $this->projectId !== null && array_key_exists($this->projectId, $this->projectOptions()) ? $this->projectId : null;
            $thread = app(SupportService::class)->open($this->account(), $this->subject, $this->body, $projectId);
        } catch (ValidationException $exception) {
            Notification::make()->title((string) collect($exception->errors())->flatten()->first())->danger()->send();

            return;
        }

        $this->reset(['subject', 'body', 'projectId']);
        $this->thread = $thread->getKey();
        Notification::make()->title(__('Sent. The office will answer here.'))->success()->send();
    }

    public function sendReply(): void
    {
        $this->refuseInStaffPreview();
        $thread = $this->current();
        abort_if($thread === null, 404);

        try {
            app(SupportService::class)->investorReply($thread, $this->account(), $this->reply);
        } catch (ValidationException $exception) {
            Notification::make()->title((string) collect($exception->errors())->flatten()->first())->danger()->send();

            return;
        }

        $this->reply = '';
    }
}
