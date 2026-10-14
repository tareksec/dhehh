<?php

namespace App\Filament\Resources\InvestorGroups\RelationManagers;

use App\Filament\Resources\InvestmentProjects\InvestmentProjectResource;
use App\Models\InvestmentProject;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The projects a group has run (Project 1, 2, 3 …). Read only: the cycle creates them. */
class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('Projects');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withSum('investments', 'amount'))
            ->defaultSort('sequence_in_group')
            ->paginated(false)
            ->columns([
                TextColumn::make('sequence_in_group')->label(__('Project no.'))->formatStateUsing(fn ($state): string => 'P'.$state),
                TextColumn::make('project_code')->label(__('Project Code'))
                    ->url(fn (InvestmentProject $record): string => InvestmentProjectResource::getUrl('view', ['record' => $record])),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __(InvestmentProject::STATUSES[$state] ?? $state)),
                TextColumn::make('start_date')->label(__('Start date'))->date(),
                TextColumn::make('end_date')->label(__('End date'))->date()->placeholder('-'),
                TextColumn::make('investments_sum_amount')->label(__('Invested'))->moneyWithoutTrailingZeroes('BDT'),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (InvestmentProject $record): string => InvestmentProjectResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
