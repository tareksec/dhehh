<?php

namespace App\Filament\Resources\InvestorGroups\Pages;

use App\Filament\Resources\InvestorGroups\InvestorGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvestorGroups extends ListRecords
{
    protected static string $resource = InvestorGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
