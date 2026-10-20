<?php

namespace App\Filament\Ims\Office\Resources\FeedPosts\RelationManagers;

use App\Support\ImsNumbers;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Who saw a post and when (office only, `ims.feed.moderate`). */
class ViewsRelationManager extends RelationManager
{
    protected static string $relationship = 'views';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Seen by');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->hasPermission('ims.feed.moderate') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('account'))
            ->defaultSort('viewed_at', 'desc')
            ->columns([
                TextColumn::make('account.name')->label(__('Investor')),
                TextColumn::make('viewed_at')->label(__('First seen'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
            ]);
    }
}
