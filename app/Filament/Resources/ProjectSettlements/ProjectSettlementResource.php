<?php

namespace App\Filament\Resources\ProjectSettlements;

use App\Filament\Clusters\Investments;
use App\Filament\Resources\InvestmentProjects\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\ProjectSettlements\Pages\ListProjectSettlements;
use App\Filament\Resources\ProjectSettlements\Pages\ViewProjectSettlement;
use App\Filament\Resources\ProjectSettlements\RelationManagers\ChannelPartnerPayoutsRelationManager;
use App\Filament\Resources\ProjectSettlements\RelationManagers\PayoutsRelationManager;
use App\Models\ProjectSettlement;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectSettlementResource extends Resource
{
    protected static ?string $model = ProjectSettlement::class;

    protected static ?string $cluster = Investments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Settlement Summary')->schema([
                TextEntry::make('project.project_code')->label('Project Code'),
                TextEntry::make('project.name')->label('Project'),
                TextEntry::make('basis')
                    ->label(__('Amount decided'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === ProjectSettlement::BASIS_MANUAL ? __('Profit entered by hand') : __('From the sales revenue'))
                    ->color(fn (?string $state): string => $state === ProjectSettlement::BASIS_MANUAL ? 'warning' : 'gray'),
                // A hand-entered profit has no real selling amount, so none is shown.
                TextEntry::make('total_revenue')->moneyWithoutTrailingZeroes('BDT')
                    ->visible(fn (ProjectSettlement $record): bool => ! $record->isManualBasis()),
                TextEntry::make('landed_cost')
                    ->label('Total Landed Cost')
                    ->state(fn (ProjectSettlement $record): float => $record->project->totalLandedCost())
                    ->moneyWithoutTrailingZeroes('BDT'),
                // Selling − landed cost only (v3 P3) -- same figure already
                // shown on the printable per-investor report (P1.2), just
                // missing here in the admin infolist until now.
                TextEntry::make('gross_profit')
                    ->label('Gross Profit (Selling − Landed Cost)')
                    ->state(fn (ProjectSettlement $record): float => round((float) $record->total_revenue - $record->project->totalLandedCost(), 2))
                    ->visible(fn (ProjectSettlement $record): bool => ! $record->isManualBasis())
                    ->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('local_expense')
                    ->label('Total Local Expense')
                    ->state(fn (ProjectSettlement $record): float => $record->project->totalLocalExpense())
                    ->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('total_cost')->label('Total Direct Cost')->moneyWithoutTrailingZeroes('BDT'),
                TextEntry::make('net_profit')->moneyWithoutTrailingZeroes('BDT')->badge()
                    ->color(fn (ProjectSettlement $record): string => $record->net_profit < 0 ? 'danger' : 'success'),
                TextEntry::make('outcome')->badge()
                    ->formatStateUsing(fn (string $state): string => ProjectSettlement::OUTCOMES[$state] ?? $state)
                    ->color(fn (string $state): string => $state === 'profit' ? 'success' : 'danger'),
                TextEntry::make('loss_reason')->placeholder('-')->visible(fn (ProjectSettlement $record): bool => $record->outcome !== 'profit')->columnSpanFull(),
                TextEntry::make('investor_pool_amount')->moneyWithoutTrailingZeroes('BDT')->label(fn (ProjectSettlement $record): string => "Investor Pool ({$record->project->investor_share_percent}%)"),
                TextEntry::make('channel_partner_amount')->moneyWithoutTrailingZeroes('BDT')->label(fn (ProjectSettlement $record): string => "Channel Partner ({$record->project->channel_partner_share_percent}% eligible share)"),
                TextEntry::make('company_net_amount')->moneyWithoutTrailingZeroes('BDT')->label('Company Net (includes unallocated channel share)'),
                TextEntry::make('annualized_return_percent')
                    ->label('Annualized Investor Return')
                    ->suffix('%')->badge()->placeholder('Not available')
                    ->helperText('Investor pool ÷ মোট মূলধন, ৩৬০-দিন বছরে annualized।'),
                TextEntry::make('rate_per_lac')
                    ->label('Rate per Lac')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->placeholder('-')
                    ->helperText('প্রতি ১ লাখ বিনিয়োগে investor-এর profit।'),
                TextEntry::make('status')->badge(),
                TextEntry::make('settledBy.name')->label('Settled By'),
                TextEntry::make('settled_at')->dateTime(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('project.project_code')->label('Project')->searchable(),
            TextColumn::make('project.name')->searchable(),
            TextColumn::make('net_profit')->moneyWithoutTrailingZeroes('BDT')->sortable()
                ->color(fn (ProjectSettlement $record): string => $record->net_profit < 0 ? 'danger' : 'gray'),
            TextColumn::make('investor_pool_amount')->moneyWithoutTrailingZeroes('BDT'),
            TextColumn::make('company_net_amount')->moneyWithoutTrailingZeroes('BDT'),
            TextColumn::make('outcome')->badge()
                ->formatStateUsing(fn (string $state): string => ProjectSettlement::OUTCOMES[$state] ?? $state)
                ->color(fn (string $state): string => $state === 'profit' ? 'success' : 'danger')
                ->toggleable(),
            TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                'draft' => 'warning', 'paid_out' => 'success', default => 'gray',
            }),
            TextColumn::make('settled_at')->dateTime()->sortable(),
        ])->recordActions([ViewAction::make()]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [PayoutsRelationManager::class, ChannelPartnerPayoutsRelationManager::class, DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListProjectSettlements::route('/'), 'view' => ViewProjectSettlement::route('/{record}')];
    }
}
