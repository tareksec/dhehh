<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\CompanyStorageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One Video Generation request and its result. Created with `status =
 * queued` by the VideoGeneration page, advanced by GenerateVideoJob through
 * an async submit -> poll -> poll -> ... state machine (12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md
 * §9). A sibling of GeneratedImage, not a `tool` discriminator on it — see
 * plan §3.1 for why the data model is separate.
 *
 * Company-owned (`BelongsToCompany` + `CompanyScope`); registered in
 * MultiCompanyIsolationTest per the CLAUDE.md contract.
 */
class GeneratedVideo extends Model
{
    use BelongsToCompany;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TOOL_VIDEO_GENERATION = 'video_generation';

    /** @var array<string, string> */
    public const CONTEXTS = [
        'product_demo' => 'Product Demo',
        'ad_creative' => 'Ad Creative',
        'landing_hero' => 'Landing / Offer Hero Video',
        'general' => 'General',
    ];

    public const OPERATION_IMAGE_TO_VIDEO = 'image_to_video';

    /** @var array<string, string> */
    public const OPERATIONS = [
        self::OPERATION_IMAGE_TO_VIDEO => 'Image-to-video',
    ];

    public const REVIEW_NOT_REQUIRED = 'not_required';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    /** @var array<string, string> */
    public const REVIEW_STATUSES = [
        self::REVIEW_NOT_REQUIRED => 'No review needed',
        self::REVIEW_PENDING => 'Pending review',
        self::REVIEW_APPROVED => 'Approved',
        self::REVIEW_REJECTED => 'Rejected',
    ];

    /**
     * The input-frame buckets Stability's image-to-video model accepts
     * (confirmed against its current API reference: 1024x576, 768x768, and
     * 576x1024 are the only three accepted input sizes). A future adapter
     * with looser constraints simply ignores buckets it does not need;
     * adapters snap the source image to their nearest supported option, the
     * same mechanism StabilityImageProvider already uses for images.
     *
     * @var array<string, array{label: string, size: string}>
     */
    public const ASPECT_RATIOS = [
        '16:9' => ['label' => 'Landscape · 16:9', 'size' => '1024x576'],
        '1:1' => ['label' => 'Square · 1:1', 'size' => '768x768'],
        '9:16' => ['label' => 'Story · 9:16', 'size' => '576x1024'],
    ];

    public const DEFAULT_ASPECT_RATIO = '16:9';

    /**
     * A small fixed preset menu (plan §2, §14(b)). Stability's current
     * image-to-video API has no duration parameter at all — it always
     * returns one short fixed-length clip — so these presets are inert until
     * a duration-capable adapter (a near-term follow-up, plan §8.4) ships.
     * The Custom adapter can still honor them if its endpoint supports it.
     *
     * @var array<string, array{label: string, seconds: int}>
     */
    public const DURATION_PRESETS = [
        'short' => ['label' => 'Short (~5s)', 'seconds' => 5],
        'standard' => ['label' => 'Standard (~10s)', 'seconds' => 10],
    ];

    public const DEFAULT_DURATION_PRESET = 'standard';

    protected $fillable = [
        'company_id',
        'user_id',
        'tool',
        'context',
        'operation',
        'original_prompt',
        'prompt',
        'source_image_path',
        'source_generated_image_id',
        'provider_profile_id',
        'provider_label',
        'api_format',
        'model',
        'aspect_ratio',
        'duration_preset',
        'duration_seconds',
        'external_job_id',
        'poll_attempts',
        'submitted_at',
        'next_poll_at',
        'poll_deadline_at',
        'video_path',
        'video_mime_type',
        'video_size_bytes',
        'thumbnail_path',
        'linked_type',
        'linked_id',
        'is_favorite',
        'estimated_cost',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'status',
        'error_message',
        'provider_response',
        'generated_at',
    ];

