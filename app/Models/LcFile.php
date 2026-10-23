<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\NumberToWords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The LC file (import file) of a purchase in the Trade Portal
 * (docs/tasneem-samples/PLAN.md §3.2): what the import papers need beyond the
 * purchase itself, the stage of the import and the bank's document status.
 * The purchase keeps its LC / PI / CI numbers and dates, ports, delivery
 * terms and country of origin; the supplier keeps its name and address; the
 * company keeps its addresses, TIN / BIN / IRC and signatory.
 *
 * missingFor() says, per paper, which fields are still empty, so a paper is
 * never printed with blanks (SUMMARY.md §5.7: the cover note no. and date
 * of a Shipment Advice are mandatory).
 */
class LcFile extends Model
{
    use BelongsToCompany;

    /** The stages of an import (SUMMARY.md §4 report 2), in order. */
    public const STAGES = [
        'pi_received' => 'PI received',
        'lc_opened' => 'LC opened',
        'under_inspection' => 'Under inspection',
        'on_vessel' => 'On vessel',
        'port_clearance' => 'Port clearance',
        'cleared' => 'Cleared',
    ];

    /** Where the shipping documents are at the bank (SUMMARY.md §4 report 2). */
    public const DOC_STATUSES = [
        'awaiting_docs' => 'Awaiting docs',
        'discrepancy_pending' => 'Discrepancy pending',
        'released' => 'Released',
    ];

    /** The papers made from an LC file: key => title. */
    public const DOCUMENTS = [
        'opening-letter' => 'LC Opening Letter',
        'beneficiary-certificate' => "Beneficiary's Certificate",
        'detail-packing-list' => 'Detail Packing List',
        'shipment-advice' => 'Shipment Advice',
        'drl' => 'Discrepancy Release Letter (DRL)',
    ];

    protected $fillable = [
        'company_id', 'purchase_id', 'stage', 'doc_status',
        'lc_value_usd', 'margin_percent', 'margin_amount_bdt', 'lc_bank', 'lc_account_name', 'lc_account_number', 'bank_branch', 'bank_bin',
        'insurer_name', 'insurer_address', 'cover_note_number', 'cover_note_date',
        'psi_agency', 'psi_date',
        'goods_description', 'goods_used', 'total_packages', 'net_weight_kg', 'gross_weight_kg', 'cbm',
        'carrier', 'vessel_name', 'port_of_shipment', 'place_of_delivery', 'etd', 'eta', 'bl_number', 'bl_date', 'container_number', 'seal_number',
        'opening_letter_date', 'certificate_date', 'shipment_advice_date', 'drl_date', 'note',
        'exchange_rate', 'landed_cost_applied_at',
    ];

