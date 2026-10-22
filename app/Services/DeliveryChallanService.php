<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DeliveryChallan;
use App\Models\DeliveryChallanItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Delivery Challan of the Trade Portal (docs/tasneem-samples/PLAN.md §4).
 *
 * Writing a challan is the sale: in one transaction it makes an ERP order
 * (Order::SOURCE_TRADE_CHALLAN, completed and delivered — the goods left the
 * shop), one order line per challan line that names a product (so the stock
 * leaves through the ERP's own stock ledger and is refused when there is not
 * enough), and the advance and the cash paid that day as order payments. The
 * rest is the customer's due, counted by the ERP as for any order; later
 * money is added against the same challan (receivePayment()).
 *
 * The total is the sum of quantity × price over the priced lines: on the
 * owner's pad a machine set carries the price and the stand, feeders and
 * CAMs given with it carry none (PLAN.md §4 example: 7,50,000 × 2 =
 * 15,00,000). A price needs a product, because only order lines count.
 */
class DeliveryChallanService
{
    /**
     * @param  array<string, mixed>  $data  the challan form (see the Trade Portal's challan page)
     */
    public function create(array $data, ?User $by = null): DeliveryChallan
    {
        $lines = $this->lines($data['items'] ?? []);
        $total = round(array_sum(array_map(fn (array $line): float => $line['unit_price'] === null ? 0.0 : $line['quantity'] * $line['unit_price'], $lines)), 2);
        $advance = round(max((float) ($data['advance_amount'] ?? 0), 0), 2);
        $cash = round(max((float) ($data['cash_amount'] ?? 0), 0), 2);
        $date = Carbon::parse($data['challan_date'] ?? now())->toDateString();
        $number = trim((string) ($data['challan_number'] ?? '')) ?: $this->nextNumber();

        if ($advance + $cash > $total) {
            throw ValidationException::withMessages([
                'cash_amount' => __('Advance and cash paid together cannot be more than the total price (:total).', ['total' => number_format($total, 2)]),
            ]);
        }

        if (DeliveryChallan::query()->where('challan_number', $number)->exists()) {
            throw ValidationException::withMessages([
                'challan_number' => __('Challan no. :number is already used.', ['number' => $number]),
            ]);
        }

        $accountId = $this->accountId($data['payment_account_id'] ?? null);

        // Money received goes into a cash or bank account, so it shows in the cash book (PLAN.md §5).
        if ($advance + $cash > 0 && $accountId === null) {
            throw ValidationException::withMessages(['payment_account_id' => __('Choose where the money went.')]);
        }

        $this->assertStock($lines);

        return DB::transaction(function () use ($data, $lines, $total, $advance, $cash, $date, $number, $accountId, $by): DeliveryChallan {
            $customer = $this->customer($data);

            $challan = DeliveryChallan::query()->create([
                'customer_id' => $customer->getKey(),
                'challan_number' => $number,
                'challan_date' => $date,
                'buyer_name' => trim((string) ($data['buyer_name'] ?? '')) ?: $customer->name,
                'buyer_phone' => $this->text($data['buyer_phone'] ?? null) ?? $customer->phone,
                'buyer_address' => $this->text($data['buyer_address'] ?? null) ?? $customer->address,
                'total' => $total,
                'advance_amount' => $advance,
                'advance_date' => $advance > 0 ? ($data['advance_date'] ?? null) ?: $date : null,
                'advance_note' => $this->text($data['advance_note'] ?? null),
                'cash_amount' => $cash,
                'cash_date' => $cash > 0 ? ($data['cash_date'] ?? null) ?: $date : null,
                'payment_account_id' => $accountId,
                'cheque_number' => $this->text($data['cheque_number'] ?? null),
                'cheque_bank' => $this->text($data['cheque_bank'] ?? null),
                'due_terms' => $this->text($data['due_terms'] ?? null),
                'authorised_name' => $this->text($data['authorised_name'] ?? null),
                'authorised_designation' => $this->text($data['authorised_designation'] ?? null),
                'authorised_phone' => $this->text($data['authorised_phone'] ?? null),
                'note' => $this->text($data['note'] ?? null),
                'status' => DeliveryChallan::STATUS_CONFIRMED,
                'created_by' => $by?->getKey(),
            ]);

            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'order_date' => $date,
                'status' => Order::STATUS_COMPLETED,
                'delivery_status' => CourierBooking::STATUS_DELIVERED,
                'source' => Order::SOURCE_TRADE_CHALLAN,
                // Handed over in the shop: no delivery charge is worked out from the address.
                'shipping_zone' => 'none',
                'shipping_fee' => 0,
                'paid_amount' => 0,
                'note' => __('Delivery Challan :number', ['number' => $number]),
            ]);

            foreach ($lines as $index => $line) {
                DeliveryChallanItem::query()->create([
                    'delivery_challan_id' => $challan->getKey(),
                    'product_id' => $line['product_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_price' => $line['unit_price'],
                    'remarks' => $line['remarks'],
                    'sort_order' => $index + 1,
                ]);

                if ($line['product_id'] !== null) {
                    OrderItem::query()->create([
                        'order_id' => $order->getKey(),
                        'product_id' => $line['product_id'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'] ?? 0,
                    ]);
                }
            }

            foreach ([[$advance, OrderPayment::TYPE_ADVANCE, $challan->advance_date, $challan->advance_note], [$cash, OrderPayment::TYPE_PARTIAL, $challan->cash_date, null]] as [$amount, $type, $paidAt, $note]) {
                if ($amount > 0) {
                    $order->payments()->create([
                        'type' => $type,
                        'account_id' => $accountId,
                        'amount' => $amount,
                        'paid_at' => $paidAt,
                        'note' => $note ?? __('Paid with Delivery Challan :number', ['number' => $number]),
                    ]);
                }
            }

            $challan->forceFill(['order_id' => $order->getKey()])->save();

            return $challan->refresh();
        });
    }

