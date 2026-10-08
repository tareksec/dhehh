<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One person's "I have read and agree" to one agreement version. Never changed or deleted. */
class AgreementAcceptance extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'agreement_template_id', 'portal_account_id', 'investor_id', 'channel_partner_id', 'name', 'accepted_at', 'ip_address', 'user_agent', 'body_sha256'];

    protected $casts = ['accepted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An agreement acceptance cannot be changed.'));
        static::deleting(fn () => throw new LogicException('An agreement acceptance cannot be deleted.'));
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AgreementTemplate::class, 'agreement_template_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InvestorPortalAccount::class, 'portal_account_id');
    }
}
