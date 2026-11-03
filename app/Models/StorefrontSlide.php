<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\NotifiesWebsiteOfStorefrontChanges;
use App\Support\StorefrontThemeRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class StorefrontSlide extends Model
{
    use BelongsToCompany;
    use NotifiesWebsiteOfStorefrontChanges;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'storefront_setting_id',
        'theme',
        'template',
        'image',
        'image_mobile',
        'fit_to_frame',
        'eyebrow',
        'heading',
        'subheading',
        'cta_label',
        'cta_url',
        'product_id',
        'sort_order',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'fit_to_frame' => 'boolean',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(fn (StorefrontSlide $slide) => Cache::forget("storefront-home:{$slide->company_id}"));
        static::deleted(fn (StorefrontSlide $slide) => Cache::forget("storefront-home:{$slide->company_id}"));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScopes()->withoutTrashed();
    }

    /** The site this slide is limited to; null means it shows on every site of the company. */
    public function storefrontSetting(): BelongsTo
    {
        return $this->belongsTo(StorefrontSetting::class)->withoutGlobalScopes();
    }

    /** Slides shown on $site: those for all sites plus those limited to this one. */
    public function scopeForSite(Builder $query, ?StorefrontSetting $site): Builder
    {
        return $query->where(function (Builder $q) use ($site): void {
            $q->whereNull($this->qualifyColumn('storefront_setting_id'));

            if ($site?->getKey()) {
                $q->orWhere($this->qualifyColumn('storefront_setting_id'), $site->getKey());
            }
        });
    }

    public function scopeActiveNow(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public static function forCompany(int $companyId): Collection
    {
        return Cache::remember("storefront-home:{$companyId}", now()->addMinutes(10), fn () => static::query()
            ->where('company_id', $companyId)
            ->activeNow()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get());
    }

    /**
     * Slides tagged for the company's currently active theme/template, so a
     * banner built for one theme's ratio never shows under another. Slides
     * saved before this tagging existed have a null theme/template and stay
     * a fallback for every theme until re-tagged.
     */
    public static function forCompanyTheme(int $companyId, ?string $theme, ?string $template, ?StorefrontSetting $site = null): Collection
    {
        $theme = StorefrontThemeRegistry::normalizeTheme($theme);
        $template = StorefrontThemeRegistry::normalizeTemplate($theme, $template);

        // The cached list holds every active slide of the company; the site's own are picked here.
        $all = static::forCompany($companyId)->filter(
            fn (StorefrontSlide $slide): bool => $slide->storefront_setting_id === null
                || ($site?->getKey() && (int) $slide->storefront_setting_id === (int) $site->getKey()),
        );

        $matched = $all->where('theme', $theme)->where('template', $template)->values();

        return $matched->isNotEmpty() ? $matched : $all->whereNull('theme')->values();
    }
}
