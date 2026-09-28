<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DocumentationCategory extends Model
{
    protected $fillable = ['name', 'slug', 'icon', 'sort_order'];

    protected static function booted(): void
    {
        static::creating(function (DocumentationCategory $category): void {
            if (blank($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function articles(): HasMany
    {
        return $this->hasMany(DocumentationArticle::class);
    }
}
