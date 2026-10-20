<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "A person already called or wrote to this customer" on the To chase list
 * (16_AI_INBOX_CONTROL_PLANE_PLAN.md §4.8). Keyed by normalised phone.
 */
class ChaseMark extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'phone', 'contacted_at', 'contacted_by'];

    protected $casts = ['contacted_at' => 'datetime'];

    public function contactedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contacted_by');
    }
}
