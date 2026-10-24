<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\OtpLifetime;
use Illuminate\Database\Eloquent\Model;

/**
 * A six-digit one-time code (IMS-V2/PRD.md FR-04): 3 minutes at most (OtpLifetime), five wrong
 * tries, 60 seconds between sends. Only the code's hash is kept.
 */
class InvestorOtpCode extends Model
{
    use BelongsToCompany;

    public const PURPOSE_INVITE = 'invite';

    public const VALID_MINUTES = OtpLifetime::MAX_MINUTES;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    protected $fillable = ['company_id', 'contact', 'purpose', 'code_hash', 'attempts', 'expires_at', 'last_sent_at', 'used_at'];

    protected $casts = ['expires_at' => 'datetime', 'last_sent_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];

    protected $hidden = ['code_hash'];
}
