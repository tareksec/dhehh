<?php

namespace App\Filament\Ims\Office\Resources\FeedPosts\RelationManagers;

use App\Ims\Feed\FeedService;
use App\Models\FeedComment;
use App\Support\ImsNumbers;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** A post's comments with their writers (office only; investors see them without names). */
class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Comments');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('account'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('account.name')->label(__('Written by'))->placeholder('—'),
                TextColumn::make('body')->label(__('Comment'))->wrap(),
                TextColumn::make('created_at')->label(__('When'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
                TextColumn::make('hidden_at')->label(__('Hidden'))->formatStateUsing(fn ($state): string => $state ? __('Hidden') : '')->badge()->color('gray')->placeholder(''),
            ])
            ->recordActions([
                Action::make('hide')
                    ->label(__('Hide'))
                    ->color('danger')
                    ->visible(fn (FeedComment $record): bool => $record->hidden_at === null && (auth()->user()?->hasPermission('ims.feed.moderate') ?? false))
                    ->requiresConfirmation()
                    ->action(function (FeedComment $record): void {
                        app(FeedService::class)->hide($record, auth()->user());
                        Notification::make()->title(__('Comment hidden'))->success()->send();
                    }),
            ]);
    }
}
