<?php

namespace App\Filament\Resources\CompanyFaqs\Pages;

use App\Filament\Resources\CompanyFaqs\CompanyFaqResource;
use App\Filament\Resources\Pages\TrashedRecords;

/**
 * Deleted FAQs (owner request 2026-11-01, docs/delete-and-trash-plan.md T3).
 */
class TrashedCompanyFaqs extends TrashedRecords
{
    protected static string $resource = CompanyFaqResource::class;
}
