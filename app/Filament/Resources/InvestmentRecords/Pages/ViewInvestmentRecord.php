<?php

namespace App\Filament\Resources\InvestmentRecords\Pages;

use App\Filament\Resources\InvestmentRecords\InvestmentRecordResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInvestmentRecord extends ViewRecord
{
    protected static string $resource = InvestmentRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [InvestmentRecordResource::withdrawAction()];
    }
}
