<?php

namespace App\Filament\Resources\CourierWebhookLogs\Pages;

use App\Filament\Resources\CourierWebhookLogs\CourierWebhookLogResource;
use Filament\Resources\Pages\ListRecords;

class ListCourierWebhookLogs extends ListRecords
{
    protected static string $resource = CourierWebhookLogResource::class;

    public function getSubheading(): ?string
    {
        return CourierWebhookLogResource::logNotice();
    }
}