    /**
     * Money received later against a challan. A cheque number or a bank name
     * goes in the note.
     */
    public function receivePayment(DeliveryChallan $challan, float $amount, CarbonInterface|string|null $date = null, ?int $accountId = null, ?string $note = null): OrderPayment
    {
        $amount = round($amount, 2);
        $due = $challan->dueNow();

        if ($challan->isCancelled() || $challan->order === null) {
            throw ValidationException::withMessages(['amount' => __('This challan is cancelled.')]);
        }

        if ($amount <= 0 || $amount > $due) {
            throw ValidationException::withMessages([
                'amount' => __('Enter an amount from 1 up to the due (:due).', ['due' => number_format($due, 2)]),
            ]);
        }

        $accountId = $this->accountId($accountId) ?? throw ValidationException::withMessages(['account_id' => __('Choose where the money went.')]);

        return $challan->order->payments()->create([
            'type' => $amount >= $due ? OrderPayment::TYPE_FINAL : OrderPayment::TYPE_PARTIAL,
            'account_id' => $accountId,
            'amount' => $amount,
            'paid_at' => Carbon::parse($date ?? now())->toDateString(),
            'note' => $this->text($note) ?? __('Payment for Delivery Challan :number', ['number' => $challan->challan_number]),
        ]);
    }

    /**
     * Money received against a customer's old due (the opening due brought in
     * from the old books), not against one challan.
     */
    public function receiveOldDue(Customer $customer, float $amount, CarbonInterface|string|null $date = null, ?int $accountId = null, ?string $note = null): CustomerPayment
    {
        $accountId = $this->accountId($accountId);

        // A customer payment is always posted to a money account (customer_payments.account_id is required).
        if ($accountId === null) {
            throw ValidationException::withMessages(['payment_account_id' => __('Choose where the money went.')]);
        }

        $type = (string) Account::query()->whereKey($accountId)->value('type');

        return CustomerPayment::query()->create([
            'customer_id' => $customer->getKey(),
            'account_id' => $accountId,
            'method' => array_key_exists($type, CustomerPayment::METHODS) ? $type : 'other',
            'amount' => round($amount, 2),
            'payment_date' => Carbon::parse($date ?? now())->toDateString(),
            'note' => $this->text($note) ?? __('Old due received'),
        ]);
    }

