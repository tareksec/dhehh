<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payout destination (bKash / Nagad / Rocket number or bank details) a
 * reseller saved once in the portal, so a withdrawal request can pick it
 * from a list instead of retyping it. A withdrawal request still copies the
 * method and details onto itself (ResellerPayoutRequest), so editing or
 * deleting an account here never changes a request already sent.
 */
class ResellerPayoutAccount extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'customer_id',
        'method',
        'account_details',
        'label',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** e.g. "bKash — 01711112222 (Personal)". */
    public function displayName(): string
    {
        $method = __(ResellerPayoutRequest::METHODS[$this->method] ?? $this->method);

        return $method.' — '.$this->account_details.($this->label ? ' ('.$this->label.')' : '');
    }
}
