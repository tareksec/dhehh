<?php

namespace App\Filament\Resources\Orders\Pages\Concerns;

use App\Models\Order;
use App\Models\StockMovement;
use App\Services\OrderStockCheckService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Auth;

/**
 * The "Not enough stock" popup for the Create and Edit order pages. A
 * confirmed / processing / completed order takes its items out of stock;
 * when any product is short, the page stops before saving and lists every
 * short product at once, with an option to add the missing stock.
 */
trait HandlesStockShortages
{
    /** Re-runs the page's own save once the stock has been added. */
    abstract protected function submitAfterAddingStock(): void;

    /**
     * Decided by the page, not by $this->record: a create rolled back by a
     * stock error still leaves its record object on the Create page.
     */
    protected function isEditingSavedOrder(): bool
    {
        return $this instanceof EditRecord && $this->record instanceof Order;
    }

    protected function haltWhenStockIsShort(): void
    {
        $status = $this->isEditingSavedOrder() ? $this->record->status : ($this->data['status'] ?? null);

        if (! in_array($status, Order::ACCOUNTED_STATUSES, true)) {
            return;
        }

        // A WooCommerce order's sale already happened on the storefront and
        // is never blocked by ERP stock (see OrderWorkflowService).
        if ($this->isEditingSavedOrder() && $this->record->source === Order::SOURCE_WOOCOMMERCE) {
            return;
        }

        // A Nuport import is history that never took stock (see
        // OrderWorkflowService::sync()), so its lines are not "short" and the
        // popup must not offer to add stock for it.
        if ($this->isEditingSavedOrder() && $this->record->isHistoricalImport()) {
            return;
        }

        if ($this->stockShortages() === []) {
            return;
        }

        $this->mountAction('stockShortage');

        $this->halt();
    }

    /**
     * @return list<array{key: string, product_id: int, product_variant_id: ?int, name: string, ordered: int, available: int, short: int}>
     */
    protected function stockShortages(): array
    {
        return app(OrderStockCheckService::class)->shortages(
            $this->data['items'] ?? [],
            $this->isEditingSavedOrder() ? $this->record : null,
        );
    }

    /**
     * @param  array{ordered: int, available: int, short: int}  $shortage
     */
    protected function shortageSummary(array $shortage): string
    {
        $replace = [
            'ordered' => $shortage['ordered'],
            'available' => $shortage['available'],
            'short' => $shortage['short'],
        ];

        // On a saved order "ordered" is only what is being added on top of
        // the stock the order already took.
        return $this->isEditingSavedOrder()
            ? __('Adding: :ordered · In stock: :available · Short by: :short', $replace)
            : __('Ordered: :ordered · In stock: :available · Short by: :short', $replace);
    }

    protected function canAddStock(): bool
    {
        return Auth::user()?->can('create', StockMovement::class) ?? false;
    }

    public function stockShortageAction(): Action
    {
        return Action::make('stockShortage')
            ->modalHeading(__('Not enough stock'))
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('warning')
            ->modalDescription(fn (): string => $this->canAddStock()
                ? __('Some products in this order are out of stock or short. Add the missing stock to place the order, or go back and change the items.')
                : __('Some products in this order are out of stock or short. You do not have permission to add stock — ask an inventory manager, or go back and change the items.'))
            ->schema(fn (): array => collect($this->stockShortages())
                ->map(fn (array $shortage) => $this->canAddStock()
                    ? TextInput::make('add.'.$shortage['key'])
                        ->label($shortage['name'])
                        ->helperText($this->shortageSummary($shortage))
                        ->integer()
                        ->required()
                        ->minValue($shortage['short'])
                        ->default($shortage['short'])
                        ->suffix(__('pcs to add'))
                    : Text::make($shortage['name'].' — '.$this->shortageSummary($shortage)))
                ->all())
            ->modalSubmitActionLabel(fn (): string => $this->isEditingSavedOrder()
                ? __('Add stock and save order')
                : __('Add stock and place order'))
            ->modalSubmitAction(fn (Action $action): Action|false => $this->canAddStock() ? $action : false)
            ->modalCancelActionLabel(__('Back to order'))
            ->action(function (array $data): void {
                if (! $this->canAddStock()) {
                    return;
                }

                $service = app(OrderStockCheckService::class);
                $editing = $this->isEditingSavedOrder();
                $orderNumber = (string) ($editing ? $this->record->order_number : ($this->data['order_number'] ?? ''));

                foreach ($this->stockShortages() as $shortage) {
                    $quantity = (int) ($data['add'][$shortage['key']] ?? 0);

                    if ($quantity > 0) {
                        $service->addStock($shortage['product_id'], $shortage['product_variant_id'], $quantity, $orderNumber, $editing);
                    }
                }

                Notification::make()
                    ->title(__('Stock added'))
                    ->success()
                    ->send();

                $this->submitAfterAddingStock();
            });
    }
}
