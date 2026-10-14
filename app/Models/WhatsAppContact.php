<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contact from the WhatsApp Business App address book, archived through
 * the WhatsApp Web (QR login) bridge. Rows are never removed when the
 * channel is deleted, unlinked or the number is banned - channel_id simply
 * becomes null - so the customer list survives losing the WhatsApp account.
 */
class WhatsAppContact extends Model
{
    use BelongsToCompany;

    protected $table = 'whatsapp_contacts';

    protected $fillable = [
        'company_id', 'channel_id', 'jid', 'lid', 'phone', 'name', 'push_name', 'verified_name',
        'customer_id', 'lead_id', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function displayName(): ?string
    {
        return collect([$this->name, $this->verified_name, $this->push_name])
            ->map(fn (?string $value): string => trim((string) $value))
            ->first(fn (string $value): bool => $value !== '');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ConversationChannel::class, 'channel_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
