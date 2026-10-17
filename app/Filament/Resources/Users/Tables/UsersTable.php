<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => User::ROLES[$state] ?? str($state ?? '')->headline()->toString())
                    ->sortable(),

                TextColumn::make('companies.name')
                    ->label('Companies')
                    ->badge()
                    ->separator(',')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')->options(User::ROLES),
                TernaryFilter::make('is_active')->label('Active status'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Checked per selected user, so a Super Admin in the
                    // selection is skipped for anyone who isn't one.
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
