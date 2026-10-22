<?php

namespace App\Filament\Resources\ProjectSettlements\Pages;

use App\Filament\Resources\InvestmentProjects\InvestmentProjectResource;
use App\Filament\Resources\ProjectSettlements\ProjectSettlementResource;
use App\Services\Investment\SettlementService;
use App\Support\ImsPanelLinks;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectSettlement extends ViewRecord
{
    protected static string $resource = ProjectSettlementResource::class;

    protected function getHeaderActions(): array
    {
        $canSettle = fn (): bool => auth()->user()?->hasPermission('investments.settle') ?? false;

        return [
            Action::make('register')
                ->label('Investor Register')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->url(fn (): string => ImsPanelLinks::route('investments.reports.project-register', $this->record->project_id))
                ->openUrlInNewTab(),

            Action::make('confirm')
                ->label('Confirm Settlement')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === 'draft' && $canSettle())
                ->requiresConfirmation()
                ->modalDescription('This locks the settlement figures. Payouts can then be marked paid. Amounts can no longer be changed — only a void (before any payout is paid) can undo it.')
                ->action(function (): void {
                    app(SettlementService::class)->confirmSettlement($this->record, (int) auth()->id());
                    Notification::make()->success()->title('Settlement confirmed')->send();
                    $this->redirect(ProjectSettlementResource::getUrl('view', ['record' => $this->record]));
                }),

            Action::make('void')
                ->label('Void Settlement')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status !== 'paid_out' && ! $this->record->hasPaidPayouts() && (auth()->user()?->hasPermission('ims.settlement.void') ?? false))
                ->schema([
                    // IMS v2 (PRD FR-48): Super Admin only, a reason of 10 letters or more.
                    Textarea::make('reason')->label('Void reason')->rows(2)->required()->minLength(10),
                ])
                ->requiresConfirmation()
                ->modalDescription('This deletes the settlement and its payout schedule and re-opens the project as "closed" so it can be settled again. Only possible while no payout has been paid.')
                ->action(function (array $data): void {
                    $projectId = $this->record->project_id;
                    app(SettlementService::class)->voidSettlement($this->record, (string) $data['reason'], (int) auth()->id());
                    Notification::make()->success()->title('Settlement voided')->body('The project is re-openable for a fresh settlement.')->send();
                    $this->redirect(InvestmentProjectResource::getUrl('view', ['record' => $projectId]));
                }),
        ];
    }
}
