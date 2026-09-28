<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DocumentationArticle extends Model
{
    protected $fillable = ['documentation_category_id', 'title', 'slug', 'content', 'sort_order', 'is_published'];

    protected $casts = ['is_published' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (DocumentationArticle $article): void {
            if (blank($article->slug)) {
                $article->slug = Str::slug($article->title);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentationCategory::class, 'documentation_category_id');
    }
}
