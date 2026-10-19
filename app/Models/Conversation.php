<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class Conversation extends Model
{
    use BelongsToCompany;

    /**
     * How long a staff member having this conversation open in the Inbox
     * silences the AI auto-reply for it — a short, rolling window (renewed
     * on every 8s poll tick while the thread stays open) so the AI never
     * races a human who is about to type, without needing the 24h
     * `human_handled_until` lock that only starts after a human actually
     * sends something.
     */
    public const HUMAN_PRESENCE_SECONDS = 30;

    public const PROVIDERS = [
        'whatsapp' => 'WhatsApp',
        'messenger' => 'Messenger',
        'phone' => 'Phone',
        'manual' => 'Manual',
    ];

    public const STATUSES = [
        'open' => 'Open',
        'pending' => 'Pending',
        'closed' => 'Closed',
    ];

    /** Who answers the chat now. Change it only through ConversationModeService. */
    public const MODE_AI = 'ai';

    public const MODE_HUMAN = 'human';

    /** Why the AI handed a chat to the team (shown in the Inbox and the Activity log). */
    public const HANDOVER_REASONS = [
        'discount_requested' => 'Asked for a discount',
        'complaint' => 'Complaint',
        'refund' => 'Refund',
        'human_requested' => 'Asked for a person',
        'out_of_scope' => 'Outside what the AI knows',
        'angry' => 'Angry customer',
        'ready_to_buy' => 'Ready to buy',
        'ai_error' => 'AI could not reply',
        'payment_proof' => 'Payment proof sent',
        'call_requested' => 'Asked for a call',
        'booking_requested' => 'Asked for a booking',
        'website_lead' => 'New website lead',
        'follow_up_due' => 'Follow-up due',
        'other' => 'Other',
    ];

    protected $fillable = [
        'company_id', 'channel_id', 'provider', 'entry_point', 'ad_referral_id',
        'external_contact_id', 'contact_name', 'contact_phone', 'lead_id', 'customer_id',
        'status', 'ai_enabled', 'is_staff_test', 'automation_mode', 'handover_reason', 'human_handled_until',
        'mode_changed_at', 'mode_changed_by', 'assigned_to',
        'last_message_at', 'unread_count',
    ];

    protected $attributes = ['automation_mode' => self::MODE_AI];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
        'ai_enabled' => 'boolean',
        'is_staff_test' => 'boolean',
        'human_handled_until' => 'datetime',
        'mode_changed_at' => 'datetime',
    ];

    public function profileDisplayName(): string
    {
        $name = trim((string) $this->contact_name);

        return $name !== '' && ! preg_match('/^(?:(?:chat\s+)?contact\s+)?[\d+\s-]+$/i', $name)
            ? $name : 'নাম পাওয়া যায়নি';
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ConversationChannel::class, 'channel_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->latestOfMany('id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function modeEvents(): HasMany
    {
        return $this->hasMany(ConversationModeEvent::class);
    }

    public function isHumanMode(): bool
    {
        return $this->automation_mode === self::MODE_HUMAN;
    }

    /** When a human chat goes back to the AI on its own; null = AI chat, or held with no time limit. */
    public function revertAt(): ?Carbon
    {
        return $this->isHumanMode() ? $this->human_handled_until : null;
    }

    public function markRead(): void
    {
        if ($this->unread_count > 0) {
            $this->forceFill(['unread_count' => 0])->saveQuietly();
        }
    }

    /** Mark a staff member as currently viewing this conversation (Inbox open). */
    public function markHumanPresent(): void
    {
        Cache::put($this->humanPresenceCacheKey(), true, self::HUMAN_PRESENCE_SECONDS);
    }

    /** True while a staff member has this conversation open in the Inbox. */
    public function hasHumanPresent(): bool
    {
        return Cache::has($this->humanPresenceCacheKey());
    }

    protected function humanPresenceCacheKey(): string
    {
        return "crm:human-present:{$this->getKey()}";
    }

    /**
     * WhatsApp/Messenger allow free-form replies only within 24 hours of the
     * customer's last incoming message; outside that window only approved
     * template messages can be sent. Conversations opened from a Click-to-
     * WhatsApp ad (CTWA Free Entry Point) get 72 hours instead.
     */
    public function withinReplyWindow(): bool
    {
        if (! in_array($this->provider, ['whatsapp', 'messenger'], true)) {
            return true;
        }

        $lastIncomingAt = $this->messages()
            ->where('direction', 'incoming')
            ->latest('sent_at')
            ->value('sent_at');

        if ($lastIncomingAt === null) {
            return false;
        }

        return now()->diffInHours($lastIncomingAt, true) < $this->replyWindowHours();
    }

    public function replyWindowHours(): int
    {
        return $this->entry_point === 'ctwa_ad' ? 72 : 24;
    }

    /** Whole hours left in the messaging window, null when it is closed. */
    public function replyWindowHoursLeft(): ?int
    {
        if (! in_array($this->provider, ['whatsapp', 'messenger'], true)) {
            return null;
        }

        $lastIncomingAt = $this->messages()
            ->where('direction', 'incoming')
            ->latest('sent_at')
            ->value('sent_at');

        if ($lastIncomingAt === null) {
            return null;
        }

        $left = $this->replyWindowHours() - now()->diffInHours($lastIncomingAt, true);

        return $left > 0 ? (int) floor($left) : null;
    }
}
