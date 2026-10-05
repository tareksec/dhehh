<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\Orders\Actions\ChangeOrderWorkflowAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\Concerns\ReportsStockErrors;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditOrder extends EditRecord
{
    use HasStickyHeaderFormActions;
    use ReportsStockErrors;

    protected static string $resource = OrderResource::class;

    /** See CreateOrder: a rejected stock movement must not leave a half-saved order. */
    protected ?bool $hasDatabaseTransactions = true;

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->runReportingStockErrors(fn () => parent::save($shouldRedirect, $shouldSendSavedNotification));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getStickySaveFormAction(),
            ChangeOrderWorkflowAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
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