    /**
     * Cancels a challan written by mistake: its order is cancelled, so the
     * goods go back to stock and the due is gone. Money already recorded on it
     * stays in the payment history (it was received); give it back by hand.
     */
    public function cancel(DeliveryChallan $challan, string $reason, ?User $by = null): void
    {
        if ($challan->isCancelled()) {
            return;
        }

        DB::transaction(function () use ($challan, $reason, $by): void {
            $order = $challan->order;

            if ($order && $order->status !== Order::STATUS_CANCELLED) {
                $order->statusTransitionStage = Order::STAGE_CANCELLED;
                $order->statusTransitionReason = $reason;
                $order->statusTransitionSource = 'trade_portal';
                $order->statusTransitionActorId = $by?->getKey();
                $order->update(['status' => Order::STATUS_CANCELLED]);
            }

            $challan->forceFill([
                'status' => DeliveryChallan::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();
        });
    }

    /** The next pad number: one after the highest number used so far, three digits like "026". */
    public function nextNumber(): string
    {
        $highest = DeliveryChallan::query()
            ->pluck('challan_number')
            ->map(fn ($number): int => ctype_digit((string) $number) ? (int) $number : 0)
            ->max() ?? 0;

        return str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{product_id: int|null, description: string, quantity: int, unit: string|null, unit_price: float|null, remarks: string|null}>
     */
    protected function lines(array $items): array
    {
        $lines = [];

        foreach (array_values($items) as $index => $item) {
            $productId = filled($item['product_id'] ?? null) ? (int) $item['product_id'] : null;
            $product = $productId ? Product::query()->find($productId) : null;
            $row = $index + 1;

            if ($productId !== null && ! $product) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => __('Line :row: the item was not found.', ['row' => $row])]);
            }

            $description = trim((string) ($item['description'] ?? '')) ?: (string) $product?->name;
            $quantity = (int) ($item['quantity'] ?? 0);
            $price = filled($item['unit_price'] ?? null) ? round((float) $item['unit_price'], 2) : null;

            if ($description === '' && $quantity === 0) {
                continue;
            }

            if ($description === '') {
                throw ValidationException::withMessages(["items.{$index}.description" => __('Line :row: write what was given.', ['row' => $row])]);
            }

            if ($product !== null && $quantity < 1) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => __('Line :row: the quantity must be at least 1.', ['row' => $row])]);
            }

            if ($price !== null && $price < 0) {
                throw ValidationException::withMessages(["items.{$index}.unit_price" => __('Line :row: the price cannot be below zero.', ['row' => $row])]);
            }

            if ($price !== null && $product === null) {
                throw ValidationException::withMessages(["items.{$index}.unit_price" => __('Line :row: choose the item from stock to put a price on it.', ['row' => $row])]);
            }

            if ($product?->has_variants) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => __('Line :row: items with variations cannot be put on a challan yet.', ['row' => $row])]);
            }

            $lines[] = [
                'product_id' => $product?->getKey(),
                'description' => $description,
                'quantity' => max($quantity, 0),
                'unit' => $this->text($item['unit'] ?? null) ?? $product?->unit,
                'unit_price' => $price,
                'remarks' => $this->text($item['remarks'] ?? null),
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => __('Add at least one line.')]);
        }

        return $lines;
    }

    /**
     * Checks every product before anything is written, so the owner reads
     * which item is short instead of a general stock error.
     *
     * @param  list<array{product_id: int|null, quantity: int}>  $lines
     */
    protected function assertStock(array $lines): void
    {
        $needed = [];

        foreach ($lines as $line) {
            if ($line['product_id'] !== null) {
                $needed[$line['product_id']] = ($needed[$line['product_id']] ?? 0) + $line['quantity'];
            }
        }

        foreach ($needed as $productId => $quantity) {
            $product = Product::query()->find($productId);

            if ($product && (int) $product->stock < $quantity) {
                throw ValidationException::withMessages([
                    'items' => __('Not enough ":item" in stock: :stock left, :quantity on this challan.', [
                        'item' => $product->name,
                        'stock' => (int) $product->stock,
                        'quantity' => $quantity,
                    ]),
                ]);
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function customer(array $data): Customer
    {
        if (filled($data['customer_id'] ?? null)) {
            $customer = Customer::query()->find((int) $data['customer_id']);

            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => __('The buyer was not found.')]);
            }

            return $customer;
        }

        $name = trim((string) ($data['buyer_name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['buyer_name' => __('Write the buyer / party name.')]);
        }

        return Customer::query()->create([
            'name' => $name,
            'phone' => $this->text($data['buyer_phone'] ?? null),
            'address' => $this->text($data['buyer_address'] ?? null),
        ]);
    }

    protected function accountId(mixed $accountId): ?int
    {
        if (blank($accountId)) {
            return null;
        }

        $account = Account::query()->manual()->whereKey((int) $accountId)->first();

        if (! $account) {
            throw ValidationException::withMessages(['payment_account_id' => __('Choose where the money went.')]);
        }

        return (int) $account->getKey();
    }

    protected function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
