<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One investor saw one feed post (first time only). */
class FeedPostView extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'post_id', 'investor_portal_account_id', 'viewed_at'];

    protected $casts = ['viewed_at' => 'datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(InvestorPortalAccount::class, 'investor_portal_account_id');
    }
}
