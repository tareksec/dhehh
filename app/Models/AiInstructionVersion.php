<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved copy of the owner's guidance for the sales agent
 * (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.5). At most one draft and one
 * published version per company; older published ones are superseded.
 */
class AiInstructionVersion extends Model
{
    use BelongsToCompany;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUPERSEDED = 'superseded';

    /** block key => AI setting key it is stored under */
    public const BLOCKS = [
        'about' => 'business_about',
        'voice' => 'brand_voice',
        'guidelines' => 'sales_guidelines',
    ];

    public const SOURCES = [
        'agent_desk' => 'Agent Desk',
        'settings' => 'Integrations',
        'restore' => 'Restored',
    ];

    protected $guarded = ['id'];

    protected $casts = ['blocks' => 'array', 'published_at' => 'datetime'];

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Blocks with every key present, as trimmed strings. */
    public function normalizedBlocks(): array
    {
        return self::normalize((array) $this->blocks);
    }

    public static function normalize(array $blocks): array
    {
        $clean = [];
        foreach (array_keys(self::BLOCKS) as $key) {
            $clean[$key] = trim((string) ($blocks[$key] ?? ''));
        }

        return $clean;
    }
}
