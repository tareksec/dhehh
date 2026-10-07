<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StorefrontPage extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'storefront_setting_id',
        'title',
        'slug',
        'excerpt',
        'cover_image',
        'content',
        'meta_title',
        'meta_description',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (StorefrontPage $page): void {
            $page->slug = static::normalizeSlug($page->slug ?: $page->title);
            $page->sort_order ??= 0;
            $page->is_published ??= false;
        });

        static::saving(function (StorefrontPage $page): void {
            $page->slug = static::normalizeSlug($page->slug ?: $page->title);
        });
    }

    /** The site this page is limited to; null means it shows on every site of the company. */
    public function storefrontSetting(): BelongsTo
    {
        return $this->belongsTo(StorefrontSetting::class)->withoutGlobalScopes();
    }

    /** Pages shown on $site: those for all sites plus those limited to this one. */
    public function scopeForSite(Builder $query, ?StorefrontSetting $site): Builder
    {
        return $query->where(function (Builder $q) use ($site): void {
            $q->whereNull($this->qualifyColumn('storefront_setting_id'));

            if ($site?->getKey()) {
                $q->orWhere($this->qualifyColumn('storefront_setting_id'), $site->getKey());
            }
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public static function normalizeSlug(?string $slug): string
    {
        return Str::of($slug ?: 'page')
            ->trim()
            ->slug()
            ->limit(120, '')
            ->toString() ?: 'page';
    }
}
