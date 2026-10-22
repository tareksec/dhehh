<?php

namespace App\Filament\Ims\Office\Resources\InvestorInvites\Pages;

use App\Filament\Ims\Office\Resources\InvestorInvites\InvestorInviteResource;
use App\Ims\Onboarding\InviteService;
use App\Ims\Settings\ImsSettings;
use App\Services\CompanyContext;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListInvestorInvites extends ListRecords
{
    protected static string $resource = InvestorInviteResource::class;

    public function getSubheading(): ?string
    {
        return ImsSettings::get(app(CompanyContext::class)->company(), 'invite_registration_enabled', false)
            ? null
            : __('Joining by invite is switched off in IMS settings, so an invite link cannot be used yet.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('Invite an investor'))
                ->icon('heroicon-o-paper-airplane')
                ->schema([
                    TextInput::make('contact')->label(__('Mobile number or e-mail'))->required()->maxLength(255),
                    TextInput::make('name')->label(__('Name (if you know it)'))->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $result = app(InviteService::class)->invite(app(CompanyContext::class)->company(), (string) $data['contact'], $data['name'] ?? null, auth()->user());

                    Notification::make()
                        ->title($result['sent'] ? __('Invite sent') : __('Invite made, but it could not be sent. Share this link yourself:'))
                        ->body($result['link'])
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
