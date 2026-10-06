<?php

namespace App\Filament\Resources\CourierWebhookLogs\Pages;

use App\Filament\Resources\CourierWebhookLogs\CourierWebhookLogResource;
use Filament\Resources\Pages\ListRecords;

class ListCourierWebhookLogs extends ListRecords
{
    protected static string $resource = CourierWebhookLogResource::class;

    /**
     * A callback is saved first and processed by the queue worker. When
     * callbacks sit in Pending, the worker is not running and no courier
     * status reaches the orders — say so here, where staff look.
     */
    public function getSubheading(): ?string
    {
        $stuck = CourierWebhookLogResource::stuckCount();

        return $stuck > 0
            ? __(':count courier callback(s) have waited more than :minutes minutes. The queue worker does not seem to be running (see docs/deployment.md) — start it, or press Process now on each one.', [
                'count' => $stuck,
                'minutes' => CourierWebhookLogResource::STUCK_AFTER_MINUTES,
            ])
            : null;
    }
}
