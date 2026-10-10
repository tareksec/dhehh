<?php

namespace App\Filament\Resources\Vouchers\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Models\FundTransfer;
use App\Services\FundTransferService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

class FundTransfersWidget extends TableWidget
{
    use GuardsWidgetRendering;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** Deep-linked from a VoucherSummaryWidget "Fund Transfer" card - see its docblock. */
    #[Url]
    public ?string $ftStatus = null;

    public static function canView(): bool
    {
        return (Auth::user()?->canCreateFundTransfer() ?? false)
            || (Auth::user()?->canApproveFundTransfer() ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Fund Transfers')
            ->description('Move money between company accounts without leaving the Vouchers page.')
            ->query(fn (): Builder => FundTransfer::query()
                ->when($this->ftStatus, fn (Builder $q, string $status): Builder => $q->where('status', $status))
                ->with(['fromAccount', 'toAccount', 'requester']))
            ->columns([
                TextColumn::make('transfer_number')->label('Transfer #')->searchable()->sortable(),
                TextColumn::make('fromAccount.name')->label('From'),
                TextColumn::make('toAccount.name')->label('To'),
                TextColumn::make('amount')->moneyWithoutTrailingZeroes('BDT')->sortable(),
                TextColumn::make('transaction_cost')->label('Transaction Cost')->moneyWithoutTrailingZeroes('BDT')->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    FundTransfer::STATUS_APPROVED => 'success',
                    FundTransfer::STATUS_REJECTED => 'danger',
                    default => 'warning',
                }),
                TextColumn::make('requester.name')->label('Requested By'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(FundTransfer::STATUSES),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (FundTransfer $record): bool => $record->status === FundTransfer::STATUS_PENDING
                        && (Auth::user()?->canApproveFundTransfer() ?? false))
                    ->requiresConfirmation()
                    ->action(function (FundTransfer $record): void {
                        app(FundTransferService::class)->approve($record, Auth::user());
                        Notification::make()->title('Fund transfer approved.')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXMark)
                    ->visible(fn (FundTransfer $record): bool => $record->status === FundTransfer::STATUS_PENDING
                        && (Auth::user()?->canApproveFundTransfer() ?? false))
                    ->requiresConfirmation()
                    ->action(function (FundTransfer $record): void {
                        app(FundTransferService::class)->reject($record, Auth::user());
                        Notification::make()->title('Fund transfer rejected.')->warning()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
