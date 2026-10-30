<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\CompanyStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/** A file attached to a social post (19_SOCIAL_CONTENT_PLANNER_PLAN.md §7.2). */
class SocialPostMedia extends Model
{
    use BelongsToCompany;

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    protected $table = 'social_post_media';

    protected $fillable = [
        'company_id',
        'social_post_id',
        'sort_order',
        'kind',
        'path',
        'generated_image_id',
        'generated_video_id',
        'mime',
        'bytes',
        'sha256',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        // A file always belongs to the post's own company, whatever company
        // the signed-in user is looking at right now.
        static::creating(function (SocialPostMedia $media): void {
            $companyId = SocialPost::withoutGlobalScopes()
                ->whereKey($media->social_post_id)
                ->value('company_id');

            if ($companyId) {
                $media->company_id = $companyId;
            }
        });

        // Fill what we know about the file so the approval fingerprint and the
        // "same file again" check have something firm to compare.
        static::saving(function (SocialPostMedia $media): void {
            if (! $media->isDirty('path') || blank($media->path)) {
                return;
            }

            try {
                $bytes = app(CompanyStorageService::class)->readPublic($media->path, $media->company ?? null);
            } catch (Throwable) {
                $bytes = null;
            }

            if (! is_string($bytes) || $bytes === '') {
                return;
            }

            $media->sha256 = hash('sha256', $bytes);
            $media->bytes = strlen($bytes);
            $media->mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: null;
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }
}
