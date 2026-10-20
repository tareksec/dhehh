<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\CombinedPortalTable;
use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Services\InvestorPortfolio;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Page 5 of §6.1: the person's own papers (NID copy, signed contract, cheque
 * copy, partner agreement) and the shared papers of the projects they have
 * money in (contract and cost evidence; see
 * InvestorPortalService::SHARED_PROJECT_DOCUMENT_CATEGORIES). Every download
 * goes through InvestorPortalDocumentController, which checks the same scope.
 * From every company the person is in, with a Project filter
 * (18_IMS_ALL_COMPANIES_PLAN.md §4); another company's paper downloads after
 * opening the person's own account there (?business=).
 */
class Documents extends Page implements HasTable
{
    use CombinedPortalTable;
    use InteractsWithTable;
    use InvestorPortalPage;

    protected static ?string $portalRole = 'holder';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.investor.pages.table';

    public static function getNavigationGroup(): ?string
    {
        return __('Account');
    }

    public static function getNavigationLabel(): string
    {
        return __('Documents');
    }

    public function getTitle(): string
    {
        return __('Documents');
    }

    public function table(Table $table): Table
    {
        // Every company's papers in one list (18_IMS_ALL_COMPANIES_PLAN.md §4, phase E).
        return $table
            ->records(fn (?array $filters, int|string $page, int|string $recordsPerPage) => $this->pageOfRows(
                app(InvestorPortfolio::class)->documents($this->account(), $this->chosenProject($filters)),
                $page,
                $recordsPerPage,
            ))
            ->filters([$this->projectFilter()])
            ->columns([
                TextColumn::make('label')
                    ->label(__('Document'))
                    ->wrap(),
                TextColumn::make('about')
                    ->label(__('About'))
                    ->description(fn (array $record): ?string => $this->showsCompanies() ? $record['company_name'] : null)
                    ->wrap(),
                TextColumn::make('created_at')->label(__('Added'))->date('d M Y')->visibleFrom('md'),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('Download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (array $record): string => $record['download_url']),
            ])
            ->emptyStateHeading(__('No documents yet.'))
            ->paginated([10, 25, 50]);
    }
}
