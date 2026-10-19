<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scoped only through its owning Broadcast (same pattern as
 * ConversationMessage/ConversationChannel) -- never independently
 * company-scoped, since it has no reason to be queried outside its parent.
 */
class BroadcastRecipient extends Model
{
    public const TYPES = [
        'lead' => 'Lead',
        'customer' => 'Customer',
        'conversation' => 'Chat',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'sending' => 'Sending / delivery requires reconciliation',
        'sent' => 'Sent',
        'failed' => 'Failed',
    ];

    protected $fillable = [
        'broadcast_id', 'recipient_type', 'recipient_id', 'name', 'phone',
        'channel_used', 'status', 'error', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }
}
