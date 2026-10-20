<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * A project report for investors (IMS-V2/PRD.md FR-36). Drafted by staff;
 * publishing freezes its figures (`summary`) and it can never be changed or
 * deleted after that.
 */
class ProjectReport extends Model
{
    use BelongsToCompany;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = ['company_id', 'project_id', 'title', 'period_start', 'period_end', 'notes', 'status', 'summary', 'published_at', 'published_by', 'created_by'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'summary' => 'array', 'published_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected static function booted(): void
    {
        static::updating(function (self $report): void {
            if ($report->getOriginal('status') === self::STATUS_PUBLISHED) {
                throw ValidationException::withMessages(['status' => __('A published report cannot be changed.')]);
            }
        });

        static::deleting(function (self $report): void {
            if ($report->status === self::STATUS_PUBLISHED) {
                throw ValidationException::withMessages(['status' => __('A published report cannot be deleted.')]);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
