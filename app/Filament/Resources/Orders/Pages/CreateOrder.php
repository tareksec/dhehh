<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\Concerns\ReportsStockErrors;
use App\Models\Order;
use App\Models\StockMovement;
use App\Services\OrderStockCheckService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateOrder extends CreateRecord
{
    use HasStickyHeaderFormActions;
    use ReportsStockErrors;

    protected static string $resource = OrderResource::class;

    /**
     * The order, its items and their stock movements are written by separate
     * model hooks; one failing (e.g. not enough stock for the second item)
     * must not leave a half-saved order behind.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [
            $this->getStickySaveFormAction(),
        ];
    }

    public function create(bool $another = false): void
    {
        $this->runReportingStockErrors(fn () => parent::create($another));
    }

    /**
     * A confirmed / processing / completed order takes its items out of
     * stock. When any product is short, stop and show the "Not enough
     * stock" popup instead of failing on the first short item.
     */
    protected function beforeCreate(): void
    {
        if (! in_array($this->data['status'] ?? null, Order::ACCOUNTED_STATUSES, true)) {
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
        return app(OrderStockCheckService::class)->shortages($this->data['items'] ?? []);
    }

    /**
     * @param  array{ordered: int, available: int, short: int}  $shortage
     */
    protected function shortageSummary(array $shortage): string
    {
        return __('Ordered: :ordered · In stock: :available · Short by: :short', [
            'ordered' => $shortage['ordered'],
            'available' => $shortage['available'],
            'short' => $shortage['short'],
        ]);
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
            ->modalSubmitActionLabel(__('Add stock and place order'))
            ->modalSubmitAction(fn (Action $action): Action|false => $this->canAddStock() ? $action : false)
            ->modalCancelActionLabel(__('Back to order'))
            ->action(function (array $data): void {
                if (! $this->canAddStock()) {
                    return;
                }

                $service = app(OrderStockCheckService::class);
                $orderNumber = (string) ($this->data['order_number'] ?? '');

                foreach ($this->stockShortages() as $shortage) {
                    $quantity = (int) ($data['add'][$shortage['key']] ?? 0);

                    if ($quantity > 0) {
                        $service->addStock($shortage['product_id'], $shortage['product_variant_id'], $quantity, $orderNumber);
                    }
                }

                Notification::make()
                    ->title(__('Stock added'))
                    ->success()
                    ->send();

                $this->create();
            });
    }

    /**
     * The "Payment Method" account picked next to Paid Amount is not an
     * order column; hand it to the order so its first Payments History row
     * posts into that account (see Order::booted()'s `created` hook).
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Order $record */
        $record = new ($this->getModel())($data);
        $accountId = $this->data['payment_account_id'] ?? null;
        $record->initialPaymentAccountId = filled($accountId) ? (int) $accountId : null;
        $record->save();

        return $record;
    }

    /**
     * After creating an order, go straight back to the orders list (owner
     * request) instead of Filament's default jump to the new order's View
     * page. The "Created" success toast still fires.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
