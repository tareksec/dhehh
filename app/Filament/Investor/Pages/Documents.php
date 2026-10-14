<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\ChannelPartnerPayout;
use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\SettlementPayout;
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
 */
class Documents extends Page implements HasTable
{
    use InteractsWithTable;
    use InvestorPortalPage;

    protected static ?string $portalRole = 'holder';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.investor.pages.table';

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
        return $table
            ->query(fn () => $this->portal()->documents($this->account())->with('documentable'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('category')
                    ->label(__('Document'))
                    ->formatStateUsing(fn (?string $state, InvestmentDocument $record): string => filled($record->label) ? (string) $record->label : InvestmentDocument::categoryLabel($state))
                    ->wrap(),
                TextColumn::make('about')
                    ->label(__('About'))
                    ->state(fn (InvestmentDocument $record): string => match (true) {
                        $record->documentable instanceof InvestmentProject => $this->portal()->projectLabel($record->documentable),
                        $record->documentable instanceof Investment => $this->portal()->projectLabel($record->documentable->project),
                        $record->documentable instanceof SettlementPayout, $record->documentable instanceof ChannelPartnerPayout => __('Payment for :project', ['project' => $this->portal()->projectLabel($record->documentable->settlement?->project)]),
                        default => __('You'),
                    })
                    ->wrap(),
                TextColumn::make('created_at')->label(__('Added'))->date('d M Y')->visibleFrom('md'),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('Download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (InvestmentDocument $record): string => route('filament.investor.documents.download', ['document' => $record->getKey()])),
            ])
            ->emptyStateHeading(__('No documents yet.'))
            ->paginated([10, 25, 50]);
    }
}
