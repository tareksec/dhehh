<?php

namespace App\Filament\Resources\ChannelPartners\Pages;

use App\Filament\Resources\ChannelPartners\ChannelPartnerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChannelPartners extends ListRecords
{
    protected static string $resource = ChannelPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
