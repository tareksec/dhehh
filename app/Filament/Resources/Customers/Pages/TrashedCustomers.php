<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Pages\TrashedRecords;

/**
 * Deleted customers. Their orders, payments and balance are not touched while they are here; Restore brings the customer back as they were (owner request 2026-11-01, docs/delete-and-trash-plan.md T4a).
 */
class TrashedCustomers extends TrashedRecords
{
    protected static string $resource = CustomerResource::class;
}
