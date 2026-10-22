<?php

namespace App\Filament\Ims\Office\Resources\FeedPosts\Pages;

use App\Filament\Ims\Office\Resources\FeedPosts\FeedPostResource;
use App\Ims\Feed\FeedService;
use App\Models\FeedPost;
use App\Services\CompanyContext;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListFeedPosts extends ListRecords
{
    protected static string $resource = FeedPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('write')
                ->label(__('Write a post'))
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => auth()->user()?->hasPermission('ims.feed.write') ?? false)
                ->schema([
                    Select::make('kind')->label(__('Kind'))->options(collect(FeedPost::KINDS)->map(fn (string $label): string => __($label))->all())->default('post')->required(),
                    TextInput::make('title')->label(__('Title (optional)'))->maxLength(255),
                    Textarea::make('body')->label(__('Text'))->required()->rows(5),
                    TextInput::make('link_url')->label(__('Link (optional)'))->url()->maxLength(500),
                    FileUpload::make('images')->label(__('Pictures (up to 6)'))->image()->multiple()->maxFiles(FeedPost::MAX_IMAGES)->storeFiles(false)
                        ->imageResizeMode('contain')->imageResizeTargetWidth('1600'),
                    Toggle::make('is_pinned')->label(__('Pin at the top')),
                    Toggle::make('comments_enabled')->label(__('Investors may comment'))->default(true),
                ])
                ->action(function (array $data): void {
                    app(FeedService::class)->post(app(CompanyContext::class)->company(), $data, array_values((array) ($data['images'] ?? [])), auth()->user());
                    Notification::make()->title(__('Posted'))->success()->send();
                }),
        ];
    }
}
