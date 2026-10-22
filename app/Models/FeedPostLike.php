<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedPostLike extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'post_id', 'investor_portal_account_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(InvestorPortalAccount::class, 'investor_portal_account_id');
    }
}
