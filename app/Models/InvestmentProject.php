<?php

namespace App\Models;

use App\Jobs\SendInvestorProjectStatusSmsJob;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\GeneratesSequentialNumber;
use App\Services\InvestorProjectMessageService;
use App\Services\SmsTemplateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvestmentProject extends Model
{
    use BelongsToCompany, GeneratesSequentialNumber;

    public const DURATIONS = ['2_month' => '2 months', '6_month' => '6 months', '12_month' => '12 months', 'custom_days' => 'Custom days'];

    public const STATUSES = ['open' => 'Open', 'running' => 'Running', 'closed' => 'Closed', 'settled' => 'Settled'];

    protected $fillable = ['company_id', 'group_id', 'sequence_in_group', 'project_code', 'name', 'description', 'deal_reference', 'purchase_id', 'duration_type', 'trade_cycle_days', 'start_date', 'end_date', 'investment_opens_at', 'investment_closes_at', 'target_amount', 'company_contribution_amount', 'company_contribution_note', 'receiving_fund_source_id', 'payout_fund_source_id', 'investor_share_percent', 'channel_partner_share_percent', 'company_share_percent', 'status'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'investment_opens_at' => 'date', 'investment_closes_at' => 'date', 'target_amount' => 'decimal:2', 'company_contribution_amount' => 'decimal:2', 'investor_share_percent' => 'decimal:2', 'channel_partner_share_percent' => 'decimal:2', 'company_share_percent' => 'decimal:2', 'trade_cycle_days' => 'integer'];

    protected static function booted(): void
    {
        static::saving(function (self $project): void {
            // Where a project sits in its group never moves once set (the
            // backfill sets it once, from empty).
            if ($project->exists && $project->isDirty(['group_id', 'sequence_in_group'])
                && (filled($project->getOriginal('group_id')) || filled($project->getOriginal('sequence_in_group')))) {
                throw ValidationException::withMessages(['group_id' => __('A project cannot be moved to another group or position.')]);
            }

            $sum = (float) $project->investor_share_percent + (float) $project->channel_partner_share_percent + (float) $project->company_share_percent;

            if (abs($sum - 100) > 0.001) {
                throw ValidationException::withMessages(['investor_share_percent' => 'Investor, channel partner, and company shares must total exactly 100%.']);
            }

            if ($project->exists && $project->isDirty('status') && $project->status === 'settled' && ! $project->settlement()->exists()) {
                throw ValidationException::withMessages(['status' => 'A project can only become settled through Calculate & Settle.']);
            }

            $project->trade_cycle_days = match ($project->duration_type) {
                '2_month' => 60, '6_month' => 180, '12_month' => 365,
                default => $project->trade_cycle_days,
            };
        });

        static::creating(function (self $project): void {
            $project->project_code ??= static::groupProjectCode($project) ?? static::nextProjectCode((int) $project->company_id);
        });

        // The investors' status SMS (SMS Templates) for a project outside a group; a group's projects
        // are texted by the group cycle, and "settled" by the settlement's confirmation.
        static::updated(function (self $project): void {
            $key = match ($project->status) {
                'running' => SmsTemplateService::PROJECT_RUNNING,
                'closed' => SmsTemplateService::PROJECT_CLOSED,
                default => null,
            };

            if ($key === null || filled($project->group_id) || ! $project->wasChanged('status') || ! Schema::hasTable('sms_templates')) {
                return;
            }

            try {
                if (app(InvestorProjectMessageService::class)->configured($project, $key)) {
                    SendInvestorProjectStatusSmsJob::dispatch($project->getKey(), $key)->afterCommit();
                }
            } catch (Throwable $exception) {
                // A problem with the SMS set-up never blocks a status change.
                report($exception);
            }
        });
    }

    public static function nextProjectCode(int $companyId): string
    {
        $year = now()->year;
        $sequence = static::withoutGlobalScopes()->where('company_id', $companyId)->whereYear('created_at', $year)->count() + 1;
        $number = str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);

        return "{$number}/{$year}-INV-{$number}";
    }

    /** `G1-P03`: the group's code and the project's position in it (null for an ungrouped project). */
    protected static function groupProjectCode(self $project): ?string
    {
        if (! $project->group_id || ! $project->sequence_in_group) {
            return null;
        }

        $groupCode = InvestorGroup::withoutGlobalScopes()->whereKey($project->group_id)->value('group_code');

        return $groupCode ? $groupCode.'-P'.str_pad((string) $project->sequence_in_group, 2, '0', STR_PAD_LEFT) : null;
    }

    protected function sequentialNumberColumn(): string
    {
        return 'project_code';
    }

    public function group()
    {
        return $this->belongsTo(InvestorGroup::class, 'group_id');
    }

    public function isGrouped(): bool
    {
        return filled($this->group_id);
    }

    /** Projects that are not part of a group (the old one-project-at-a-time way). */
    public function scopeUngrouped($query)
    {
        return $query->whereNull('group_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function investments()
    {
        return $this->hasMany(Investment::class, 'project_id');
    }

    public function costItems()
    {
        return $this->hasMany(ProjectCostItem::class, 'project_id');
    }

    public function settlement()
    {
        return $this->hasOne(ProjectSettlement::class, 'project_id');
    }

    public function documents()
    {
        return $this->morphMany(InvestmentDocument::class, 'documentable');
    }

    /** Where incoming investor capital is booked as a voucher (v3 P2.5). */
    public function receivingFundSource()
    {
        return $this->belongsTo(FundSource::class, 'receiving_fund_source_id');
    }

    /** Where outgoing investor/channel-partner payouts are booked as a voucher (v3 P2.5). */
    public function payoutFundSource()
    {
        return $this->belongsTo(FundSource::class, 'payout_fund_source_id');
    }

    public function totalInvested(): float
    {
        return (float) $this->investments()->sum('amount');
    }

    /**
     * Whether a *new* investment can be added right now without the
     * `investments.override_investment_window` permission (v3 P2.3). A
     * project that never set a window (both columns null) is never
     * restricted -- this feature is opt-in, per project.
     */
    public function isWithinInvestmentWindow(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }

        return ! $this->investment_closes_at || now()->toDateString() <= $this->investment_closes_at->toDateString();
    }

    /**
     * Short label for a project-list/view badge. Null when no window is
     * configured for this project at all.
     */
    public function investmentWindowLabel(): ?string
    {
        if (! $this->investment_opens_at && ! $this->investment_closes_at) {
            return null;
        }

        if (! $this->isWithinInvestmentWindow()) {
            return 'Window closed';
        }

        if ($this->investment_closes_at) {
            $daysLeft = now()->startOfDay()->diffInDays($this->investment_closes_at->copy()->startOfDay(), false);

            return $daysLeft <= 0 ? 'Closes today' : "Closes in {$daysLeft} day".($daysLeft === 1 ? '' : 's');
        }

        return 'Investment open';
    }

    /**
     * Capital the profit pool and the "rate per lac" are spread over: the
     * external investors plus whatever the company itself put in to close a
     * funding gap (v3 P1.7). The company's slice of the investor pool then
     * folds back into company_net at settlement.
     */
    public function totalCapitalBase(): float
    {
        return $this->totalInvested() + (float) $this->company_contribution_amount;
    }

    /**
     * Capital base minus landed cost, when both are known and differ by more
     * than ৳1. The investor's profit per lakh is spread over the capital base,
     * so when the base is not the landed cost the figure will not match a
     * profit-per-lakh worked out on the landed cost (e.g. on the supplier
     * invoice). A warning for staff before settling; never a block.
     */
    public function capitalBaseGap(): ?float
    {
        $landed = $this->totalLandedCost();

        if ($landed <= 0) {
            return null;
        }

        $gap = round($this->totalCapitalBase() - $landed, 2);

        return abs($gap) > 1 ? $gap : null;
    }

    public function totalCostItems(): float
    {
        return (float) $this->costItems()->sum('amount');
    }

    public function totalLandedCost(): float
    {
        return (float) $this->costItems()->where('category', 'landed_cost')->sum('amount');
    }

    public function totalLocalExpense(): float
    {
        return (float) $this->costItems()->where('category', 'local_expense')->sum('amount');
    }
}
