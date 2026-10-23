<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $cluster = Settings::class;

    /** Reached from Settings → Logs (App\Filament\Pages\Logs), not from the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'action';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Audit Information')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('created_at')->label('Date')->dateTime(),
                        TextEntry::make('actor_label')->label('User')->state(fn (AuditLog $record): ?string => $record->actorLabel())->placeholder('System'),
                        TextEntry::make('action')->badge(),
                        TextEntry::make('auditable_type')
                            ->label('Model')
                            ->formatStateUsing(fn (string $state): string => class_basename($state)),
                        TextEntry::make('auditable_id')->label('Record ID'),
                        TextEntry::make('ip_address')->label('IP Address')->placeholder('-'),
                    ])
                    ->columns(3),

                Section::make('Changed Values')
                    ->columnSpanFull()
                    ->schema([
                        KeyValueEntry::make('old_values')
                            ->label('Before')
                            ->placeholder('No previous values'),
                        KeyValueEntry::make('new_values')
                            ->label('After')
                            ->placeholder('No new values'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('User')
                    ->state(fn (AuditLog $record): ?string => $record->actorLabel())
                    ->placeholder('System')
                    ->searchable(),

                TextColumn::make('action')
                    ->badge()
                    ->sortable(),

                TextColumn::make('auditable_type')
                    ->label('Model')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->sortable(),

                TextColumn::make('auditable_id')
                    ->label('Record ID')
                    ->sortable(),

                TextColumn::make('ip_address')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
