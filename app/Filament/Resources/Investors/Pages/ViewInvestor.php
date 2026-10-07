<?php

namespace App\Filament\Resources\Investors\Pages;

use App\Filament\Actions\InvestorPortalAccessAction;
use App\Filament\Resources\Investors\InvestorResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInvestor extends ViewRecord
{
    protected static string $resource = InvestorResource::class;

    protected function getHeaderActions(): array
    {
        return [InvestorPortalAccessAction::make(), EditAction::make()];
    }
}
