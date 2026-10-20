<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Crm;
use App\Models\CrmSalesFollowUp;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesFollowUps extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = Crm::class;

    // See SalesAutomation::$navigationSort — same gap, same fix; placed
    // right after it since the two pages are part of the same feature.
    protected static ?int $navigationSort = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $title = 'Sales Follow-ups';

    protected string $view = 'filament.pages.sales-automation';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('crm.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table->query(CrmSalesFollowUp::query()->with('conversation'))->defaultSort('due_at', 'desc')->columns([
            TextColumn::make('conversation.contact_name')->label('Customer')->searchable(),
            TextColumn::make('event_key')->label('Trigger'),
            TextColumn::make('step')->label('Reminders done'),
            TextColumn::make('due_at')->dateTime()->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('reason')->wrap(),
            TextColumn::make('sent_at')->dateTime(),
        ])->filters([
            SelectFilter::make('status')->options(['pending' => 'Pending', 'sent' => 'Sent', 'cancelled' => 'Cancelled', 'review' => 'Review', 'unknown' => 'Unknown delivery', 'sending' => 'Sending', 'completed' => 'Completed']),
        ])->recordActions([
            Action::make('inbox')->label('Open inbox')->url(fn (CrmSalesFollowUp $record) => Inbox::getUrl(['conversation' => $record->conversation_id, 'status' => 'all'])),
            Action::make('cancel')->requiresConfirmation()->visible(fn (CrmSalesFollowUp $record) => $record->status === 'pending' && auth()->user()?->hasPermission('crm.manage'))
                ->action(function (CrmSalesFollowUp $record): void {
                    abort_unless(auth()->user()?->hasPermission('crm.manage'), 403);
                    CrmSalesFollowUp::query()->whereKey($record->getKey())->where('status', 'pending')->update(['status' => 'cancelled', 'reason' => 'Cancelled by staff.']);
                }),
        ]);
    }
}
