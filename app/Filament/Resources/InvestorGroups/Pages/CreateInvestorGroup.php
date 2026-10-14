<?php

namespace App\Filament\Resources\InvestorGroups\Pages;

use App\Filament\Resources\InvestorGroups\InvestorGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvestorGroup extends CreateRecord
{
    protected static string $resource = InvestorGroupResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
