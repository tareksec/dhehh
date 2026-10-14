<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

/**
 * The unit investors join (16_INVESTOR_GROUPS_PLAN.md). A group is Open
 * (members can be added), Running (membership frozen; a new project starts
 * every `cycle_months` calendar months from `running_since`) or Closed.
 * Status changes go through `InvestorGroupService`.
 */
class InvestorGroup extends Model
{
    use BelongsToCompany;

    public const STATUS_OPEN = 'open';

    public const STATUS_RUNNING = 'running';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_RUNNING => 'Running',
        self::STATUS_CLOSED => 'Closed',
    ];

    /** The only status moves a group may make: Open → Running → Closed, or Open → Closed. */
    private const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_RUNNING, self::STATUS_CLOSED],
        self::STATUS_RUNNING => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
    ];

    protected $fillable = [
        'company_id', 'group_code', 'name', 'status', 'opened_on', 'running_since', 'closed_on', 'close_reason',
        'cycle_months', 'auto_cycle', 'investor_share_percent', 'channel_partner_share_percent', 'company_share_percent',
        'receiving_fund_source_id', 'payout_fund_source_id', 'notes', 'created_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'cycle_months' => 2,
        'auto_cycle' => false,
        'investor_share_percent' => 40,
        'channel_partner_share_percent' => 10,
        'company_share_percent' => 50,
    ];

    protected $casts = [
        'opened_on' => 'date',
        'running_since' => 'date',
        'closed_on' => 'date',
        'cycle_months' => 'integer',
        'auto_cycle' => 'boolean',
        'investor_share_percent' => 'decimal:2',
        'channel_partner_share_percent' => 'decimal:2',
        'company_share_percent' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            $group->group_code ??= static::nextGroupCode((int) $group->company_id);
            $group->name = filled($group->name) ? $group->name : 'Group-'.preg_replace('/\D/', '', (string) $group->group_code);
            $group->opened_on ??= now()->toDateString();
        });

        static::saving(function (self $group): void {
            $sum = (float) $group->investor_share_percent + (float) $group->channel_partner_share_percent + (float) $group->company_share_percent;

            if (abs($sum - 100) > 0.001) {
                throw ValidationException::withMessages(['investor_share_percent' => __('Investor, channel partner, and company shares must total exactly 100%.')]);
            }

            if ((int) $group->cycle_months < 1 || (int) $group->cycle_months > 12) {
                throw ValidationException::withMessages(['cycle_months' => __('A cycle is between 1 and 12 months.')]);
            }

            if ($group->auto_cycle && $group->status !== self::STATUS_RUNNING) {
                throw ValidationException::withMessages(['auto_cycle' => __('Automatic cycles can be on only while the group is Running.')]);
            }

            if (! $group->exists) {
                return;
            }

            if ($group->isDirty('status')) {
                $from = (string) $group->getOriginal('status');

                if (! in_array($group->status, self::TRANSITIONS[$from] ?? [], true)) {
                    throw ValidationException::withMessages(['status' => __('A group cannot go from :from to :to.', [
                        'from' => __(self::STATUSES[$from] ?? $from),
                        'to' => __(self::STATUSES[$group->status] ?? $group->status),
                    ])]);
                }
            }

            // Once automatic cycles are on for a running group, its calendar is
            // fixed: moving the anchor or the cycle length would shift every
            // future project date. (Before that, staff can still correct them.)
            if ($group->getOriginal('status') === self::STATUS_RUNNING
                && $group->getOriginal('auto_cycle')
                && $group->isDirty(['running_since', 'cycle_months'])) {
                throw ValidationException::withMessages(['running_since' => __('The start date and cycle length cannot change while automatic cycles are on.')]);
            }
        });

        static::deleting(function (self $group): void {
            if ($group->members()->exists() || $group->projects()->exists()) {
                throw ValidationException::withMessages(['status' => __('A group with members or projects is kept as a record and cannot be deleted.')]);
            }
        });
    }

    /** Next free `G<number>` for the company. */
    public static function nextGroupCode(int $companyId): string
    {
        $highest = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('group_code')
            ->map(fn (string $code): int => (int) preg_replace('/\D/', '', $code))
            ->max() ?? 0;

        return 'G'.($highest + 1);
    }

    public function members(): HasMany
    {
        return $this->hasMany(InvestorGroupMember::class, 'group_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', InvestorGroupMember::STATUS_ACTIVE);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(InvestmentProject::class, 'group_id')->orderBy('sequence_in_group');
    }

    /** The project that is collecting or running now (highest sequence not yet closed). */
    public function currentProject(): HasOne
    {
        return $this->hasOne(InvestmentProject::class, 'group_id')
            ->whereIn('status', ['open', 'running'])
            ->orderByDesc('sequence_in_group');
    }

    public function latestProject(): HasOne
    {
        return $this->hasOne(InvestmentProject::class, 'group_id')->orderByDesc('sequence_in_group');
    }

    public function receivingFundSource(): BelongsTo
    {
        return $this->belongsTo(FundSource::class, 'receiving_fund_source_id');
    }

    public function payoutFundSource(): BelongsTo
    {
        return $this->belongsTo(FundSource::class, 'payout_fund_source_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /** Sum of the members who are still in the group. */
    public function totalActiveDeposits(): float
    {
        return round((float) $this->activeMembers()->sum('amount'), 2);
    }
}
