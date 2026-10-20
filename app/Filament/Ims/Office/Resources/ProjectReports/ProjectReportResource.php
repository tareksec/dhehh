<?php

namespace App\Filament\Ims\Office\Resources\ProjectReports;

use App\Filament\Ims\Office\Resources\ProjectReports\Pages\ListProjectReports;
use App\Ims\Reports\ProjectReportService;
use App\Models\ProjectReport;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Project reports (IMS-V2/PRD.md FR-36): draft → publish (frozen, PDF, investors told). */
class ProjectReportResource extends Resource
{
    protected static ?string $model = ProjectReport::class;

    protected static ?string $slug = 'project-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('project report');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Project reports');
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
            ->modifyQueryUsing(fn ($query) => $query->with('project'))
            ->columns([
                TextColumn::make('project.name')->label(__('Project'))->wrap(),
                TextColumn::make('title')->label(__('Title'))->wrap(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => $state === ProjectReport::STATUS_PUBLISHED ? __('Published') : __('Draft'))
                    ->color(fn (string $state): string => $state === ProjectReport::STATUS_PUBLISHED ? 'success' : 'gray'),
                TextColumn::make('published_at')->label(__('Published on'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state))->placeholder('—'),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label(__('Publish'))
                    ->color('success')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (ProjectReport $record): bool => ! $record->isPublished())
                    ->requiresConfirmation()
                    ->modalDescription(__('The figures are frozen as they are now, the report can never be changed or deleted, and the project\'s investors are told. Yes, publish?'))
                    ->action(function (ProjectReport $record): void {
                        app(ProjectReportService::class)->publish($record, auth()->user());
                        Notification::make()->title(__('Report published'))->success()->send();
                    }),
                Action::make('pdf')
                    ->label(__('PDF'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (ProjectReport $record): bool => $record->isPublished())
                    ->url(fn (ProjectReport $record): string => route('filament.office.files.project-report', $record))
                    ->openUrlInNewTab(),
                DeleteAction::make()->visible(fn (ProjectReport $record): bool => ! $record->isPublished()),
            ])
            ->emptyStateHeading(__('No report yet'))
            ->emptyStateDescription(__('Press "New report" to write one for a project\'s investors.'));
    }

    public static function getPages(): array
    {
        return ['index' => ListProjectReports::route('/')];
    }
}
