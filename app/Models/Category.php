<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\NotifiesWebsiteOfStorefrontChanges;
use App\Support\StorefrontListingCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{
    use BelongsToCompany;
    use NotifiesWebsiteOfStorefrontChanges;
    use SoftDeletes;

    protected $fillable = ['company_id', 'name', 'slug', 'description', 'tagline', 'image', 'icon', 'is_active'];

    protected static function booted(): void
    {
        static::saved(function (Category $category): void {
            Cache::forget("storefront-home:{$category->company_id}");
            StorefrontListingCache::forgetCompany($category->company_id);
        });
        static::deleted(function (Category $category): void {
            Cache::forget("storefront-home:{$category->company_id}");
            StorefrontListingCache::forgetCompany($category->company_id);
        });
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
