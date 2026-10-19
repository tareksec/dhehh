<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Crm\KnowledgeBaseService;
use Illuminate\Database\Eloquent\Model;

/**
 * One document of a company's knowledge base (16_AI_INBOX_CONTROL_PLANE_PLAN.md
 * §4.6): plain text or Markdown, split into short passages for the AI to look up.
 */
class KnowledgeDocument extends Model
{
    use BelongsToCompany;

    public const MAX_CONTENT = 20000;

    protected $fillable = ['company_id', 'title', 'content', 'is_active', 'source_file'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        $forget = fn (self $document) => KnowledgeBaseService::forget((int) $document->company_id);

        static::saved($forget);
        static::deleted($forget);
    }
}
