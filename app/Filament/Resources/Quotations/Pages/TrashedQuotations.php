<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\Quotations\QuotationResource;

/**
 * Deleted quotations. Their items stay with them; Restore brings a quotation back as it was. The quotation number stays reserved while it is here (owner request 2026-11-01, docs/delete-and-trash-plan.md T4b).
 */
class TrashedQuotations extends TrashedRecords
{
    protected static string $resource = QuotationResource::class;
}
