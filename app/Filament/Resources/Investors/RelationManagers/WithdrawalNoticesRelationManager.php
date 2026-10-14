<?php

namespace App\Filament\Resources\Investors\RelationManagers;

use App\Models\InvestmentWithdrawalNotice;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 60-day written exit notices (v3 P2.4, deed clauses 5-6). Tracking only —
 * nothing else in the system is blocked by a pending notice.
 */
class WithdrawalNoticesRelationManager extends RelationManager
{
    protected static string $relationship = 'withdrawalNotices';

    protected static ?string $title = 'Withdrawal Notices';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('notice_given_at')->default(now())->required()
                ->helperText('Effective date is automatically set to 60 days after this.'),
            Select::make('project_id')
                ->label('Project (optional)')
                ->relationship('project', 'name')
                ->searchable()
                ->helperText('Leave blank if this is a general exit notice, not tied to one project.'),
            Textarea::make('reason')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('notice_given_at')->label('Given')->date(),
            TextColumn::make('effective_at')->label('Effective')->date(),
            TextColumn::make('group.name')->label(__('Group'))->placeholder('-'),
            TextColumn::make('project.name')->label('Project')->placeholder('-'),
            TextColumn::make('reason')->limit(30)->placeholder('-'),
            TextColumn::make('status')->badge(),
        ])->headerActions([
            CreateAction::make()->mutateDataUsing(fn (array $data): array => [...$data, 'company_id' => $this->getOwnerRecord()->company_id]),
        ])->recordActions([
            // A group notice is honored by itself at the end of the project it matures in.
            Action::make('honor')->label('Mark Honored')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (InvestmentWithdrawalNotice $record): bool => $record->status === 'pending' && blank($record->group_id))
                ->requiresConfirmation()
                ->action(function (InvestmentWithdrawalNotice $record): void {
                    $record->update(['status' => 'honored']);
                    Notification::make()->success()->title('Notice marked honored')->send();
                }),
            Action::make('cancel')->label('Cancel Notice')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (InvestmentWithdrawalNotice $record): bool => $record->status === 'pending')
                ->requiresConfirmation()
                ->action(function (InvestmentWithdrawalNotice $record): void {
                    $record->update(['status' => 'cancelled']);
                    Notification::make()->success()->title('Notice cancelled')->send();
                }),
        ]);
    }
}
