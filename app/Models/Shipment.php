<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Shipment extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['planned' => 'Planned', 'booked' => 'Booked', 'shipped' => 'Shipped', 'in_transit' => 'In Transit', 'customs' => 'Customs', 'received' => 'Received', 'cancelled' => 'Cancelled'];

    protected $fillable = ['company_id', 'container_id', 'purchase_id', 'tracking_number', 'carrier', 'transport_mode', 'status', 'shipped_at', 'estimated_arrival', 'received_at', 'notes'];

    protected $casts = ['shipped_at' => 'date', 'estimated_arrival' => 'date', 'received_at' => 'date'];

    protected static function booted(): void
    {
        static::saving(function (Shipment $shipment): void {
            // On a brand-new record, `saving` fires before `creating` (see
            // Model::save()/performInsert()), so BelongsToCompany hasn't
            // filled in $shipment->company_id yet at this point — comparing
            // against it directly would always read null/0 here and reject
            // every single creation. Use the company_id this shipment is
            // actually about to be saved with instead.
            $companyId = $shipment->company_id ?: Company::resolveActiveCompanyId();

            if ($shipment->container_id && (int) $shipment->container?->company_id !== (int) $companyId) {
                throw ValidationException::withMessages(['container_id' => 'Container must belong to the same company.']);
            }
            if ($shipment->purchase_id && (int) $shipment->purchase?->company_id !== (int) $companyId) {
                throw ValidationException::withMessages(['purchase_id' => 'Purchase must belong to the same company.']);
            }
        });
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
