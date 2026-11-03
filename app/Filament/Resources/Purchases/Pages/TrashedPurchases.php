<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\Purchases\PurchaseResource;

/**
 * Deleted purchases. While a purchase is here its goods are out of stock and its amount is out of the
 * supplier's balance (as when it was deleted for good); Restore puts both back (owner request 2026-11-01,
 * docs/delete-and-trash-plan.md T5a).
 */
class TrashedPurchases extends TrashedRecords
{
    protected static string $resource = PurchaseResource::class;
}
