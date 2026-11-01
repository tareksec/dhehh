<?php

namespace App\Filament\Resources\Resellers\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\Resellers\ResellerResource;

/**
 * Deleted resellers. Their deposits, profits and withdrawals are kept; their store and sign-in are off until Restore (owner request 2026-11-01, docs/delete-and-trash-plan.md T4a).
 */
class TrashedResellers extends TrashedRecords
{
    protected static string $resource = ResellerResource::class;
}
