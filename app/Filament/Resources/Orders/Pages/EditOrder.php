<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\Orders\Actions\ChangeOrderWorkflowAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\Concerns\HandlesStockShortages;
use App\Filament\Resources\Orders\Pages\Concerns\ReportsStockErrors;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditOrder extends EditRecord
{
    use HandlesStockShortages;
    use HasStickyHeaderFormActions;
    use ReportsStockErrors;

    protected static string $resource = OrderResource::class;

    /** See CreateOrder: a rejected stock movement must not leave a half-saved order. */
    protected ?bool $hasDatabaseTransactions = true;

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->runReportingStockErrors(fn () => parent::save($shouldRedirect, $shouldSendSavedNotification));
    }

    /**
     * Raising a quantity or adding an item on a confirmed order takes more
     * stock: show the "Not enough stock" popup when it is short.
     */
    protected function beforeSave(): void
    {
        $this->haltWhenStockIsShort();
    }

    protected function submitAfterAddingStock(): void
    {
        $this->save();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getStickySaveFormAction(),
            ChangeOrderWorkflowAction::make(),
            ViewAction::make(),
            // A returned or refunded order is never trashed (owner decision 2026-10-15).
            DeleteAction::make()
                ->visible(fn (): bool => $this->getRecord()->canBeMovedToTrash()),
        ];
    }

    /**
     * PaymentsRelationManager dispatches this after every add/edit/delete of
     * a payment row, so the form's paid_amount/due_amount (dehydrated(false)
     * on this page — see OrderForm) pick up the ledger-recalculated values
     * right away instead of only on the next full page load.
     */
    #[On('order-payment-updated')]
    public function refreshPaymentTotals(): void
    {
        $this->refreshFormData(['paid_amount', 'due_amount']);
    }
}
