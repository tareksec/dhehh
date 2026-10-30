<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One organic social post for a company (19_SOCIAL_CONTENT_PLANNER_PLAN.md §7).
 *
 * Nothing goes out unless `approval_state` is approved AND the content still
 * matches what was approved (`approved_fingerprint`). `publish_progress` is the
 * private per-platform checkpoint the publish job keeps; it is hidden from
 * serialization and from the audit log, and is never shown in a page.
 */
class SocialPost extends Model
{
    use BelongsToCompany;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_POSTED = 'posted';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_ARCHIVED = 'archived';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const PUBLISH_IDLE = 'idle';

    public const PUBLISH_QUEUED = 'queued';

    public const PUBLISH_RUNNING = 'running';

    public const PUBLISH_SUCCEEDED = 'succeeded';

    public const PUBLISH_FAILED = 'failed';

    public const PLATFORM_FACEBOOK = 'facebook';

    public const PLATFORM_INSTAGRAM = 'instagram';

    /** Platforms the publish path serves today. TikTok and YouTube come in Phase 4. */
    public const PLATFORMS = [
        self::PLATFORM_FACEBOOK => 'Facebook Page',
        self::PLATFORM_INSTAGRAM => 'Instagram',
    ];

    public const LANGUAGES = [
        'bn' => 'Bangla',
        'en' => 'English',
    ];

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_READY => 'Waiting approval',
        self::STATUS_SCHEDULED => 'Scheduled',
        self::STATUS_POSTED => 'Posted',
        self::STATUS_BLOCKED => 'Blocked',
        self::STATUS_ARCHIVED => 'Archived',
    ];

    protected $fillable = [
        'company_id',
        'number',
        'title',
        'body',
        'language',
        'platforms',
        'platform_options',
        'scheduled_at',
        'status',
        'approval_state',
        'approved_by_user_id',
        'approved_at',
        'approved_fingerprint',
        'publish_state',
        'publish_error',
        'publish_progress',
        'publish_result',
        'lease_token',
        'lease_expires_at',
        'source_type',
        'source_id',
        'is_ai_drafted',
        'created_by_user_id',
    ];

    /** The checkpoint and the lease are private to the publish job. */
    protected $hidden = [
        'publish_progress',
        'lease_token',
    ];

    protected $casts = [
        'platforms' => 'array',
        'platform_options' => 'array',
        'scheduled_at' => 'datetime',
        'approved_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'publish_progress' => 'array',
        'publish_result' => 'array',
        'is_ai_drafted' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (SocialPost $post): void {
            if (blank($post->number)) {
                $post->number = ((int) static::withoutGlobalScopes()
                    ->where('company_id', $post->company_id)
                    ->max('number')) + 1;
            }
        });
    }

    public function media(): HasMany
    {
        return $this->hasMany(SocialPostMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function getHumanIdAttribute(): string
    {
        return 'S-'.$this->number;
    }

    /**
     * What an approval covers: the words, the language, the platforms, their
     * options and the attached files. A change to any of it voids the approval.
     */
    public function contentFingerprint(): string
    {
        $platforms = array_values((array) $this->platforms);
        sort($platforms);

        return hash('sha256', json_encode([
            'title' => (string) $this->title,
            'body' => (string) $this->body,
            'language' => (string) $this->language,
            'platforms' => $platforms,
            'options' => (array) $this->platform_options,
            'media' => $this->media()->get()->map(fn (SocialPostMedia $media): array => [
                $media->kind,
                $media->sha256 ?: $media->path,
            ])->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function isApproved(): bool
    {
        return $this->approval_state === self::APPROVAL_APPROVED
            && filled($this->approved_fingerprint)
            && hash_equals((string) $this->approved_fingerprint, $this->contentFingerprint());
    }

    /** True when it was approved but has been changed since. */
    public function approvalIsStale(): bool
    {
        return $this->approval_state === self::APPROVAL_APPROVED && ! $this->isApproved();
    }

    /** A post that is queued, running or has an uncertain platform cannot be edited. */
    public function isLocked(): bool
    {
        return in_array($this->publish_state, [self::PUBLISH_QUEUED, self::PUBLISH_RUNNING], true)
            || $this->hasUncertainPlatform();
    }

    public function hasUncertainPlatform(): bool
    {
        foreach ((array) $this->publish_progress as $progress) {
            if (($progress['state'] ?? null) === 'uncertain') {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function platformList(): array
    {
        return array_values(array_intersect(array_keys(self::PLATFORMS), (array) $this->platforms));
    }

    /** Where the schedule time is typed and shown: the company's timezone. */
    public function timezone(): string
    {
        return (string) ($this->company?->timezone ?: config('app.timezone', 'UTC'));
    }
}
