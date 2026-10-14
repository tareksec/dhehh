<?php

namespace App\Filament\Resources\InvestorGroups\Pages;

use App\Filament\Resources\InvestorGroups\InvestorGroupResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditInvestorGroup extends EditRecord
{
    protected static string $resource = InvestorGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
