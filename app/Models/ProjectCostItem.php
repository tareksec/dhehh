<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ProjectCostItem extends Model
{
    use BelongsToCompany;

    public const CATEGORIES = ['landed_cost' => 'Landed Cost', 'local_expense' => 'Local Expense'];

    protected $fillable = ['company_id', 'project_id', 'category', 'label', 'amount', 'currency', 'original_amount', 'exchange_rate', 'cost_date', 'purchase_id', 'remarks'];

    protected $casts = ['amount' => 'decimal:2', 'original_amount' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'cost_date' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($project = InvestmentProject::query()->find($item->project_id)) {
                $item->company_id = $project->company_id;
            }

            // 17_IMS_IN_PORTAL_PLAN.md phase R: `amount` is always the BDT figure.
            // A cost entered in USD or RMB carries its original amount and rate,
            // and the BDT amount is worked out here, never taken from the form.
            if (blank($item->currency) || $item->currency === ExchangeRate::BASE) {
                $item->currency = null;
                $item->original_amount = null;
                $item->exchange_rate = null;

                return;
            }

            if (! array_key_exists((string) $item->currency, ExchangeRate::CURRENCIES)) {
                throw ValidationException::withMessages(['currency' => __('Choose a currency from the list.')]);
            }

            if ((float) $item->original_amount <= 0 || (float) $item->exchange_rate <= 0) {
                throw ValidationException::withMessages(['original_amount' => __('A cost in :currency needs the original amount and the rate.', ['currency' => $item->currency])]);
            }

            $item->amount = round((float) $item->original_amount * (float) $item->exchange_rate, 2);
        });
    }

    /** True when the cost was entered in USD or RMB and converted with a rate. */
    public function isForeign(): bool
    {
        return filled($this->currency) && $this->currency !== ExchangeRate::BASE;
    }

    public function project()
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function documents()
    {
        return $this->morphMany(InvestmentDocument::class, 'documentable');
    }

    /**
     * Transparency rule (deed clause 3 / channel-partner agreement clause 2):
     * every cost line must be traceable — either linked to a Purchase record
     * or backed by an uploaded receipt/invoice.
     */
    public function hasProof(): bool
    {
        return filled($this->purchase_id) || $this->documents()->exists();
    }
}
