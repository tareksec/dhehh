<?php

namespace App\Filament\Resources\Vouchers\Pages;

use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\Voucher;
use App\Services\VoucherTrashService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Vouchers moved to Trash (owner, 2026-09-28). Restore or delete permanently;
 * both need the `voucher.delete` permission.
 */
class TrashedVouchers extends ListRecords
{
    protected static string $resource = VoucherResource::class;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canDeleteVoucher() ?? false, 403);

        parent::mount();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Voucher trash');
    }

    public function getSubheading(): ?string
    {
        return __('Vouchers moved to trash. Restore one to put it back in the voucher list, or delete it permanently. A permanent delete cannot be undone.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('Voucher list'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(VoucherResource::getUrl('index')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Voucher::onlyTrashed()->with(['submitter', 'deleter']))
            ->columns([
                TextColumn::make('voucher_number')->label(__('Voucher No.'))->searchable()->weight('bold'),
                TextColumn::make('type')->label(__('Type'))->badge()->formatStateUsing(fn (?string $state): string => __(Voucher::TYPES[$state] ?? (string) $state)),
                TextColumn::make('transaction_type')->label(__('Transaction type'))->formatStateUsing(fn (?string $state): string => __(Voucher::TRANSACTION_TYPES[$state] ?? (string) $state)),
                TextColumn::make('amount')->label(__('Amount'))->moneyWithoutTrailingZeroes('BDT'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('purpose')->label(__('Purpose'))->limit(50)->placeholder('-'),
                TextColumn::make('deleter.name')->label(__('Moved to trash by'))->placeholder('-'),
                TextColumn::make('deleted_at')->label(__('Moved to trash at'))->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('restore')
                    ->label(__('Restore'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Voucher $record) => $this->run(fn () => app(VoucherTrashService::class)->restore($record, Auth::user()), __('Voucher restored.'))),
                Action::make('deletePermanently')
                    ->label(__('Delete permanently'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Delete this voucher permanently?'))
                    ->modalDescription(__('This cannot be undone.'))
                    ->action(fn (Voucher $record) => $this->run(fn () => app(VoucherTrashService::class)->deletePermanently($record, Auth::user()), __('Voucher deleted permanently.'))),
            ])
            ->emptyStateHeading(__('No vouchers in trash'))
            ->emptyStateIcon(Heroicon::OutlinedTrash)
            ->defaultSort('deleted_at', 'desc');
    }

    private function run(\Closure $operation, string $success): void
    {
        try {
            $operation();
            Notification::make()->title($success)->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title(__('Could not complete this'))->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
        }
    }
}
