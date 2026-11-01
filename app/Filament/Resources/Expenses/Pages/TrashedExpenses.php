<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Pages\TrashedRecords;

/**
 * Deleted expenses (owner request 2026-11-01). Restoring one puts its money
 * movement back on the account, unless that would make the balance negative.
 */
class TrashedExpenses extends TrashedRecords
{
    protected static string $resource = ExpenseResource::class;
}
