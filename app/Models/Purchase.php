<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use App\Services\CompanySettingsService;
use App\Services\PurchaseWorkflowService;
use App\Support\MoneyFormatter;
use App\Support\NumberToWords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'pl_number',
        'pl_date',
        'delivery_terms',
        'country_of_origin',
        'port_of_loading',
        'port_of_discharge',
        'payment_method_summary',
        'terms_conditions',
        'pl_certification_note',
        'freight_usd',
        'exchange_rate',
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
        'pl_date' => 'date',
        'freight_usd' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
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

    /** The Trade Portal's LC file of this purchase (bank, insurance, shipment details for the import papers). */
    public function lcFile(): HasOne
    {
        return $this->hasOne(LcFile::class);
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

    /**
     * FOB value of the goods themselves in USD (quantity × per-unit FOB
     * price), as printed on the PI/CI item tables. Purely informational for
     * these trade documents — does not feed the BDT landed-cost engine.
     */
    public function fobTotalUsd(): float
    {
        return (float) $this->items->sum(
            fn (PurchaseItem $item): float => (int) $item->quantity * (float) ($item->fob_unit_price_usd ?? 0)
        );
    }

    /**
     * FOB value plus the international freight (USD), i.e. the CFR/CNF
     * total shown on the Commercial Invoice cost breakdown.
     */
    public function cfrTotalUsd(): float
    {
        return $this->fobTotalUsd() + (float) ($this->freight_usd ?? 0);
    }

    public function totalQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function netWeightTotalKg(): float
    {
        return (float) $this->items->sum('net_weight_kg');
    }

    public function grossWeightTotalKg(): float
    {
        return (float) $this->items->sum('gross_weight_kg');
    }

    public function cfrAmountInWords(): string
    {
        return NumberToWords::amountInWords($this->cfrTotalUsd());
    }

    public function fobAmountInWords(): string
    {
        return NumberToWords::amountInWords($this->fobTotalUsd());
    }

    /**
     * Whether the purchase has the shipping/trade details (ports, delivery
     * terms) needed to generate the Commercial Invoice and Packing List —
     * the "shipping details added" gate for those two Generate actions.
     * The Proforma Invoice does not require this, since it is generated at
     * order time, before shipping is arranged.
     */
    public function hasShippingDetailsForDocuments(): bool
    {
        return filled($this->delivery_terms) && filled($this->port_of_loading) && filled($this->port_of_discharge);
    }

    /**
     * PI's "Payment Terms" / CI's "Terms & Conditions" text. Uses this
     * purchase's own override when set, otherwise falls back to the
     * company-wide default from Company Settings (Purchase Documents).
     */
    public function termsConditionsText(): string
    {
        if (filled($this->terms_conditions)) {
            return $this->terms_conditions;
        }

        return app(CompanySettingsService::class)->purchaseDocuments($this->company)['ci_terms_conditions'] ?? '';
    }

    public function paymentTermsText(): string
    {
        if (filled($this->terms_conditions)) {
            return $this->terms_conditions;
        }

        return app(CompanySettingsService::class)->purchaseDocuments($this->company)['pi_payment_terms'] ?? '';
    }

    /**
     * PL's certification paragraph. Uses this purchase's own override when
     * set, otherwise the company default template with {country_of_origin},
     * {pi_number}, and {pi_date} tokens substituted from this purchase's
     * own data.
     */
    public function plCertificationNoteText(): string
    {
        if (filled($this->pl_certification_note)) {
            return $this->pl_certification_note;
        }

        $template = app(CompanySettingsService::class)->purchaseDocuments($this->company)['pl_certification_note'] ?? '';

        return strtr($template, [
            '{country_of_origin}' => $this->country_of_origin ?: '-',
            '{pi_number}' => $this->pi_number ?: '-',
            '{pi_date}' => $this->pi_date?->format('d M Y') ?: '-',
        ]);
    }

    public function syncSupplierBalance(): void
    {
        app(PurchaseWorkflowService::class)->syncSupplierBalance($this);
    }
}
