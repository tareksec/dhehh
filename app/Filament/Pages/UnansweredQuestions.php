<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Models\CompanyFaq;
use App\Models\Conversation;
use App\Models\ConversationModeEvent;
use App\Services\CompanyContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

/**
 * Chats the AI gave to the team because it had no verified answer
 * (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.5), for the last 30 days. A saved FAQ
 * answer is what lets the AI answer the same question next time.
 */
class UnansweredQuestions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 12;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected string $view = 'filament.pages.sales-automation';

    public static function getNavigationLabel(): string
    {
        return __('Unanswered questions');
    }

    public function getTitle(): string
    {
        return __('Unanswered questions');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('crm.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function canManage(): bool
    {
        return auth()->user()?->hasPermission('crm.manage') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ConversationModeEvent::query()
                ->where('source', 'ai')
                ->where('reason', 'out_of_scope')
                ->where('created_at', '>=', now()->subDays(30))
                ->with('conversation'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('No unanswered questions in the last 30 days'))
            ->columns([
                TextColumn::make('created_at')->label(__('Time'))->dateTime()->sortable(),
                TextColumn::make('conversation.contact_name')->label(__('Customer'))
                    ->formatStateUsing(fn ($state, ConversationModeEvent $record): string => $record->conversation?->profileDisplayName() ?? '—'),
                TextColumn::make('question')->label(__('Customer asked'))->wrap()->limit(160)
                    ->state(fn (ConversationModeEvent $record): ?string => $this->questionFor($record)),
                TextColumn::make('note')->label(__('Why the AI handed over'))->wrap()->limit(120),
            ])
            ->recordActions([
                Action::make('addFaq')->label(__('Add FAQ answer'))->icon(Heroicon::OutlinedPlusCircle)
                    ->visible(fn (): bool => $this->canManage())
                    ->modalHeading(__('Add a FAQ answer'))
                    ->modalDescription(__('The answer is sent to the customer exactly as written when their message contains one of the keywords.'))
                    ->fillForm(fn (ConversationModeEvent $record): array => ['question' => mb_substr((string) $this->questionFor($record), 0, 255)])
                    ->schema([
                        TextInput::make('question')->label(__('Question'))->required()->maxLength(255),
                        Textarea::make('answer')->label(__('Answer'))->required()->rows(4)->maxLength(2000),
                        TextInput::make('keywords')->label(__('Keywords (comma separated)'))->maxLength(255)
                            ->helperText(__('If a customer message contains one of these words, this answer is sent instantly.')),
                    ])
                    ->action(function (array $data): void {
                        abort_unless($this->canManage(), 403);
                        $companyId = (int) app(CompanyContext::class)->id();
                        CompanyFaq::query()->create([
                            'company_id' => $companyId,
                            'question' => $data['question'],
                            'answer' => $data['answer'],
                            'keywords' => $data['keywords'] ?? null,
                            'is_active' => true,
                        ]);
                        Cache::forget("crm:faq:{$companyId}");
                        Notification::make()->title(__('FAQ answer saved'))->success()->send();
                    }),
                Action::make('inbox')->label(__('Open inbox'))->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->url(fn (ConversationModeEvent $record): string => Inbox::getUrl(['conversation' => $record->conversation_id, 'status' => 'all'])),
            ]);
    }

    /** The customer's last message at the time the AI handed the chat over. */
    protected function questionFor(ConversationModeEvent $event): ?string
    {
        $conversation = $event->conversation;
        if (! $conversation instanceof Conversation) {
            return null;
        }

        return $conversation->messages()
            ->where('direction', 'incoming')
            ->where('sent_at', '<=', $event->created_at)
            ->latest('sent_at')->latest('id')
            ->value('body');
    }
}
