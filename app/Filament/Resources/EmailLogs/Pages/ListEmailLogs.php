<?php

namespace App\Filament\Resources\EmailLogs\Pages;

use App\Filament\Resources\EmailLogs\EmailLogResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailLogs extends ListRecords
{
    protected static string $resource = EmailLogResource::class;

    /**
     * "Sent" is the mail server's own acceptance of the message. It is not proof that the inbox received it: a
     * bounce or a spam-folder delivery happens later and is not tracked.
     */
    public function getSubheading(): ?string
    {
        return __('Sent means the mail server accepted the message. It does not prove the inbox received it: bounces and spam-folder delivery are not tracked yet.');
    }
}
