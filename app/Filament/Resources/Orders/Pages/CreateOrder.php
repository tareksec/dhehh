<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\Concerns\HandlesStockShortages;
use App\Filament\Resources\Orders\Pages\Concerns\ReportsStockErrors;
use App\Models\Customer;
use App\Models\Order;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOrder extends CreateRecord
{
    use HandlesStockShortages;
    use HasStickyHeaderFormActions;
    use ReportsStockErrors;

    protected static string $resource = OrderResource::class;

    /**
     * The order, its items and their stock movements are written by separate
     * model hooks; one failing (e.g. not enough stock for the second item)
     * must not leave a half-saved order behind.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * "Create order" in CRM → Email opens this page with `?customer=ID`: the customer is already picked, and the fields
     * that depend on the customer (name, shipping fee, courier check) are filled exactly as if it had been picked by hand.
     * An unknown, inactive or other company's customer is ignored.
     */
    protected function afterFill(): void
    {
        $customerId = (int) request()->query('customer');

        if ($customerId <= 0 || ! Customer::query()->where('is_active', true)->whereKey($customerId)->exists()) {
            return;
        }

        $this->form->fill([...$this->data, 'customer_id' => $customerId]);
        $this->form->getComponent('customer_id')?->callAfterStateUpdated();
    }

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
        $this->haltWhenStockIsShort();
    }

    protected function submitAfterAddingStock(): void
    {
        $this->create();
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
