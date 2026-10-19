<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Filament\Concerns\HasGuardedNavigationBadge;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\AttentionItem;
use App\Models\Conversation;
use App\Services\Crm\AttentionQueueService;
use App\Services\Crm\ConversationModeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chats the AI handed to the team, with how long each waited for a person
 * (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.3). The push alert says "look now";
 * this page is the record.
 */
class NeedsAttention extends Page implements HasTable
{
    use HasGuardedNavigationBadge;
    use InteractsWithTable;

    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected string $view = 'filament.pages.sales-automation';

    public static function getNavigationLabel(): string
    {
        return __('Needs attention');
    }

    public function getTitle(): string
    {
        return __('Needs attention');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('crm.view') ?? false;
    }

    protected static function navigationBadge(): ?string
    {
        $open = AttentionItem::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return AttentionItem::query()->open()->whereNull('first_response_at')->whereNotNull('due_at')->where('due_at', '<', now())->exists()
            ? 'danger'
            : 'warning';
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
            ->query(AttentionItem::query()->with(['conversation.assignedUser', 'lead', 'firstResponder', 'resolver']))
            ->defaultSort('opened_at', 'desc')
            ->poll('15s')
            ->columns([
                TextColumn::make('opened_at')->label(__('Opened'))->dateTime()->sortable(),
                TextColumn::make('customer')->label(__('Customer'))
                    ->state(fn (AttentionItem $record): string => $record->conversation?->profileDisplayName() ?? $record->lead?->name ?? '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $any): Builder => $any
                        ->whereHas('conversation', fn (Builder $q): Builder => $q->where('contact_name', 'like', "%{$search}%"))
                        ->orWhereHas('lead', fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%")))),
                TextColumn::make('reason')->label(__('Reason'))
                    ->formatStateUsing(fn (?string $state): string => $state ? __(Conversation::HANDOVER_REASONS[$state] ?? $state) : '')
                    ->description(fn (AttentionItem $record): ?string => $record->note),
                TextColumn::make('waiting')->label(__('Waited'))
                    ->state(fn (AttentionItem $record): string => __(':minutes min', ['minutes' => $record->waitMinutes()]))
                    ->badge()
                    ->color(fn (AttentionItem $record): string => match (true) {
                        $record->isOverdue() => 'danger',
                        $record->first_response_at !== null => 'success',
                        default => 'gray',
                    })
                    ->description(fn (AttentionItem $record): ?string => $record->isOverdue() ? __('Late') : null),
                TextColumn::make('first_response_at')->label(__('First reply'))->dateTime()->placeholder('—')
                    ->description(fn (AttentionItem $record): ?string => $record->firstResponder?->name),
                TextColumn::make('conversation.assignedUser.name')->label(__('Handled by'))->placeholder('—'),
                TextColumn::make('outcome')->label(__('Outcome'))->badge()->placeholder(__('Still open'))
                    ->formatStateUsing(fn (?string $state): string => $state ? __(AttentionItem::OUTCOMES[$state] ?? $state) : '')
                    ->color(fn (?string $state): string => match ($state) {
                        'answered' => 'success',
                        'never_answered' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('resolved_at')->label(__('Closed'))->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')->label(__('Show'))
                    ->options(['open' => __('Needs attention'), 'done' => __('Done')])
                    ->default('open')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('resolved_at'),
                        'done' => $query->whereNotNull('resolved_at'),
                        default => $query,
                    }),
                SelectFilter::make('reason')->label(__('Reason'))
                    ->options(collect(Conversation::HANDOVER_REASONS)->map(fn (string $label): string => __($label))->all()),
                SelectFilter::make('outcome')->label(__('Outcome'))
                    ->options(collect(AttentionItem::OUTCOMES)->map(fn (string $label): string => __($label))->all()),
                Filter::make('late')->label(__('Late only'))->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNull('first_response_at')->whereNotNull('due_at')
                        ->where(fn (Builder $q): Builder => $q->whereColumn('due_at', '<', 'resolved_at')
                            ->orWhere(fn (Builder $open): Builder => $open->whereNull('resolved_at')->where('due_at', '<', now())))),
                Filter::make('mine')->label(__('Given to me'))->toggle()
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $any): Builder => $any
                        ->whereHas('conversation', fn (Builder $q): Builder => $q->where('assigned_to', auth()->id()))
                        ->orWhereHas('lead', fn (Builder $q): Builder => $q->where('assigned_to', auth()->id())))),
            ])
            ->recordActions([
                Action::make('inbox')->label(__('Open inbox'))->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->visible(fn (AttentionItem $record): bool => $record->conversation_id !== null)
                    ->url(fn (AttentionItem $record): ?string => $record->conversation_id
                        ? Inbox::getUrl(['conversation' => $record->conversation_id, 'status' => 'all'])
                        : null),
                Action::make('lead')->label(__('Open lead'))->icon(Heroicon::OutlinedUser)
                    ->visible(fn (AttentionItem $record): bool => $record->lead_id !== null && LeadResource::canViewAny())
                    ->url(fn (AttentionItem $record): ?string => $record->lead_id ? LeadResource::getUrl('view', ['record' => $record->lead_id]) : null),
                Action::make('claim')->label(__('Claim'))->icon(Heroicon::OutlinedHandRaised)
                    ->visible(fn (AttentionItem $record): bool => $this->canManage() && $record->resolved_at === null
                        && $record->conversation !== null && (int) $record->conversation->assigned_to !== (int) auth()->id())
                    ->action(fn (AttentionItem $record) => $this->claim($record)),
                Action::make('done')->label(__('Done'))->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (AttentionItem $record): bool => $this->canManage() && $record->resolved_at === null)
                    ->action(fn (AttentionItem $record) => $this->markDone($record)),
            ]);
    }

    public function claim(AttentionItem $item): void
    {
        abort_unless($this->canManage(), 403);
        $conversation = $item->conversation;
        if (! $conversation) {
            return;
        }

        $conversation->forceFill(['assigned_to' => auth()->id()])->saveQuietly();
        if (! $conversation->isHumanMode()) {
            app(ConversationModeService::class)->toHuman($conversation, 'dashboard', auth()->user());
        }
    }

    public function markDone(AttentionItem $item): void
    {
        abort_unless($this->canManage(), 403);
        app(AttentionQueueService::class)->markDone($item, auth()->user());
    }
}
