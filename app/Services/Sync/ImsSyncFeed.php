<?php

namespace App\Services\Sync;

use App\Models\Category;
use App\Models\Container;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The read-only data feed an outside system (IMS) pulls from. One definition
 * per resource: the query, the business-date column for the date filter, the
 * status column for the status filter, and the JSON shape of a record.
 *
 * Paging is keyset-based on (updated_at, id) so a record changed while the
 * caller is mid-way through a sync can never make it skip or repeat a row —
 * unlike page numbers, which shift. `updated_since` is INCLUSIVE; the caller
 * upserts by id, so receiving a boundary record twice is harmless, while an
 * exclusive filter could silently lose records sharing the boundary second.
 *
 * Every query goes through the model's CompanyScope; the company is the one the
 * API key belongs to (ResolveCompanyFromApiKey).
 */
class ImsSyncFeed
{
    public const DEFAULT_PER_PAGE = 100;

    public const MAX_PER_PAGE = 500;

    /** @return list<string> */
    public function resourceNames(): array
    {
        return array_keys($this->definitions());
    }

    public function has(string $resource): bool
    {
        return array_key_exists($resource, $this->definitions());
    }

    /**
     * @param  array{updated_since?: ?string, cursor?: ?string, per_page?: ?int, status?: ?string, date_from?: ?string, date_to?: ?string, ids_only?: bool}  $options
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(string $resource, array $options): array
    {
        $definition = $this->definitions()[$resource] ?? throw new InvalidArgumentException("Unknown sync resource [{$resource}].");

        $perPage = max(1, min((int) ($options['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));
        $idsOnly = (bool) ($options['ids_only'] ?? false);

        /** @var Builder $query */
        $query = ($definition['query'])();
        $table = $query->getModel()->getTable();

        if (filled($options['updated_since'] ?? null)) {
            $query->where("{$table}.updated_at", '>=', $this->toDatabaseTime(Carbon::parse($options['updated_since'])));
        }

        if (filled($options['status'] ?? null) && isset($definition['status'])) {
            $query->where("{$table}.{$definition['status']}", $options['status']);
        }

        if (isset($definition['date'])) {
            if (filled($options['date_from'] ?? null)) {
                $query->whereDate("{$table}.{$definition['date']}", '>=', $options['date_from']);
            }
            if (filled($options['date_to'] ?? null)) {
                $query->whereDate("{$table}.{$definition['date']}", '<=', $options['date_to']);
            }
        }

        if (filled($options['cursor'] ?? null)) {
            [$cursorTime, $cursorId] = $this->decodeCursor((string) $options['cursor']);

            $query->where(function (Builder $q) use ($table, $cursorTime, $cursorId): void {
                $q->where("{$table}.updated_at", '>', $cursorTime)
                    ->orWhere(function (Builder $same) use ($table, $cursorTime, $cursorId): void {
                        $same->where("{$table}.updated_at", '=', $cursorTime)->where("{$table}.id", '>', $cursorId);
                    });
            });
        }

        if (! $idsOnly && isset($definition['with'])) {
            $query->with($definition['with']);
        }

