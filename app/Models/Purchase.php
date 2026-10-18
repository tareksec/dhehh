<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use App\Services\PurchaseWorkflowService;
use App\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Purchase extends Model
{
    use BelongsToCompany, GeneratesSequentialNumber;

    public const CHINA_TO_BD_COST_FIELDS = [
        'machine_purchase' => 'Machine Purchase',
        'inspection' => 'Inspection',
        'freight_to_ctg' => 'Freight to Ctg',
        'duty' => 'Duty',
        'c_and_f' => 'C&F',
        'misc' => 'Misc',
        'truck' => 'Truck',
        'load_unload' => 'Load & Unload',
        'spare_parts' => 'Spare Parts',
        'cam' => 'CAM',
        'positive_feeder' => 'Positive Feeder',
        'cylinder' => 'Cylinder',
    ];

    /** Suggested names for the costs paid after the goods reached the house (free text is allowed too). */
    public const PRODUCT_COST_SUGGESTIONS = [
        'Packaging cost',
        'Ad cost',
        'Other cost',
    ];

    public const STATUSES = [
        'draft' => 'Draft',
        'received' => 'Received',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'company_id',
        'purchase_number',
        'supplier_id',
        'purchase_date',
        'lc_number',
        'lc_date',
        'pi_number',
        'pi_date',
        'ci_number',
        'ci_date',
        'subtotal',
        'discount',
        'vat',
        'machine_purchase',
        'inspection',
        'freight_to_ctg',
        'duty',
        'c_and_f',
        'misc',
        'truck',
        'load_unload',
        'spare_parts',
        'cam',
        'positive_feeder',
        'cylinder',
        'custom_costs',
        'product_costs',
        'funding_sources',
        'total_amount',
        'paid_amount',
        'due_amount',
        'status',
        'update_cost_price',
        'note',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'lc_date' => 'date',
        'pi_date' => 'date',
        'ci_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'vat' => 'decimal:2',
        'machine_purchase' => 'decimal:2',
        'inspection' => 'decimal:2',
        'freight_to_ctg' => 'decimal:2',
        'duty' => 'decimal:2',
        'c_and_f' => 'decimal:2',
        'misc' => 'decimal:2',
        'truck' => 'decimal:2',
        'load_unload' => 'decimal:2',
        'spare_parts' => 'decimal:2',
        'cam' => 'decimal:2',
        'positive_feeder' => 'decimal:2',
        'cylinder' => 'decimal:2',
        'custom_costs' => 'array',
        'product_costs' => 'array',
        'funding_sources' => 'array',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
        'update_cost_price' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Purchase $purchase): void {
            $purchase->purchase_number ??= static::nextPurchaseNumber($purchase->company);
            $purchase->purchase_date ??= now()->toDateString();
        });

        static::saved(function (Purchase $purchase): void {
            $purchase->syncTotalsAndStock();
            app(PurchaseWorkflowService::class)->syncPreviousSupplierBalance($purchase);
        });

        static::deleted(function (Purchase $purchase): void {
            app(PurchaseWorkflowService::class)->deleteStockMovements($purchase);
            app(PurchaseWorkflowService::class)->syncSupplierBalance($purchase);
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    protected function sequentialNumberColumn(): string
    {
        return 'purchase_number';
    }

    public static function nextPurchaseNumber(?Company $company = null): string
    {
        $company ??= app()->bound('company.context') ? app('company.context')->company() : null;
        $company ??= Company::defaultCompany();
        $prefix = $company?->invoice_prefix ?: 'PUR';

        do {
            $number = $prefix.'-PUR-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (self::query()
            ->when($company, fn ($query) => $query->where('company_id', $company->getKey()))
            ->where('purchase_number', $number)
            ->exists());

        return $number;
    }

    public function syncTotalsAndStock(): void
    {
        app(PurchaseWorkflowService::class)->sync($this);
    }

    public function chinaToBdCostTotal(): float
    {
        $fixedCosts = collect(self::CHINA_TO_BD_COST_FIELDS)
            ->keys()
            ->sum(fn (string $field): float => (float) ($this->{$field} ?? 0));

        return $fixedCosts + $this->customCostTotal();
    }

    public function customCostTotal(): float
    {
        return collect($this->custom_costs ?? [])
            ->sum(fn (array $cost): float => (float) ($cost['amount'] ?? 0));
    }

    public function customCostAmountFor(string $label): float
    {
        return collect($this->custom_costs ?? [])
            ->filter(fn (array $cost): bool => ($cost['label'] ?? null) === $label)
            ->sum(fn (array $cost): float => (float) ($cost['amount'] ?? 0));
    }

    public function customCostsSummary(): string
    {
        return collect($this->custom_costs ?? [])
            ->filter(fn (array $cost): bool => filled($cost['label'] ?? null))
            ->map(fn (array $cost): string => ($cost['label'] ?? '').': '.MoneyFormatter::currency((float) ($cost['amount'] ?? 0)))
            ->implode('; ') ?: '-';
    }

    public function landedCostTotal(): float
    {
        return (float) $this->subtotal + $this->chinaToBdCostTotal();
    }

    public function landedCostPerUnitSummary(): string
    {
        return $this->items()
            ->with('product')
            ->get()
            ->map(fn (PurchaseItem $item): string => ($item->product?->name ?? 'Product').': '.MoneyFormatter::currency((float) $item->landed_unit_cost))
            ->implode('; ') ?: '-';
    }

    public function syncSupplierBalance(): void
    {
        app(PurchaseWorkflowService::class)->syncSupplierBalance($this);
    }
}
