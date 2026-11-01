<?php

namespace App\Livewire;

use App\Models\Order;
use App\Services\CompanyContext;
use App\Support\TypeToConfirmDelete;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class OrderTrashTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(mixed $record = null): void
    {
        abort_unless($this->canManageTrash(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->trashedOrdersQuery()->with('customer'))
            ->columns([
                TextColumn::make('order_number')
                    ->label('Invoice Number')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->getStateUsing(fn (Order $record): ?string => $record->customer?->name ?? $record->customer_name)
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->label('Total Amount')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->label('Moved to Trash')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->authorize(fn (Order $record): bool => $this->canRestore($record))
                    ->requiresConfirmation()
                    ->modalHeading('Restore order?')
                    ->modalDescription('This order will return to the active Orders list. Its stock and customer balance effects will also be restored.')
                    ->modalSubmitActionLabel('Restore order')
                    ->action(fn (Order $record): bool => $this->restoreOrder($record)),
                // Owner request 2026-11-01: from Trash an order is restored or deleted for good, and the
                // permanent delete asks for the invoice number first.
                TypeToConfirmDelete::apply(Action::make('deletePermanently'), permanent: true)
                    ->label(__('Delete permanently'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->authorize(fn (Order $record): bool => $this->canRestore($record))
                    ->modalHeading(__('Delete this order permanently?'))
                    ->modalSubmitActionLabel(__('Delete permanently'))
                    ->action(fn (Order $record): bool => $this->deleteOrderPermanently($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('restoreSelected')
                        ->label('Restore selected')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('success')
                        ->authorize(fn (): bool => $this->canManageTrash())
                        ->authorizeIndividualRecords(fn (Order $record): bool => $this->canRestore($record))
                        ->requiresConfirmation()
                        ->modalHeading('Restore selected orders?')
                        ->modalDescription('The selected orders will return to the active Orders list, including their stock and customer balance effects.')
                        ->modalSubmitActionLabel('Restore selected')
                        ->action(fn (Collection $records): int => $this->restoreSelectedOrders($records))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('No orders in trash')
            ->emptyStateDescription('Orders moved to trash will appear here.')
            ->emptyStateIcon('heroicon-o-trash')
            ->defaultSort('deleted_at', 'desc')
            ->paginated([5, 10, 25, 'all'])
            ->defaultPaginationPageOption(5);
    }

    public function render(): View
    {
        return view('livewire.order-trash-table');
    }

    protected function restoreSelectedOrders(Collection $orders): int
    {
        $restored = 0;
        $failures = [];

        foreach ($orders as $order) {
            if (! $order instanceof Order || ! $this->canRestore($order)) {
                continue;
            }

            $failureMessage = null;

            if ($this->restoreOrder($order, notify: false, failureMessage: $failureMessage)) {
                $restored++;

                continue;
            }

            $failures[] = $failureMessage;
        }

        $selected = $orders->count();

        if ($restored === $selected) {
            Notification::make()
                ->title("{$restored} selected order(s) restored")
                ->success()
                ->send();

            return $restored;
        }

        Notification::make()
            ->title($restored > 0
                ? "{$restored} of {$selected} selected order(s) restored"
                : 'Selected orders could not be restored')
            ->body(collect($failures)->filter()->first() ?? 'Please review the selected orders and stock availability, then try again.')
            ->warning()
            ->send();

        return $restored;
    }

    protected function restoreOrder(Order $order, bool $notify = true, ?string &$failureMessage = null): bool
    {
        if (! $this->canRestore($order)) {
            $failureMessage = 'The order is no longer available in this company trash.';

            if ($notify) {
                Notification::make()
                    ->title('Order is no longer in trash')
                    ->warning()
                    ->send();
            }

            return false;
        }

        try {
            DB::transaction(fn (): bool => $order->restore());
        } catch (ValidationException $exception) {
            $failureMessage = collect($exception->errors())->flatten()->first()
                ?? 'Please review the order and stock availability, then try again.';

            if ($notify) {
                Notification::make()
                    ->title("{$order->order_number} could not be restored")
                    ->body($failureMessage)
                    ->danger()
                    ->send();
            }

            return false;
        }

        if ($notify) {
            Notification::make()
                ->title("{$order->order_number} restored")
                ->body('The order is available in the active Orders list again.')
                ->success()
                ->send();
        }

        return true;
    }

    protected function deleteOrderPermanently(Order $order): bool
    {
        if (! $this->canRestore($order)) {
            Notification::make()
                ->title(__('Order is no longer in trash'))
                ->warning()
                ->send();

            return false;
        }

        try {
            DB::transaction(fn (): bool => (bool) $order->forceDelete());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__(':number could not be deleted', ['number' => $order->order_number]))
                ->body(collect($exception->errors())->flatten()->first() ?? __('Please try again.'))
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title(__(':number deleted permanently', ['number' => $order->order_number]))
            ->success()
            ->send();

        return true;
    }

    protected function trashedOrdersQuery(): Builder
    {
        return Order::onlyTrashed()
            ->where('company_id', app(CompanyContext::class)->id() ?? 0);
    }

    protected function canRestore(Order $order): bool
    {
        return $this->canManageTrash()
            && $order->trashed()
            && (int) $order->company_id === (int) app(CompanyContext::class)->id();
    }

    protected function canManageTrash(): bool
    {
        return (Auth::user()?->canDeleteSensitiveRecords() ?? false)
            && app(CompanyContext::class)->hasCompany();
    }
}
