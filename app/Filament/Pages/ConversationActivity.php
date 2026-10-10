<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Models\Conversation;
use App\Models\ConversationModeEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every switch of a chat between the AI and the team, from every source —
 * including each automatic return to the AI (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.2).
 */
class ConversationActivity extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = Crm::class;

    protected static ?int $navigationSort = 9;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected string $view = 'filament.pages.sales-automation';

    public static function getNavigationLabel(): string
    {
        return __('AI / Team Activity');
    }

    public function getTitle(): string
    {
        return __('AI / Team Activity');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('crm.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label(__('Export CSV'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ConversationModeEvent::query()->with(['conversation', 'user']))
            ->defaultSort('created_at', 'desc')
            ->poll('15s')
            ->columns([
                TextColumn::make('created_at')->label(__('Time'))->dateTime()->sortable(),
                TextColumn::make('conversation.contact_name')->label(__('Customer'))
                    ->formatStateUsing(fn ($state, ConversationModeEvent $record): string => $record->conversation?->profileDisplayName() ?? '—')
                    ->searchable(),
                TextColumn::make('change')->label(__('Change'))
                    ->state(fn (ConversationModeEvent $record): string => self::modeLabel($record->from_mode).' → '.self::modeLabel($record->to_mode)),
                TextColumn::make('source')->label(__('Source'))->badge()
                    ->formatStateUsing(fn (string $state): string => __(ConversationModeEvent::SOURCES[$state] ?? $state)),
                TextColumn::make('reason')->label(__('Reason'))
                    ->formatStateUsing(fn (?string $state): string => $state ? __(Conversation::HANDOVER_REASONS[$state] ?? $state) : ''),
                TextColumn::make('revert_at')->label(__('Back to AI at'))->dateTime()->placeholder('—'),
                TextColumn::make('user.name')->label(__('By'))->placeholder('—'),
                TextColumn::make('note')->label(__('Note'))->wrap()->limit(120),
            ])
            ->filters([
                SelectFilter::make('source')->label(__('Source'))
                    ->options(collect(ConversationModeEvent::SOURCES)->map(fn (string $label): string => __($label))->all()),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('inbox')->label(__('Open inbox'))
                    ->url(fn (ConversationModeEvent $record): string => Inbox::getUrl(['conversation' => $record->conversation_id, 'status' => 'all'])),
            ]);
    }

    public static function modeLabel(string $mode): string
    {
        return $mode === Conversation::MODE_HUMAN ? __('Team') : __('AI');
    }

    /** The rows the table currently shows (search and filters applied), as CSV. */
    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $query = $this->getFilteredSortedTableQuery();

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Time'), __('Customer'), __('Before'), __('After'), __('Source'), __('Reason'), __('Back to AI at'), __('By'), __('Note')]);
            $query->chunk(500, function ($events) use ($out): void {
                foreach ($events as $event) {
                    fputcsv($out, [
                        $event->created_at?->toDateTimeString(),
                        $event->conversation?->profileDisplayName(),
                        self::modeLabel($event->from_mode),
                        self::modeLabel($event->to_mode),
                        __(ConversationModeEvent::SOURCES[$event->source] ?? $event->source),
                        $event->reason ? __(Conversation::HANDOVER_REASONS[$event->reason] ?? $event->reason) : '',
                        $event->revert_at?->toDateTimeString(),
                        $event->user?->name,
                        $event->note,
                    ]);
                }
            });
            fclose($out);
        }, 'ai-team-activity-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