    protected $attributes = [
        'operation' => self::OPERATION_IMAGE_TO_VIDEO,
        'duration_preset' => self::DEFAULT_DURATION_PRESET,
        'review_status' => self::REVIEW_NOT_REQUIRED,
    ];

    protected $casts = [
        'poll_attempts' => 'integer',
        'duration_seconds' => 'integer',
        'video_size_bytes' => 'integer',
        'submitted_at' => 'datetime',
        'next_poll_at' => 'datetime',
        'poll_deadline_at' => 'datetime',
        'provider_response' => 'array',
        'is_favorite' => 'boolean',
        'estimated_cost' => 'decimal:4',
        'reviewed_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function linked(): MorphTo
    {
        return $this->morphTo();
    }

    /** The Image Generation output this video started from, when picked from the library. */
    public function sourceGeneratedImage(): BelongsTo
    {
        return $this->belongsTo(GeneratedImage::class);
    }

    /** Starred generations — the video library's favourites. */
    public function scopeFavorites(Builder $query): Builder
    {
        return $query->where('is_favorite', true);
    }

    /** A completed generation the library can play and reuse. */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /** Generations awaiting a reviewer's decision (Phase 5 approval workflow). */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('review_status', self::REVIEW_PENDING);
    }

    /** Rows created in the current calendar month — the usage-cap window. */
    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->startOfMonth());
    }

    /**
     * True when this video may be attached to a product / offer. A
     * generation a reviewer has not yet cleared (or has rejected) is held
     * back.
     */
    public function isAttachable(): bool
    {
        return in_array($this->review_status ?? self::REVIEW_NOT_REQUIRED, [self::REVIEW_NOT_REQUIRED, self::REVIEW_APPROVED], true);
    }

    public function reviewStatusLabel(): string
    {
        return self::REVIEW_STATUSES[$this->review_status] ?? self::REVIEW_STATUSES[self::REVIEW_NOT_REQUIRED];
    }

    public function operationLabel(): string
    {
        return self::OPERATIONS[$this->operation] ?? self::OPERATIONS[self::OPERATION_IMAGE_TO_VIDEO];
    }

    /**
     * Display name of the record this generation was attached to
     * (Product / Offer), or null if it was never attached.
     */
    public function linkedLabel(): ?string
    {
        $linked = $this->linked;

        return match (true) {
            $linked instanceof Product => $linked->name,
            $linked instanceof Offer => $linked->title,
            default => null,
        };
    }

    /** Public URL of the stored output, or null before it is ready. */
    public function outputUrl(): ?string
    {
        return app(CompanyStorageService::class)->publicUrl($this->video_path, $this->company);
    }

    /** Poster frame: the provider's own thumbnail if it returned one, else the source frame. */
    public function thumbnailUrl(): ?string
    {
        $path = $this->thumbnail_path ?: $this->source_image_path;

        return app(CompanyStorageService::class)->publicUrl($path, $this->company);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /**
     * True while this row is between submit and a completed/failed poll —
     * distinguishes "in-flight submit call" from "waiting on the provider"
     * without a dedicated status value (plan §6.2).
     */
    public function isPolling(): bool
    {
        return $this->status === self::STATUS_PROCESSING && filled($this->external_job_id);
    }

    public function contextLabel(): string
    {
        return self::CONTEXTS[$this->context] ?? self::CONTEXTS['general'];
    }

    public function durationLabel(): string
    {
        if ($this->duration_seconds !== null) {
            return $this->duration_seconds.'s';
        }

        return self::DURATION_PRESETS[$this->duration_preset]['label'] ?? self::DURATION_PRESETS[self::DEFAULT_DURATION_PRESET]['label'];
    }

    /** Pixel size for the current aspect ratio, for adapters that want "WxH". */
    public function pixelSize(): string
    {
        return self::ASPECT_RATIOS[$this->aspect_ratio]['size']
            ?? self::ASPECT_RATIOS[self::DEFAULT_ASPECT_RATIO]['size'];
    }
}