        // One extra row tells us whether another page exists without a COUNT.
        $rows = $query->orderBy("{$table}.updated_at")->orderBy("{$table}.id")->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage)->values();

        $last = $rows->last();

        return [
            'data' => $rows->map(fn (Model $row): array => $idsOnly ? $this->identity($row) : ($definition['map'])($row))->all(),
            'meta' => [
                'resource' => $resource,
                'count' => $rows->count(),
                'per_page' => $perPage,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && $last ? $this->encodeCursor($last) : null,
                'server_time' => now()->utc()->toIso8601String(),
            ],
        ];
    }

    /** @return array{0: string, 1: int} */
    public function decodeCursor(string $cursor): array
    {
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($decoded) || count($decoded) !== 2 || ! is_string($decoded[0]) || ! is_int($decoded[1])) {
            throw new InvalidArgumentException('The cursor is not valid.');
        }

        return [$decoded[0], $decoded[1]];
    }

    protected function encodeCursor(Model $row): string
    {
        $time = $row->getRawOriginal('updated_at');
        $time = $time instanceof CarbonInterface ? $this->toDatabaseTime($time) : (string) $time;

        return rtrim(strtr(base64_encode((string) json_encode([$time, (int) $row->getKey()])), '+/', '-_'), '=');
    }

    /** The database stores times in the app timezone, so convert before comparing. */
    protected function toDatabaseTime(CarbonInterface $time): string
    {
        return Carbon::instance($time)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    protected function identity(Model $row): array
    {
        return [
            'id' => $row->getKey(),
            'updated_at' => $this->time($row->updated_at),
            'deleted' => method_exists($row, 'trashed') && $row->trashed(),
        ];
    }

    // ------------------------------------------------------------ definitions

    /**
     * @return array<string, array{query: Closure, map: Closure, with?: list<string>, status?: string, date?: string}>
     */
    protected function definitions(): array
    {
        return [
            'categories' => [
                'query' => fn (): Builder => Category::query(),
                'map' => fn (Category $c): array => [
                    'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'is_active' => (bool) $c->is_active,
                    'created_at' => $this->time($c->created_at), 'updated_at' => $this->time($c->updated_at),
                ],
            ],
            'products' => [
                'query' => fn (): Builder => Product::query(),
                'with' => ['category:id,name'],
                'status' => 'status',
                'map' => fn (Product $p): array => [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'barcode' => $p->barcode,
                    'name' => $p->name,
                    'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name] : null,
                    'unit' => $p->unit,
                    'brand' => $p->brand,
                    'description' => $p->description,
                    'cost_price' => $this->money($p->cost_price),
                    'price' => $this->money($p->price),
                    'sale_price' => $this->money($p->sale_price),
                    'vat_rate' => $this->money($p->vat_rate),
                    'stock' => (int) $p->stock,
                    'reorder_level' => $p->reorder_level !== null ? (int) $p->reorder_level : null,
                    'status' => $p->status,
                    'is_active' => (bool) $p->is_active,
                    'created_at' => $this->time($p->created_at),
                    'updated_at' => $this->time($p->updated_at),
                ],
            ],
            'stock-movements' => [
                'query' => fn (): Builder => StockMovement::query(),
                'with' => ['product:id,sku,name,cost_price'],
                'status' => 'type',
                'date' => 'created_at',
                'map' => fn (StockMovement $m): array => [
                    'id' => $m->id,
                    'product_id' => $m->product_id,
                    'sku' => $m->product?->sku,
                    'product_variant_id' => $m->product_variant_id,
                    // 'opening', 'purchase', 'sale', 'return', 'damage', 'adjustment' or 'transfer'.
                    'type' => $m->type,
                    'quantity' => (int) $m->quantity,
                    'reason' => $m->reason,
                    // Link to the record that caused the movement (an order for a sale or return, a purchase, ...).
                    'reference_type' => $m->reference_type ? class_basename($m->reference_type) : null,
                    'reference_id' => $m->reference_id,
                    'movement_date' => $this->date($m->created_at),
                    // The product's CURRENT cost price, not the cost on the day: the ERP does not store one per movement.
                    'unit_cost' => $this->money($m->product?->cost_price),
                    'estimated_value' => $m->product ? $this->money((float) $m->product->cost_price * (int) $m->quantity) : null,
                    'created_at' => $this->time($m->created_at),
                    'updated_at' => $this->time($m->updated_at),
                ],
            ],
            'suppliers' => [
                'query' => fn (): Builder => Supplier::query(),
                'map' => fn (Supplier $s): array => [
                    'id' => $s->id, 'name' => $s->name, 'company_name' => $s->company_name, 'phone' => $s->phone,
                    'email' => $s->email, 'address' => $s->address,
                    'opening_balance' => $this->money($s->opening_balance), 'current_balance' => $this->money($s->current_balance),
                    'is_active' => (bool) $s->is_active,
                    'created_at' => $this->time($s->created_at), 'updated_at' => $this->time($s->updated_at),
                ],
            ],
            'purchases' => [
                'query' => fn (): Builder => Purchase::query(),
                'with' => ['supplier:id,name', 'items.product:id,sku,name'],
                'status' => 'status',
                'date' => 'purchase_date',
                'map' => fn (Purchase $p): array => [
                    'id' => $p->id,
                    'purchase_number' => $p->purchase_number,
                    'supplier' => $p->supplier ? ['id' => $p->supplier->id, 'name' => $p->supplier->name] : null,
                    'purchase_date' => $this->date($p->purchase_date),
                    // 'draft', 'received' or 'cancelled'.
                    'status' => $p->status,
                    'lc_number' => $p->lc_number, 'lc_date' => $this->date($p->lc_date),
                    'pi_number' => $p->pi_number, 'pi_date' => $this->date($p->pi_date),
                    'ci_number' => $p->ci_number, 'ci_date' => $this->date($p->ci_date),
                    'subtotal' => $this->money($p->subtotal),
                    'discount' => $this->money($p->discount),
                    'vat' => $this->money($p->vat),
                    'cost_components' => collect([
                        'machine_purchase', 'inspection', 'freight_to_ctg', 'duty', 'c_and_f', 'misc', 'truck',
                        'load_unload', 'spare_parts', 'cam', 'positive_feeder', 'cylinder',
                    ])->mapWithKeys(fn (string $key): array => [$key => $this->money($p->{$key})])->all(),
                    'custom_costs' => $p->custom_costs,
                    'total_amount' => $this->money($p->total_amount),
                    'paid_amount' => $this->money($p->paid_amount),
                    'due_amount' => $this->money($p->due_amount),
                    'items' => $p->items->map(fn ($item): array => [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'sku' => $item->product?->sku,
                        'name' => $item->product?->name,
                        'quantity' => (int) $item->quantity,
                        'unit_cost' => $this->money($item->unit_cost),
                        'subtotal' => $this->money($item->subtotal),
                        // The ERP's own landed cost per unit, so IMS can compare it with its calculation.
                        'allocated_cost' => $this->money($item->allocated_cost),
                        'landed_unit_cost' => $this->money($item->landed_unit_cost),
                    ])->values()->all(),
                    'created_at' => $this->time($p->created_at), 'updated_at' => $this->time($p->updated_at),
                ],
            ],
            'supplier-payments' => [
                'query' => fn (): Builder => SupplierPayment::query(),
                'date' => 'payment_date',
                'map' => fn (SupplierPayment $p): array => [
                    'id' => $p->id, 'payment_number' => $p->payment_number, 'supplier_id' => $p->supplier_id,
                    'amount' => $this->money($p->amount), 'payment_date' => $this->date($p->payment_date),
                    'method' => $p->method, 'reference' => $p->reference,
                    'created_at' => $this->time($p->created_at), 'updated_at' => $this->time($p->updated_at),
                ],
            ],
            'containers' => [
                'query' => fn (): Builder => Container::query(),
                'status' => 'status',
                'map' => fn (Container $c): array => [
                    'id' => $c->id, 'container_number' => $c->container_number, 'shipping_line' => $c->shipping_line,
                    'origin' => $c->origin, 'destination' => $c->destination, 'status' => $c->status,
                    'estimated_departure' => $this->date($c->estimated_departure), 'actual_departure' => $this->date($c->actual_departure),
                    'estimated_arrival' => $this->date($c->estimated_arrival), 'actual_arrival' => $this->date($c->actual_arrival),
                    'created_at' => $this->time($c->created_at), 'updated_at' => $this->time($c->updated_at),
                ],
            ],
            'shipments' => [
                'query' => fn (): Builder => Shipment::query(),
                'with' => ['container:id,container_number', 'purchase:id,purchase_number'],
                'status' => 'status',
                'date' => 'created_at',
                'map' => fn (Shipment $s): array => [
                    'id' => $s->id,
                    'container_id' => $s->container_id, 'container_number' => $s->container?->container_number,
                    'purchase_id' => $s->purchase_id, 'purchase_number' => $s->purchase?->purchase_number,
                    'tracking_number' => $s->tracking_number, 'carrier' => $s->carrier, 'transport_mode' => $s->transport_mode,
                    // 'planned', 'booked', 'shipped', 'in_transit', 'customs', 'received' or 'cancelled'.
                    'status' => $s->status,
                    'shipped_at' => $this->date($s->shipped_at), 'estimated_arrival' => $this->date($s->estimated_arrival),
                    'received_at' => $this->date($s->received_at),
                    'created_at' => $this->time($s->created_at), 'updated_at' => $this->time($s->updated_at),
                ],
            ],
            // A sale. Soft-deleted orders stay in the feed, flagged `deleted`, because profit history depends on them.
            'orders' => [
                'query' => fn (): Builder => Order::query()->withTrashed(),
                'with' => ['items.product:id,sku,name', 'payments', 'costs'],
                'status' => 'status',
                'date' => 'order_date',
                'map' => fn (Order $o): array => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'external_reference' => $o->external_reference,
                    'source' => $o->source,
                    'order_date' => $this->date($o->order_date),
                    // 'draft', 'confirmed', 'processing', 'completed', 'cancelled', 'returned' or 'refunded'.
                    'status' => $o->status,
                    'delivery_status' => $o->delivery_status,
                    'deleted' => $o->trashed(),
                    'deleted_at' => $this->time($o->deleted_at),
                    'subtotal' => $this->money($o->subtotal),
                    'discount' => $this->money($o->discount),
                    'vat' => $this->money($o->vat),
                    'shipping_fee' => $this->money($o->shipping_fee),
                    'packaging_fee' => $this->money($o->packaging_fee),
                    'total_amount' => $this->money($o->total_amount),
                    'paid_amount' => $this->money($o->paid_amount),
                    'due_amount' => $this->money($o->due_amount),
                    // The ERP returns a whole order, never part of one: the full total once it is returned or refunded.
                    'returned_amount' => $this->money($this->isReturned($o) ? $o->total_amount : 0),
                    'items' => $o->items->map(fn ($item): array => [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'sku' => $item->product?->sku,
                        'name' => $item->product?->name,
                        'variant_label' => $item->variant_label,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => $this->money($item->unit_price),
                        // Cost of goods for the line, as the ERP recorded it at sale time.
                        'unit_cost' => $this->money($item->unit_cost),
                        'subtotal' => $this->money($item->subtotal),
                    ])->values()->all(),
                    // Money received, each with its own date: this is what profit sharing runs on.
                    'payments' => $o->payments->map(fn (OrderPayment $payment): array => $this->orderPayment($payment))->values()->all(),
                    'costs' => $o->costs->map(fn ($cost): array => [
                        'id' => $cost->id, 'cost_head' => $cost->cost_head, 'amount' => $this->money($cost->amount),
                    ])->values()->all(),
                    'created_at' => $this->time($o->created_at), 'updated_at' => $this->time($o->updated_at),
                ],
            ],
            // A returned or refunded sale, as its own record. The ERP returns whole orders only, so the returned
            // value is the order total; it does not record a separate refund payment, so none is invented here.
            'sale-returns' => [
                'query' => fn (): Builder => Order::query()->withTrashed()->whereIn('orders.status', [Order::STATUS_RETURNED, Order::STATUS_REFUNDED]),
                'with' => ['items.product:id,sku,name', 'statusTransitions'],
                'status' => 'status',
                'date' => 'order_date',
                'map' => fn (Order $o): array => [
                    'id' => $o->id,
                    'order_id' => $o->id,
                    'order_number' => $o->order_number,
                    'order_date' => $this->date($o->order_date),
                    // 'returned' or 'refunded'.
                    'status' => $o->status,
                    // When the order last moved to returned / refunded; the last edit if that was never logged.
                    'return_date' => $this->date(
                        $o->statusTransitions
                            ->whereIn('to_status', [Order::STATUS_RETURNED, Order::STATUS_REFUNDED])
                            ->sortBy('id')
                            ->last()?->created_at ?? $o->updated_at
                    ),
                    'return_value' => $this->money($o->total_amount),
                    'paid_amount' => $this->money($o->paid_amount),
                    'deleted' => $o->trashed(),
                    'items' => $o->items->map(fn ($item): array => [
                        'id' => $item->id,
                        'sku' => $item->product?->sku,
                        'name' => $item->product?->name,
                        'quantity' => (int) $item->quantity,
                        'value' => $this->money($item->subtotal),
                    ])->values()->all(),
                    'created_at' => $this->time($o->created_at), 'updated_at' => $this->time($o->updated_at),
                ],
            ],
            // The same payments as inside `orders`, as their own feed: editing only a payment's date or
            // method does not touch the order row, so this feed is how a change like that is noticed.
            'order-payments' => [
                // A deleted payment stays here, flagged, so IMS sees that a collected amount was taken back.
                'query' => fn (): Builder => OrderPayment::query()->withTrashed(),
                'with' => ['order:id,order_number'],
                'status' => 'type',
                'date' => 'paid_at',
                'map' => fn (OrderPayment $p): array => $this->orderPayment($p) + [
                    'order_id' => $p->order_id,
                    'order_number' => $p->order?->order_number,
                    'deleted' => $p->trashed(),
                    'deleted_at' => $this->time($p->deleted_at),
                    'created_at' => $this->time($p->created_at),
                    'updated_at' => $this->time($p->updated_at),
                ],
            ],
            'expense-categories' => [
                'query' => fn (): Builder => ExpenseCategory::query(),
                'map' => fn (ExpenseCategory $c): array => [
                    'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'is_active' => (bool) $c->is_active,
                    'created_at' => $this->time($c->created_at), 'updated_at' => $this->time($c->updated_at),
                ],
            ],
            'expenses' => [
                // A deleted expense stays here, flagged. Free-text notes are left out on purpose (they can name people).
                'query' => fn (): Builder => Expense::query()->withTrashed(),
                'with' => ['category:id,name'],
                'date' => 'expense_date',
                'map' => fn (Expense $e): array => [
                    'id' => $e->id, 'expense_number' => $e->expense_number,
                    'category' => $e->category ? ['id' => $e->category->id, 'name' => $e->category->name] : null,
                    'amount' => $this->money($e->amount), 'expense_date' => $this->date($e->expense_date),
                    'reference' => $e->reference,
                    'deleted' => $e->trashed(),
                    'deleted_at' => $this->time($e->deleted_at),
                    'created_at' => $this->time($e->created_at), 'updated_at' => $this->time($e->updated_at),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function orderPayment(OrderPayment $payment): array
    {
        return [
            'id' => $payment->id,
            // 'advance', 'partial' or 'final'.
            'type' => $payment->type,
            'method' => $payment->method,
            'amount' => $this->money($payment->amount),
            'paid_at' => $this->date($payment->paid_at),
        ];
    }

    protected function isReturned(Order $order): bool
    {
        return in_array($order->status, [Order::STATUS_RETURNED, Order::STATUS_REFUNDED], true);
    }

    /** Money as a two-decimal string, so no client rounds it through a float. */
    protected function money(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }

    protected function date(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : ($value === null ? null : (string) $value);
    }

    /** ISO 8601 in UTC with the offset spelled out, so the caller never has to guess a timezone. */
    protected function time(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? Carbon::instance($value)->utc()->toIso8601String() : null;
    }
}
