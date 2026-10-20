<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An investor's comment on a feed post (IMS-V2/PRD.md FR-63). Other
 * investors see it without a name; the writer can delete their own; the
 * office can hide any.
 */
class FeedComment extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'post_id', 'investor_portal_account_id', 'body', 'hidden_at', 'hidden_by'];

    protected $casts = ['hidden_at' => 'datetime'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedPost::class, 'post_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InvestorPortalAccount::class, 'investor_portal_account_id');
    }

    public function scopeShown($query)
    {
        return $query->whereNull('hidden_at');
    }
}
