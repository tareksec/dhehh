<?php

namespace App\Filament\Resources\InvestmentProjects\RelationManagers;

use App\Models\ProjectMilestone;
use App\Support\ImsNumbers;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The project's timeline for investors (IMS-V2/PRD.md FR-31): ordered →
 * shipped → arrived → selling → done, or any steps staff write.
 */
class MilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'milestones';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Milestones');
    }

    public function isReadOnly(): bool
    {
        return ! (auth()->user()?->hasPermission('ims.updates.manage') ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label(__('Step'))->required()->maxLength(255),
            DatePicker::make('due_on')->label(__('Planned for')),
            DatePicker::make('done_on')->label(__('Done on')),
            TextInput::make('sort')->label(__('Order'))->numeric()->integer()->default(fn (): int => (int) $this->getOwnerRecord()->milestones()->max('sort') + 1),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->columns([
                TextColumn::make('title')->label(__('Step'))->wrap(),
                TextColumn::make('due_on')->label(__('Planned for'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state))->placeholder('—'),
                IconColumn::make('done')->label(__('Done'))->state(fn (ProjectMilestone $record): bool => $record->isDone())->boolean(),
                TextColumn::make('done_on')->label(__('Done on'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state))->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->label(__('Add a step'))
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'company_id' => $this->getOwnerRecord()->company_id]),
                Action::make('standardSteps')
                    ->label(__('Add the usual steps'))
                    ->color('gray')
                    ->visible(fn (): bool => $this->getOwnerRecord()->milestones()->doesntExist())
                    ->action(function (): void {
                        foreach (['Ordered', 'Shipped', 'Arrived', 'Selling', 'Finished'] as $sort => $title) {
                            $this->getOwnerRecord()->milestones()->create(['company_id' => $this->getOwnerRecord()->company_id, 'title' => __($title), 'sort' => $sort]);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('done')
                    ->label(__('Mark done'))
                    ->icon('heroicon-o-check')
                    ->visible(fn (ProjectMilestone $record): bool => ! $record->isDone())
                    ->action(fn (ProjectMilestone $record) => $record->update(['done_on' => now()->toDateString()])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No steps yet'))
            ->emptyStateDescription(__('Press "Add the usual steps" for ordered → shipped → arrived → selling → finished.'));
    }
}
