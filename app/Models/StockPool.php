<?php

namespace App\Models;

use App\Services\StockPoolMembership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Deliberately does NOT use BelongsToCompany/CompanyScope, and is excluded
 * from MultiCompanyIsolationTest's contract on purpose — same carve-out as
 * CustomerBlacklist.
 *
 * A pool links two or more Product rows (normally owned by different
 * companies) that are physically the same stock in one real warehouse, e.g.
 * ZamZam International selling the item wholesale and ZamZam Gadget selling
 * the same physical units retail. One member is the `source_product_id`:
 * the product that "owns" the real stock (opening/purchase movements live
 * there). Every other member's own StockMovement ledger always nets to zero
 * — StockMovementService::maybeCreatePoolTransfer() mirrors any real
 * movement recorded against a non-source member as an equal-and-opposite
 * 'transfer' pair, so the pool's true total only ever moves by the actual
 * sale/purchase/return/damage/adjustment, never doubled or lost, while each
 * company's own stock history still tells a coherent story.
 *
 * Every member Product's `stock` column is kept in sync to the same live
 * pool-wide total by StockMovementService::syncProductStock() — whichever
 * company's storefront/staff sells first, both companies see the correct
 * remaining quantity immediately, with no manual reconciliation step.
 *
 * Pooling is only supported for simple (non-variant) products — see
 * StockPoolResource's product picker.
 *
 * Delete = Trash (owner request 2026-11-01, plan T5a). Moving a pool to Trash does what deleting
 * it always did: its products go back to their own ledgers. The pool remembers who was in it
 * (`member_product_ids`) and Restore links them again (StockPoolMembership).
 */
class StockPool extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'source_product_id',
    ];

    protected $casts = [
        'member_product_ids' => 'array',
    ];

    protected static function booted(): void
    {
        static::deleting(function (StockPool $pool): void {
            if (! $pool->trashed()) {
                app(StockPoolMembership::class)->release($pool);
            }
        });

        static::restoring(function (StockPool $pool): void {
            app(StockPoolMembership::class)->assertCanRestore($pool);
        });

        static::restored(function (StockPool $pool): void {
            app(StockPoolMembership::class)->restoreMembers($pool);
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_product_id');
    }
}
