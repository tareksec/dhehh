<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time a chat needed a person (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.3).
 * Opened when the AI hands a chat to the team; closed when someone marks it
 * done or the chat goes back to the AI.
 */
class AttentionItem extends Model
{
    use BelongsToCompany;

    public const KINDS = [
        'handover' => 'AI handed over',
    ];

    public const OUTCOMES = [
        'answered' => 'Answered',
        'returned_to_ai' => 'Given back to the AI',
        'never_answered' => 'Never answered',
        'dismissed' => 'Closed without a reply',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'opened_at' => 'datetime',
        'due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function firstResponder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_response_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /** Waiting past the response target with no reply yet. */
    public function isOverdue(): bool
    {
        return $this->resolved_at === null
            && $this->first_response_at === null
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /** Minutes from opening to the first reply, or to now while still waiting. */
    public function waitMinutes(): int
    {
        $end = $this->first_response_at ?? $this->resolved_at ?? now();

        return (int) max(0, $this->opened_at->diffInMinutes($end));
    }
}
