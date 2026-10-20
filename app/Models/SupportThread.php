<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An investor's question and the office's answers (IMS-V2/PRD.md FR-64),
 * with "unread" on both sides. A closed thread opens again when the
 * investor writes.
 */
class SupportThread extends Model
{
    use BelongsToCompany;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = ['company_id', 'investor_portal_account_id', 'investor_id', 'project_id', 'subject', 'status', 'staff_unread', 'investor_unread', 'last_message_at', 'legacy_request_id'];

    protected $casts = ['staff_unread' => 'boolean', 'investor_unread' => 'boolean', 'last_message_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_OPEN, 'staff_unread' => true, 'investor_unread' => false];

    public function account(): BelongsTo
    {
        return $this->belongsTo(InvestorPortalAccount::class, 'investor_portal_account_id');
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(InvestmentProject::class, 'project_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'thread_id')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
