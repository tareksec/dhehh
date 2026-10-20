<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ProjectCostItem extends Model
{
    use BelongsToCompany;

    public const CATEGORIES = ['landed_cost' => 'Landed Cost', 'local_expense' => 'Local Expense'];

    protected $fillable = ['company_id', 'project_id', 'category', 'label', 'amount', 'purchase_id', 'remarks'];

    protected $casts = ['amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($project = InvestmentProject::query()->find($item->project_id)) {
                $item->company_id = $project->company_id;
                static::refuseWhenFrozen($project);
            }
        });

        static::deleting(function (self $item): void {
            if ($project = InvestmentProject::query()->find($item->project_id)) {
                static::refuseWhenFrozen($project);
            }
        });
    }

    /** IMS v2 (PRD FR-47): the costs of a fully paid cycle are frozen. */
    protected static function refuseWhenFrozen(InvestmentProject $project): void
    {
        if ($project->isFrozen()) {
            throw ValidationException::withMessages(['amount' => __('Every payout of this project is paid, so its costs are frozen and cannot change.')]);
        }
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
