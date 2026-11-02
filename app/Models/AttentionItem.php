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
        'payment_proof' => 'Payment proof sent',
        'call_request' => 'Asked for a call',
        'booking_request' => 'Asked for a booking',
        'website_lead' => 'New website lead',
    ];

    /** The kind an item gets for a handover reason; any other reason is a plain handover. */
    public const KIND_BY_REASON = [
        'payment_proof' => 'payment_proof',
        'call_requested' => 'call_request',
        'booking_requested' => 'booking_request',
        'website_lead' => 'website_lead',
    ];

    public const OUTCOMES = [
        'answered' => 'Answered',
        'returned_to_ai' => 'Given back to the AI',
        'never_answered' => 'Never answered',
        'dismissed' => 'Closed without a reply',
    ];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Deleting a lead used to delete its Needs attention item with it (the
        // foreign key cascades). A lead in Trash (owner request 2026-11-01, plan
        // T4b) hides the item the same way: not in the queue, the badge or the
        // reports; Restore puts it back.
        static::addGlobalScope('leadNotInTrash', function (Builder $query): void {
            $column = $query->getModel()->qualifyColumn('lead_id');

            $query->where(fn (Builder $any): Builder => $any
                ->whereNull($column)
                ->orWhereIn($column, Lead::withoutGlobalScopes()->withoutTrashed()->select('id')));
        });
    }

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

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
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
