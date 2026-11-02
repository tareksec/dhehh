<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named reseller level (for example Bronze, Silver, Gold) that the company
 * makes up and gives to resellers by hand (owner decisions 2026-10-16). A level
 * is a name shown to the reseller; it may also set a lower return charge and a
 * lower minimum withdrawal than the company's own amounts in Reseller Settings.
 * An amount left empty means "use the company's amount". Nothing else about a
 * reseller's money depends on the level.
 *
 * A level in Trash (owner request 2026-11-01, plan T4b) stops applying: the
 * resellers who had it fall back to the company's amounts, as they did when a
 * deleted level vanished, and it applies again on Restore (the resellers keep
 * `reseller_level_id` meanwhile).
 */
class ResellerLevel extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'sort_order',
        'return_charge',
        'min_withdrawal',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'return_charge' => 'decimal:2',
            'min_withdrawal' => 'decimal:2',
        ];
    }

    public function resellers(): HasMany
    {
        return $this->hasMany(Customer::class, 'reseller_level_id');
    }
}
