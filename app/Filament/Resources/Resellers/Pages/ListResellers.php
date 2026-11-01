<?php

namespace App\Filament\Resources\Resellers\Pages;

use App\Filament\Resources\Pages\TrashedRecords;
use App\Filament\Resources\Resellers\ResellerResource;
use Filament\Resources\Pages\ListRecords;

class ListResellers extends ListRecords
{
    protected static string $resource = ResellerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TrashedRecords::linkAction(ResellerResource::class),
        ];
    }
}
