<?php

namespace App\Filament\Resources\Investors\Pages;

use App\Filament\Resources\InvestorDrafts\InvestorDraftResource;
use App\Filament\Resources\Investors\InvestorResource;
use App\Models\InvestorDraft;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListInvestors extends ListRecords
{
    protected static string $resource = InvestorResource::class;

    /**
     * The project picker on the table toolbar. It writes into the same filter
     * state as the "Project" filter in the filter menu, so the filter badge,
     * the active-filter chips and "Reset filters" all follow it.
     */
    public function setProjectFilter(?string $projectId): void
    {
        $values = [];

        if (filled($projectId) && array_key_exists((int) $projectId, InvestorResource::projectFilterOptions())) {
            $values = [(string) (int) $projectId];
        }

        data_set($this->tableFilters, 'project.values', $values);
        data_set($this->tableDeferredFilters, 'project.values', $values);

        $this->handleTableFilterUpdates();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importInvestors')
                ->label(fn (): string => ($drafts = InvestorDraft::query()->count()) > 0
                    ? __('Import / drafts (:count)', ['count' => $drafts])
                    : __('Import from Excel / CSV / text'))
                ->icon(Heroicon::OutlinedDocumentArrowUp)
                ->color('gray')
                ->visible(fn (): bool => InvestorDraftResource::canViewAny())
                ->url(fn (): string => InvestorDraftResource::getUrl('index')),
            CreateAction::make(),
        ];
    }
}
