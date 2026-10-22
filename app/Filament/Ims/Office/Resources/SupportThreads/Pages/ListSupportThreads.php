<?php

namespace App\Filament\Ims\Office\Resources\SupportThreads\Pages;

use App\Filament\Ims\Office\Resources\SupportThreads\SupportThreadResource;
use App\Ims\Support\SupportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSupportThreads extends ListRecords
{
    protected static string $resource = SupportThreadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importOld')
                ->label(__('Bring in old portal questions'))
                ->color('gray')
                ->icon('heroicon-o-arrow-down-on-square')
                ->visible(fn (): bool => auth()->user()?->hasPermission('ims.support.reply') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('Questions sent from the old Requests page become conversations here. Nothing is deleted, and each one is copied only once.'))
                ->action(function (): void {
                    $made = app(SupportService::class)->importOldRequests();
                    Notification::make()->title(__(':count questions brought in', ['count' => $made]))->success()->send();
                }),
        ];
    }
}
