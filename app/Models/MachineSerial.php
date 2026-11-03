<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A machine's serial number (PLAN.md §3.7, optional): recorded on the
 * purchase it came with and, once sold, linked to the Delivery Challan it
 * left on — so a buyer's machine can be traced back to its LC.
 */
class MachineSerial extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'product_id', 'purchase_id', 'delivery_challan_id', 'serial_number', 'note'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function isSold(): bool
    {
        return $this->delivery_challan_id !== null;
    }
}
