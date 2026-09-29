<?php

namespace App\Filament\Resources\ChannelPartners\Pages;

use App\Filament\Resources\ChannelPartners\ChannelPartnerResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditChannelPartner extends EditRecord
{
    protected static string $resource = ChannelPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
