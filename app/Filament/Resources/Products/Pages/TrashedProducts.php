<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\Products\ProductResource;

/**
 * Deleted products (owner request 2026-11-01, docs/delete-and-trash-plan.md T5b). Restoring one also brings
 * back the orders and purchases that went to Trash with it (see App\Services\ProductTrashService).
 */
class TrashedProducts extends TrashedRecords
{
    protected static string $resource = ProductResource::class;
}
