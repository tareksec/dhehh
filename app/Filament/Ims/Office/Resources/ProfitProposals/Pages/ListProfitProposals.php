<?php

namespace App\Filament\Ims\Office\Resources\ProfitProposals\Pages;

use App\Filament\Ims\Office\Resources\ProfitProposals\ProfitProposalResource;
use App\Ims\Profit\ProposalService;
use App\Services\CompanyContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListProfitProposals extends ListRecords
{
    protected static string $resource = ProfitProposalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label(__('Check for finished cycles now'))
                ->color('gray')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => auth()->user()?->hasPermission('ims.proposals.approve') ?? false)
                ->action(function (): void {
                    $made = app(ProposalService::class)->generate(app(CompanyContext::class)->company());
                    Notification::make()->title($made > 0 ? __(':count new proposals', ['count' => $made]) : __('No finished cycle is waiting'))->success()->send();
                }),
        ];
    }
}
