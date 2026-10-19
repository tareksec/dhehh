<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    protected $fillable = [
        'company_id', 'channel_id', 'provider', 'entry_point', 'ad_referral_id',
        'external_contact_id', 'contact_name', 'contact_phone', 'lead_id', 'customer_id',
        'status', 'ai_enabled', 'human_handled_until', 'assigned_to',
        'last_message_at', 'unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
        'ai_enabled' => 'boolean',
        'human_handled_until' => 'datetime',
    ];

    public function profileDisplayName(): string
    {
        return $this->hasRealName() ? trim((string) $this->contact_name) : 'নাম পাওয়া যায়নি';
    }

    /**
     * Name shown in the Inbox: the contact's name, else the phone number of a
     * WhatsApp chat (so it is still recognisable), else the "name not found" text. profileDisplayName() stays as is - the Messenger profile
     * lookups compare against its "name not found" result.
     */
    public function inboxDisplayName(): string
    {
        if ($this->hasRealName()) {
            return trim((string) $this->contact_name);
        }

        // WhatsApp chats are identified by their number. Messenger keeps the
        // "name not found" text: a missing profile name never falls back to a phone or id.
        if ($this->provider === 'whatsapp') {
            $digits = (string) preg_replace('/\D+/', '', (string) $this->contact_phone);

            if (strlen($digits) >= 7) {
                return str_starts_with($digits, '0') ? $digits : '+'.$digits;
            }
        }

        return $this->profileDisplayName();
    }

    /** A real name: not blank and not just a number such as "Contact 88017...". */
    protected function hasRealName(): bool
    {
        $name = trim((string) $this->contact_name);

        return $name !== '' && ! preg_match('/^(?:(?:chat\s+)?contact\s+)?[\d+\s-]+$/i', $name);
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
