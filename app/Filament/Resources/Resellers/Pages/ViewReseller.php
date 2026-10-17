<?php

namespace App\Filament\Resources\Resellers\Pages;

use App\Filament\Resources\Resellers\Actions\ResellerPasswordActions;
use App\Filament\Resources\Resellers\ResellerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewReseller extends ViewRecord
{
    protected static string $resource = ResellerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ResellerPasswordActions::setPassword(),
            ResellerPasswordActions::emailResetLink(),
            EditAction::make(),
        ];
    }
}
