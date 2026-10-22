<?php

namespace App\Filament\Ims\Office\Resources\ProjectUpdates;

use App\Filament\Ims\Office\Resources\ProjectUpdates\Pages\ListProjectUpdates;
use App\Ims\Transparency\ProjectUpdateService;
use App\Models\ProjectUpdate;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Project news for investors (IMS-V2/PRD.md FR-30). */
class ProjectUpdateResource extends Resource
{
    protected static ?string $model = ProjectUpdate::class;

    protected static ?string $slug = 'project-updates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('project update');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Project updates');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('ims.updates.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('project')->withCount('photos'))
            ->columns([
                TextColumn::make('project.name')->label(__('Project'))->wrap(),
                TextColumn::make('title')->label(__('Title'))->wrap()->searchable(),
                IconColumn::make('visible_to_investors')->label(__('Investors see it'))->boolean(),
                TextColumn::make('photos_count')->label(__('Photos')),
                TextColumn::make('created_at')->label(__('Written on'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
            ])
            ->filters([
                SelectFilter::make('project_id')->label(__('Project'))->relationship('project', 'name'),
            ])
            ->recordActions([
                Action::make('show')
                    ->label(__('Show to investors'))
                    ->icon('heroicon-o-eye')
                    ->visible(fn (ProjectUpdate $record): bool => ! $record->visible_to_investors)
                    ->requiresConfirmation()
                    ->modalDescription(__('The project\'s investors will see this update and be told. Yes, show it?'))
                    ->action(function (ProjectUpdate $record): void {
                        app(ProjectUpdateService::class)->show($record, auth()->user());
                        Notification::make()->title(__('Investors can see it now'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('No update yet'))
            ->emptyStateDescription(__('Press "Write an update" to tell investors how their project is going.'));
    }

    public static function getPages(): array
    {
        return ['index' => ListProjectUpdates::route('/')];
    }
}
