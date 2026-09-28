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
