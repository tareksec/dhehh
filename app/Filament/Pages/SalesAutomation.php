<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Filament\Widgets\CrmSalesMetrics;
use App\Models\CrmAiRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesAutomation extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = Crm::class;

    // Every other CRM cluster item declares an explicit navigationSort
    // (Leads = 0, the cluster's intended landing page); without one here
    // this page sorted before Leads and silently became the cluster root
    // instead, per Filament's default ordering.
    protected static ?int $navigationSort = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $title = 'Sales Automation';

    protected string $view = 'filament.pages.sales-automation';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('crm.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getHeaderWidgets(): array
    {
        return [CrmSalesMetrics::class];
    }

    public function table(Table $table): Table
    {
        return $table->query(CrmAiRun::query()->where('is_test', false)->with('conversation'))->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->dateTime()->sortable(),
            TextColumn::make('conversation.contact_name')->label('Customer')->searchable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('reason')->wrap()->limit(120),
            TextColumn::make('model')->toggleable(),
            TextColumn::make('input_tokens')->label('Input tokens'),
            TextColumn::make('output_tokens')->label('Output tokens'),
            TextColumn::make('estimated_cost_usd')->label('Estimated USD')->placeholder('Rates not configured'),
            TextColumn::make('duration_ms')->label('Duration (ms)'),
        ])->filters([
            SelectFilter::make('status')->options(array_combine(['sent', 'suggested', 'skipped', 'handed_off', 'budget_blocked', 'failed', 'running', 'dismissed'], ['Sent', 'Suggested', 'Skipped', 'Handed off', 'Budget blocked', 'Failed', 'Running', 'Dismissed'])),
        ])->recordActions([
            Action::make('review')->label('Review suggestion')->visible(fn (CrmAiRun $record) => filled($record->suggested_reply))
                ->schema([Textarea::make('reply')->disabled()->rows(7), Textarea::make('evidence')->disabled()->rows(8)])
                ->fillForm(fn (CrmAiRun $record) => ['reply' => $record->suggested_reply, 'evidence' => json_encode($record->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)])
                ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            Action::make('inbox')->label('Open inbox')->url(fn (CrmAiRun $record) => Inbox::getUrl(['conversation' => $record->conversation_id, 'status' => 'all'])),
            Action::make('dismiss')->visible(fn (CrmAiRun $record) => $record->status === 'suggested' && auth()->user()?->hasPermission('crm.manage'))
                ->action(function (CrmAiRun $record): void {
                    abort_unless(auth()->user()?->hasPermission('crm.manage'), 403);
                    $record->update(['status' => 'dismissed']);
                }),
        ]);
    }
}
