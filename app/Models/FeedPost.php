<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company post in the investors' feed (IMS-V2/PRD.md FR-63): text, news
 * or a link, up to six pictures, can be pinned. Investors like and comment;
 * the office sees who saw, liked and commented.
 */
class FeedPost extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const MAX_IMAGES = 6;

    public const KINDS = ['post' => 'Post', 'news' => 'News', 'link' => 'Link'];

    protected $fillable = ['company_id', 'kind', 'title', 'body', 'link_url', 'is_pinned', 'comments_enabled', 'published_at', 'created_by'];

    protected $casts = ['is_pinned' => 'boolean', 'comments_enabled' => 'boolean', 'published_at' => 'datetime'];

    public function images(): HasMany
    {
        return $this->hasMany(FeedPostImage::class, 'post_id')->orderBy('sort');
    }

    public function views(): HasMany
    {
        return $this->hasMany(FeedPostView::class, 'post_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(FeedPostLike::class, 'post_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FeedComment::class, 'post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
