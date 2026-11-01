<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use App\Services\SupplierOrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An order a supplier placed for his own customer, filled from our stock.
 * The stock leaves our godown, the money is not ours: it comes off what we
 * owe the supplier (see SupplierAccountService).
 */
class SupplierOrder extends Model
{
    use BelongsToCompany, GeneratesSequentialNumber;

    public const STATUS_OPEN = 'open';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_DELIVERED => 'Delivered',
        self::STATUS_RETURNED => 'Returned',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** Statuses where the goods are with the customer: stock is out and the supplier is charged. */
    public const TAKEN_STATUSES = [self::STATUS_OPEN, self::STATUS_DELIVERED];

    public const COLLECTED_BY_SUPPLIER = 'supplier';

    public const COLLECTED_BY_COMPANY = 'company';

    public const COLLECTED_BY = [
        self::COLLECTED_BY_SUPPLIER => 'Supplier',
        self::COLLECTED_BY_COMPANY => 'ZamZam',
    ];

    protected $fillable = [
        'company_id',
        'supplier_id',
        'order_number',
        'external_reference',
        'order_date',
        'customer_name',
        'customer_phone',
        'delivery_date',
        'status',
        'advance_amount',
        'advance_collected_by',
        'books_stock',
        'note',
    ];

    protected $attributes = [
        'books_stock' => true,
    ];

    protected $casts = [
        'order_date' => 'date',
        'delivery_date' => 'date',
        'advance_amount' => 'decimal:2',
        'books_stock' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierOrder $order): void {
            $order->order_number ??= static::nextOrderNumber($order->company);
            $order->order_date ??= now()->toDateString();
            $order->status ??= self::STATUS_OPEN;
            $order->advance_collected_by ??= self::COLLECTED_BY_SUPPLIER;
        });

        // A status or date change moves the stock of every line.
        static::updated(function (SupplierOrder $order): void {
            if ($order->wasChanged(['status', 'order_date', 'books_stock'])) {
                app(SupplierOrderService::class)->syncOrder($order);
            }
        });

        // Remove the lines one by one so each one gives its stock back.
        static::deleting(function (SupplierOrder $order): void {
            $order->items()->get()->each->delete();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierOrderItem::class);
    }

    /** Saved and deleted as one unit: a failed stock change rolls the order back too. */
    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return DB::transaction(fn () => parent::delete());
    }

    protected function sequentialNumberColumn(): string
    {
        return 'order_number';
    }

    public static function nextOrderNumber(?Company $company = null): string
    {
        $company ??= app()->bound('company.context') ? app('company.context')->company() : null;
        $company ??= Company::defaultCompany();
        $prefix = $company?->invoice_prefix ?: 'SUP';

        do {
            $number = $prefix.'-SUP-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (self::query()
            ->when($company, fn ($query) => $query->where('company_id', $company->getKey()))
            ->where('order_number', $number)
            ->exists());

        return $number;
    }

    public function isTaken(): bool
    {
        return in_array($this->status, self::TAKEN_STATUSES, true);
    }

    public function totalPieces(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /** What the customer pays for the goods. */
    public function totalAmount(): float
    {
        return (float) $this->items->sum(fn (SupplierOrderItem $item): float => $item->amount());
    }

    /** What comes off the supplier's account: pieces x his buy price. */
    public function takenValue(): float
    {
        return (float) $this->items->sum(fn (SupplierOrderItem $item): float => $item->cost());
    }

    public function dueAmount(): float
    {
        return max($this->totalAmount() - (float) $this->advance_amount, 0);
    }
}
