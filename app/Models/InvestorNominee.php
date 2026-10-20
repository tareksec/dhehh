<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One of an investor's one to five nominees (IMS-V2/PRD.md FR-06). With more
 * than one, the shares add up to exactly 100% (App\Ims\Onboarding\NomineeService).
 */
class InvestorNominee extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const MAX = 5;

    protected $fillable = ['company_id', 'investor_id', 'name', 'relation', 'phone', 'nid', 'address', 'share_percent'];

    protected $casts = ['nid' => 'encrypted', 'share_percent' => 'decimal:2'];

    protected $hidden = ['nid'];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }
}
