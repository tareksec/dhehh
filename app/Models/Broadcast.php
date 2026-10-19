<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Broadcast extends Model
{
    use BelongsToCompany;

    public const AUDIENCE_TYPES = [
        'leads' => 'Leads',
        'customers' => 'Customers',
        'both' => 'Leads & Customers',
    ];

    public const CHANNELS = [
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'both' => 'WhatsApp (SMS fallback)',
        'messenger' => 'Messenger',
    ];

    /** The audience of a Messenger broadcast: the Page's chats from the last window. */
    public const MESSENGER_AUDIENCE = 'conversations';

    /**
     * Meta allows a normal message for 24 hours after the customer's last
     * one; one hour is kept back so a message never lands on the edge.
     */
    public const MESSENGER_WINDOW_HOURS = 23;

    public const STATUSES = [
        'draft' => 'Draft',
        'queued' => 'Queued',
        'sending' => 'Sending',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'company_id', 'name', 'audience_type', 'lead_status_filter', 'lead_source_filter',
        'channel', 'whatsapp_channel_id', 'whatsapp_template_name', 'whatsapp_template_language',
        'messenger_channel_id', 'messenger_text', 'tested_hash', 'tested_at',
        'sms_body', 'status', 'recipients_count', 'sent_count', 'failed_count',
        'created_by', 'queued_at', 'completed_at',
    ];

    protected $casts = [
        'lead_status_filter' => 'array',
        'lead_source_filter' => 'array',
        'recipients_count' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
        'queued_at' => 'datetime',
        'completed_at' => 'datetime',
        'tested_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Broadcast $broadcast): void {
            if ($broadcast->isMessenger()) {
                $broadcast->audience_type = self::MESSENGER_AUDIENCE;
            }
        });
    }

    public function whatsappChannel(): BelongsTo
    {
        return $this->belongsTo(ConversationChannel::class, 'whatsapp_channel_id');
    }

    public function messengerChannel(): BelongsTo
    {
        return $this->belongsTo(ConversationChannel::class, 'messenger_channel_id');
    }

    public function isMessenger(): bool
    {
        return $this->channel === 'messenger';
    }

    /** What a Messenger test message proves: this exact text, from this exact Page. */
    public function contentHash(): string
    {
        return hash('sha256', $this->messenger_channel_id.'|'.trim((string) $this->messenger_text));
    }

    /** A Messenger broadcast may not be sent until a test of its current text succeeded. */
    public function needsTest(): bool
    {
        return $this->isMessenger() && $this->tested_hash !== $this->contentHash();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'failed', 'cancelled'], true);
    }
}
