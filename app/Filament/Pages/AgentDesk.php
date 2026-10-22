<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Models\AiInstructionVersion;
use App\Models\Company;
use App\Models\Conversation;
use App\Services\CompanyContext;
use App\Services\Crm\AiInstructionService;
use App\Services\Crm\AiReplyService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Where the owner writes the sales agent's guidance, tries it in a test chat,
 * publishes it and rolls it back (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.5).
 */
class AgentDesk extends Page
{
    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 11;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected string $view = 'filament.pages.agent-desk';

    /** @var array{about:string, voice:string, guidelines:string} */
    public array $blocks = ['about' => '', 'voice' => '', 'guidelines' => ''];

    public bool $hasDraft = false;

    public string $testMessage = '';

    public bool $useEditorText = true;

    /** @var list<array<string, mixed>> */
    public array $transcript = [];

    public static function getNavigationLabel(): string
    {
        return __('Agent Desk');
    }

    public function getTitle(): string
    {
        return __('Agent Desk');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('crm.view') ?? false;
    }

    /** Same people who can already change these texts on Integrations. */
    public function canEdit(): bool
    {
        return auth()->user()?->canManageSettings() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        app(AiInstructionService::class)->published($this->company()); // the first time, the current text becomes version one
        $this->loadBlocks();
    }

    protected function company(): Company
    {
        $company = app(CompanyContext::class)->company();
        abort_if($company === null, 409, 'Select a company first.');

        return $company;
    }

    protected function loadBlocks(): void
    {
        $service = app(AiInstructionService::class);
        $this->hasDraft = $service->draft($this->company()) !== null;
        $this->blocks = $service->draftOrLive($this->company());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(__('Publish'))
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('primary')
                ->visible(fn (): bool => $this->canEdit())
                ->modalHeading(__('Publish these instructions?'))
                ->modalDescription(__('The AI uses this text for every new reply from now on. You can go back to an earlier version at any time.'))
                ->schema([
                    TextInput::make('note')->label(__('What changed? (optional)'))->maxLength(250),
                ])
                ->action(function (array $data): void {
                    $this->publish((string) ($data['note'] ?? ''));
                }),
        ];
    }

    public function blockLabels(): array
    {
        return [
            'about' => [
                'label' => __('About the business'),
                'hint' => __('What you sell and who your customers are. It helps the AI understand product names and spellings and what is outside its job. The AI never quotes it as a fact.'),
                'rows' => 5,
                'max' => 4000,
            ],
            'voice' => [
                'label' => __('Voice'),
                'hint' => __('How the AI should sound, for example friendly and simple Bangla.'),
                'rows' => 3,
                'max' => 2000,
            ],
            'guidelines' => [
                'label' => __('Conversation guidelines'),
                'hint' => __('How to talk with customers and when to hand a chat to the team. Prices, stock and delivery charges always come from your catalog, and answers like delivery time or warranty must be saved as FAQ answers.'),
                'rows' => 14,
                'max' => 16000,
            ],
        ];
    }

    public function saveDraft(): void
    {
        $this->authorizeEdit();
        $this->validateBlocks();
        app(AiInstructionService::class)->saveDraft($this->company(), $this->blocks, auth()->user());
        $this->hasDraft = true;

        Notification::make()->title(__('Draft saved'))->success()->send();
    }

    public function discardDraft(): void
    {
        $this->authorizeEdit();
        app(AiInstructionService::class)->discardDraft($this->company());
        $this->loadBlocks();

        Notification::make()->title(__('Draft discarded'))->success()->send();
    }

    public function publish(string $note = ''): void
    {
        $this->authorizeEdit();
        $this->validateBlocks();
        $service = app(AiInstructionService::class);
        $service->saveDraft($this->company(), $this->blocks, auth()->user());
        $service->publishDraft($this->company(), auth()->user(), $note);
        $this->company()->refresh();
        $this->loadBlocks();

        Notification::make()->title(__('Instructions published'))->success()->send();
    }

    /** Put an older version's text into the editor, as an unsaved change. */
    public function loadVersion(int $versionId): void
    {
        $this->authorizeEdit();
        $this->blocks = AiInstructionVersion::query()->findOrFail($versionId)->normalizedBlocks();
    }

    /** Make an older version live again. */
    public function restoreVersion(int $versionId): void
    {
        $this->authorizeEdit();
        $version = AiInstructionVersion::query()->findOrFail($versionId);
        app(AiInstructionService::class)->restore($this->company(), $version, auth()->user());
        $this->company()->refresh();
        $this->loadBlocks();

        Notification::make()->title(__('Version :number is live again', ['number' => $version->getKey()]))->success()->send();
    }

    public function versions()
    {
        return AiInstructionVersion::query()
            ->whereIn('status', [AiInstructionVersion::STATUS_PUBLISHED, AiInstructionVersion::STATUS_SUPERSEDED])
            ->with('publisher')
            ->latest('id')
            ->limit(20)
            ->get();
    }

    public function sendTest(): void
    {
        $this->authorizeEdit();
        $message = trim($this->testMessage);
        if ($message === '') {
            return;
        }

        $key = 'agent-desk-test:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            Notification::make()->title(__('Too many test messages. Try again in a few minutes.'))->warning()->send();

            return;
        }
        RateLimiter::hit($key, 600);

        $history = collect($this->transcript)
            ->filter(fn (array $entry): bool => in_array($entry['kind'], ['customer', 'ai'], true))
            ->map(fn (array $entry): array => ['role' => $entry['kind'], 'text' => (string) $entry['text']])
            ->values()
            ->all();

        $result = app(AiReplyService::class)->dryRun(
            $this->company(),
            $history,
            $message,
            $this->useEditorText ? $this->blocks : null,
        );

        $this->transcript[] = ['kind' => 'customer', 'text' => $message];
        $this->transcript[] = match ($result['outcome']) {
            'reply' => ['kind' => 'ai', 'text' => (string) $result['reply'], 'source' => $result['source'], 'tools' => $result['tools'], 'tokens' => $result['input_tokens'] + $result['output_tokens'], 'cost' => $result['cost_usd']],
            'handover' => ['kind' => 'handover', 'text' => (string) $result['reason'], 'code' => $result['reason_code'], 'source' => $result['source'], 'tools' => $result['tools'], 'tokens' => $result['input_tokens'] + $result['output_tokens'], 'cost' => $result['cost_usd']],
            default => ['kind' => 'note', 'text' => (string) $result['reason']],
        };
        $this->testMessage = '';
    }

    public function clearTest(): void
    {
        $this->transcript = [];
    }

    public function handoverLabel(?string $code): string
    {
        return $code ? __(Conversation::HANDOVER_REASONS[$code] ?? $code) : '';
    }

    public function sourceLabel(?string $source): string
    {
        return match ($source) {
            'faq' => __('Answered from a saved FAQ'),
            'keyword' => __('Handed over by a keyword, no AI call'),
            default => '',
        };
    }

    protected function authorizeEdit(): void
    {
        abort_unless($this->canEdit(), 403);
    }

    protected function validateBlocks(): void
    {
        $this->validate([
            'blocks.about' => ['nullable', 'string', 'max:4000'],
            'blocks.voice' => ['nullable', 'string', 'max:2000'],
            'blocks.guidelines' => ['nullable', 'string', 'max:16000'],
        ]);
    }
}
