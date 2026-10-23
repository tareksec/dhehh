<?php

namespace App\Filament\Resources\SmsLogs\Pages;

use App\Filament\Resources\SmsLogs\SmsLogResource;
use App\Services\CompanyContext;
use App\Services\SmsLogService;
use Filament\Resources\Pages\ListRecords;

class ListSmsLogs extends ListRecords
{
    protected static string $resource = SmsLogResource::class;

    /**
     * A message is logged as waiting the moment the order is saved and is sent by the queue worker. When some
     * have waited for minutes, the worker is not running: say so here, where the owner looks.
     */
    public function getSubheading(): ?string
    {
        $context = app(CompanyContext::class);
        $stuck = app(SmsLogService::class)->stuckCount($context->isAllCompanies() ? null : $context->id());

        return $stuck > 0
            ? __(':count SMS have waited more than :minutes minutes. The queue worker does not seem to be running (see docs/deployment.md), so nothing is sent. Start it and they go out.', [
                'count' => $stuck,
                'minutes' => SmsLogService::STUCK_AFTER_MINUTES,
            ])
            : null;
    }
}
