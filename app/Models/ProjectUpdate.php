<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * News about a project for its investors (IMS-V2/PRD.md FR-30): text, up to
 * twelve photos, a video link. Investors see it only once "Show to
 * investors" is ticked.
 */
class ProjectUpdate extends Model
{
    use BelongsToCompany;

    public const MAX_PHOTOS = 12;

    protected $fillable = ['company_id', 'project_id', 'title', 'body', 'video_url', 'visible_to_investors', 'published_at', 'created_by'];

    protected $casts = ['visible_to_investors' => 'boolean', 'published_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $update): void {
            if ($update->visible_to_investors && $update->published_at === null) {
                $update->published_at = now();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ProjectUpdatePhoto::class, 'update_id')->orderBy('sort');
    }

    public function scopeVisible($query)
    {
        return $query->where('visible_to_investors', true);
    }
}
