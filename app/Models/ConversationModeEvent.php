<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One switch of a chat between the AI and the team (append-only). */
class ConversationModeEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const SOURCES = [
        'dashboard' => 'Dashboard',
        'ai' => 'AI',
        'auto_revert' => 'Auto-revert',
        'staff_reply' => 'Answered',
        'page_reply' => 'Page inbox',
        'dot_command' => 'Dot command',
        'bulk' => 'Bulk',
    ];

    protected $guarded = ['id'];

    protected $casts = ['revert_at' => 'datetime', 'created_at' => 'datetime'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
