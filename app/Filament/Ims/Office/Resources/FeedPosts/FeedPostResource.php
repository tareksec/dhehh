<?php

namespace App\Filament\Ims\Office\Resources\FeedPosts;

use App\Filament\Ims\Office\Resources\FeedPosts\Pages\ListFeedPosts;
use App\Filament\Ims\Office\Resources\FeedPosts\Pages\TrashedFeedPosts;
use App\Filament\Ims\Office\Resources\FeedPosts\Pages\ViewFeedPost;
use App\Filament\Ims\Office\Resources\FeedPosts\RelationManagers\CommentsRelationManager;
use App\Filament\Ims\Office\Resources\FeedPosts\RelationManagers\ViewsRelationManager;
use App\Models\FeedPost;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The investors' feed in the office (IMS-V2/PRD.md FR-63): write, pin, see who saw, moderate comments. */
class FeedPostResource extends Resource
{
    protected static ?string $model = FeedPost::class;

    protected static ?string $slug = 'feed';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('post');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Feed');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('ims.feed.write') || $user?->hasPermission('ims.feed.moderate')) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    // Whoever may write posts (and so delete them) may restore one or delete it for good from the Trash page.
    public static function canRestore(Model $record): bool
    {
        return static::canRestoreAny();
    }

    public static function canRestoreAny(): bool
    {
        return auth()->user()?->hasPermission('ims.feed.write') ?? false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::canRestoreAny();
    }

    public static function canForceDeleteAny(): bool
    {
        return static::canRestoreAny();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title')->label(__('Title'))->placeholder('—'),
            TextEntry::make('body')->label(__('Text'))->columnSpanFull(),
            TextEntry::make('published_at')->label(__('Posted on'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
            IconEntry::make('is_pinned')->label(__('Pinned'))->boolean(),
            IconEntry::make('comments_enabled')->label(__('Comments open'))->boolean(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['views', 'likes', 'comments']))
            ->columns([
                TextColumn::make('title')->label(__('Post'))->state(fn (FeedPost $record): string => (string) ($record->title ?: mb_strimwidth($record->body, 0, 60, '…')))->wrap(),
                IconColumn::make('is_pinned')->label(__('Pinned'))->boolean(),
                TextColumn::make('views_count')->label(__('Seen by')),
                TextColumn::make('likes_count')->label(__('Likes')),
                TextColumn::make('comments_count')->label(__('Comments')),
                TextColumn::make('published_at')->label(__('Posted on'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
            ])
            ->recordActions([
                ViewAction::make()->label(__('Open')),
                Action::make('pin')
                    ->label(fn (FeedPost $record): string => $record->is_pinned ? __('Unpin') : __('Pin'))
                    ->icon('heroicon-o-bookmark')
                    ->visible(fn (): bool => auth()->user()?->hasPermission('ims.feed.write') ?? false)
                    ->action(fn (FeedPost $record) => $record->update(['is_pinned' => ! $record->is_pinned])),
                Action::make('comments')
                    ->label(fn (FeedPost $record): string => $record->comments_enabled ? __('Close comments') : __('Open comments'))
                    ->icon('heroicon-o-chat-bubble-left')
                    ->visible(fn (): bool => auth()->user()?->hasPermission('ims.feed.moderate') ?? false)
                    ->action(fn (FeedPost $record) => $record->update(['comments_enabled' => ! $record->comments_enabled])),
                DeleteAction::make()->visible(fn (): bool => auth()->user()?->hasPermission('ims.feed.write') ?? false),
            ])
            ->emptyStateHeading(__('Nothing posted yet'))
            ->emptyStateDescription(__('Press "Write a post" to share news with every investor.'));
    }

    public static function getRelations(): array
    {
        return [CommentsRelationManager::class, ViewsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedPosts::route('/'),
            'trash' => TrashedFeedPosts::route('/trash'),
            'view' => ViewFeedPost::route('/{record}'),
        ];
    }
}
