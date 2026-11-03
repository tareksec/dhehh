<?php

namespace App\Filament\Resources\StockPools\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\StockPools\StockPoolResource;

/**
 * Deleted stock pools. A pool here has no products in it (each went back to its own stock); Restore links
 * the remembered ones again, unless the source product is gone or has joined another pool (owner request
 * 2026-11-01, docs/delete-and-trash-plan.md T5a).
 */
class TrashedStockPools extends TrashedRecords
{
    protected static string $resource = StockPoolResource::class;
}
