<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use App\Services\PurchaseReceiptService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Goods a supplier delivered on one date. Unlike a Purchase (an invoice), a
 * receipt records pieces and cartons, what was lost on the way (short) and
 * what arrived broken (damage); it books the stock itself. The supplier
 * account reads its figures from these receipts.
 */
class PurchaseReceipt extends Model
{
    use BelongsToCompany, GeneratesSequentialNumber;

    protected $fillable = [
        'company_id',
        'supplier_id',
        'receipt_number',
        'received_on',
        'reference',
        'is_opening',
        'books_stock',
        'note',
    ];

    protected $attributes = [
        'books_stock' => true,
    ];

    protected $casts = [
        'received_on' => 'date',
        'is_opening' => 'boolean',
        'books_stock' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseReceipt $receipt): void {
            $receipt->receipt_number ??= static::nextReceiptNumber($receipt->company);
            $receipt->received_on ??= now()->toDateString();
        });

        static::updated(function (PurchaseReceipt $receipt): void {
            if ($receipt->wasChanged('received_on')) {
                app(PurchaseReceiptService::class)->syncDate($receipt);
            }

            // Switching "add to stock" on or off books or releases the stock of every line.
            if ($receipt->wasChanged('books_stock')) {
                $receipt->items()->get()->each(fn (PurchaseReceiptItem $item) => app(PurchaseReceiptService::class)->syncStock($item));
            }
        });

        // Remove the items one by one so each one releases the stock it booked.
        static::deleting(function (PurchaseReceipt $receipt): void {
            $receipt->items()->get()->each->delete();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    /** Saved and deleted as one unit: a failed stock change rolls the receipt back too. */
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
        return 'receipt_number';
    }

    public static function nextReceiptNumber(?Company $company = null): string
    {
        $company ??= app()->bound('company.context') ? app('company.context')->company() : null;
        $company ??= Company::defaultCompany();
        $prefix = $company?->invoice_prefix ?: 'GRN';

        do {
            $number = $prefix.'-GRN-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (self::query()
            ->when($company, fn ($query) => $query->where('company_id', $company->getKey()))
            ->where('receipt_number', $number)
            ->exists());

        return $number;
    }

    public function totalPieces(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function totalValue(): float
    {
        return (float) $this->items->sum(fn (PurchaseReceiptItem $item): float => $item->value());
    }
}
