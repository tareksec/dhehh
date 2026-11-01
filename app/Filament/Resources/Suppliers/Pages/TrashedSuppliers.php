<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\Suppliers\SupplierResource;

/**
 * Deleted suppliers. Their purchases, payments and balance are not touched while they are here; Restore brings the supplier back as they were (owner request 2026-11-01, docs/delete-and-trash-plan.md T4a).
 */
class TrashedSuppliers extends TrashedRecords
{
    protected static string $resource = SupplierResource::class;
}