    protected $casts = [
        'lc_value_usd' => 'decimal:2',
        'margin_percent' => 'decimal:2',
        'margin_amount_bdt' => 'decimal:2',
        'net_weight_kg' => 'decimal:3',
        'gross_weight_kg' => 'decimal:3',
        'cbm' => 'decimal:2',
        'goods_used' => 'boolean',
        'cover_note_date' => 'date',
        'psi_date' => 'date',
        'etd' => 'date',
        'eta' => 'date',
        'bl_date' => 'date',
        'opening_letter_date' => 'date',
        'certificate_date' => 'date',
        'shipment_advice_date' => 'date',
        'drl_date' => 'date',
        'exchange_rate' => 'decimal:4',
        'landed_cost_applied_at' => 'datetime',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** The costs of this import beyond the goods (the landed cost worksheet). */
    public function landedCostLines(): HasMany
    {
        return $this->hasMany(LandedCostLine::class)->orderBy('id');
    }

    public static function stageLabel(?string $stage): string
    {
        return __(self::STAGES[$stage] ?? (string) $stage);
    }

    public static function docStatusLabel(?string $status): string
    {
        return __(self::DOC_STATUSES[$status] ?? (string) $status);
    }

    /** The next stage, or null at the last one. */
    public function nextStage(): ?string
    {
        $keys = array_keys(self::STAGES);
        $at = array_search($this->stage, $keys, true);

        return $at === false ? $keys[0] : ($keys[$at + 1] ?? null);
    }

    /** The LC value: as entered, else the purchase's CFR total (FOB + freight) when it has one. */
    public function lcValue(): float
    {
        if ($this->lc_value_usd !== null && (float) $this->lc_value_usd > 0) {
            return (float) $this->lc_value_usd;
        }

        return round((float) ($this->purchase?->cfrTotalUsd() ?? 0), 2);
    }

    /** The margin in USD (value × margin %). */
    public function marginUsd(): float
    {
        return round($this->lcValue() * (float) $this->margin_percent / 100, 2);
    }

    /** "US Dollars Thirty Five Thousand Six Hundred Only", as written in the LC Opening Letter. */
    public function lcValueInWords(): string
    {
        $value = round($this->lcValue(), 2);
        $whole = (int) floor($value);
        $cents = (int) round(($value - $whole) * 100);

        return 'US Dollars '.NumberToWords::convert($whole).($cents > 0 ? ' And '.NumberToWords::convert($cents).' Cents' : '').' Only';
    }

    /** This shipment's net weight: as entered, else the sum of the purchase lines. */
    public function netWeight(): float
    {
        return $this->net_weight_kg !== null ? (float) $this->net_weight_kg : (float) ($this->purchase?->netWeightTotalKg() ?? 0);
    }

    public function grossWeight(): float
    {
        return $this->gross_weight_kg !== null ? (float) $this->gross_weight_kg : (float) ($this->purchase?->grossWeightTotalKg() ?? 0);
    }

    /** The goods as the papers describe them: as entered, else the purchase lines' names. */
    public function goodsDescription(): string
    {
        if (filled($this->goods_description)) {
            return (string) $this->goods_description;
        }

        return (string) ($this->purchase?->items->map(fn (PurchaseItem $item): string => trim(($item->product?->name ?? '').' '.($item->spec_note ?? '')))->filter()->implode('; ') ?? '');
    }

    /**
     * The fields still empty for one paper, as English labels (translated where shown).
     *
     * @return list<string>
     */
    public function missingFor(string $document): array
    {
        $purchase = $this->purchase;
        $supplier = $purchase?->supplier;
        $company = $purchase?->company;

        $checks = [
            'lc_bank' => ['LC opening bank', fn (): bool => filled($this->lc_bank)],
            'pi' => ['PI no. and date', fn (): bool => filled($purchase?->pi_number) && $purchase?->pi_date !== null],
            'lc' => ['LC no. and date', fn (): bool => filled($purchase?->lc_number) && $purchase?->lc_date !== null],
            'ci' => ['Commercial Invoice no. and date', fn (): bool => filled($purchase?->ci_number) && $purchase?->ci_date !== null],
            'value' => ['LC value (USD)', fn (): bool => $this->lcValue() > 0],
            'margin' => ['Margin %', fn (): bool => $this->margin_percent !== null],
            'account' => ['Account name and number', fn (): bool => filled($this->lc_account_name) && filled($this->lc_account_number)],
            'letter_date' => ['Opening letter date', fn (): bool => $this->opening_letter_date !== null],
            'supplier' => ['Supplier name and address', fn (): bool => filled($supplier?->company_name ?: $supplier?->name) && filled($supplier?->address)],
            'goods' => ['Description of goods', fn (): bool => $this->goodsDescription() !== ''],
            'weights' => ['Net and gross weight of this shipment', fn (): bool => $this->netWeight() > 0 && $this->grossWeight() > 0],
            'terms' => ['Delivery terms', fn (): bool => filled($purchase?->delivery_terms)],
            'ports' => ['Port of loading and port of discharge', fn (): bool => filled($purchase?->port_of_loading) && filled($purchase?->port_of_discharge)],
            'origin' => ['Country of origin', fn (): bool => filled($purchase?->country_of_origin)],
            'ids' => ['Your TIN, BIN and IRC (Business profile)', fn (): bool => filled($company?->tin_number) && filled($company?->bin_number) && filled($company?->irc_number)],
            'bank_office' => ["Bank's trade office and its BIN", fn (): bool => filled($this->bank_branch) && filled($this->bank_bin)],
            'certificate_date' => ['Date of the certificate and packing list', fn (): bool => $this->certificate_date !== null],
            'container' => ['Container no., seal no. and packages', fn (): bool => filled($this->container_number) && filled($this->seal_number) && filled($this->total_packages)],
            'carrier' => ['Carrier', fn (): bool => filled($this->carrier)],
            'cover_note' => ['Insurance cover note no. and date', fn (): bool => filled($this->cover_note_number) && $this->cover_note_date !== null],
        ];

        $needs = match ($document) {
            'opening-letter' => ['lc_bank', 'pi', 'value', 'margin', 'supplier', 'account', 'letter_date'],
            'drl' => ['lc_bank', 'lc', 'value'],
            'beneficiary-certificate' => ['supplier', 'goods', 'weights', 'pi', 'terms', 'lc', 'ids', 'bank_office', 'origin', 'certificate_date'],
            'detail-packing-list' => ['supplier', 'ci', 'lc', 'ids', 'goods', 'weights', 'certificate_date'],
            'shipment-advice' => ['supplier', 'bank_office', 'goods', 'weights', 'container', 'pi', 'terms', 'lc', 'ids', 'origin', 'ports', 'carrier', 'cover_note'],
            default => [],
        };

        $missing = [];

        foreach ($needs as $key) {
            [$label, $isFilled] = $checks[$key];

            if (! $isFilled()) {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
