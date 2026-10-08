<?php

namespace App\Filament\Resources\ChannelPartners\Pages;

use App\Filament\Actions\InvestorPortalAccessAction;
use App\Filament\Actions\InvestorPortalOpenAction;
use App\Filament\Resources\ChannelPartners\ChannelPartnerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewChannelPartner extends ViewRecord
{
    protected static string $resource = ChannelPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [InvestorPortalAccessAction::make(), InvestorPortalOpenAction::make(), EditAction::make()];
    }
}
