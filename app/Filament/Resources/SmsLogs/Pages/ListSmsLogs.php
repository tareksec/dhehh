<?php

namespace App\Filament\Resources\SmsLogs\Pages;

use App\Filament\Resources\SmsLogs\SmsLogResource;
use Filament\Resources\Pages\ListRecords;

class ListSmsLogs extends ListRecords
{
    protected static string $resource = SmsLogResource::class;

    public function getSubheading(): ?string
    {
        return SmsLogResource::logNotice();
    }
}
