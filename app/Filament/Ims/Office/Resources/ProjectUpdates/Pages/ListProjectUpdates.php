<?php

namespace App\Filament\Ims\Office\Resources\ProjectUpdates\Pages;

use App\Filament\Ims\Office\Resources\ProjectUpdates\ProjectUpdateResource;
use App\Ims\Transparency\ProjectUpdateService;
use App\Models\InvestmentProject;
use App\Models\ProjectUpdate;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListProjectUpdates extends ListRecords
{
    protected static string $resource = ProjectUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('write')
                ->label(__('Write an update'))
                ->icon('heroicon-o-pencil-square')
                ->schema([
                    Select::make('project_id')->label(__('Project'))
                        ->options(fn (): array => InvestmentProject::query()->where('status', '!=', 'settled')->orderByDesc('id')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                    TextInput::make('title')->label(__('Title'))->required()->maxLength(255),
                    Textarea::make('body')->label(__('What happened'))->rows(5),
                    TextInput::make('video_url')->label(__('Video link (optional)'))->url()->maxLength(500),
                    FileUpload::make('photos')->label(__('Photos (up to 12)'))->image()->multiple()->maxFiles(ProjectUpdate::MAX_PHOTOS)->storeFiles(false)
                        ->imageResizeMode('contain')->imageResizeTargetWidth('1600'),
                    Toggle::make('visible_to_investors')->label(__('Show to investors now'))->default(true),
                ])
                ->action(function (array $data): void {
                    app(ProjectUpdateService::class)->create($data, array_values((array) ($data['photos'] ?? [])), auth()->user());
                    Notification::make()->title(__('Update saved'))->success()->send();
                }),
        ];
    }
}
