<?php

namespace App\Filament\Ims\Office\Resources\BankStatementImports;

use App\Filament\Ims\Office\Resources\BankStatementImports\Pages\ListBankStatementImports;
use App\Filament\Ims\Office\Resources\BankStatementImports\Pages\ViewBankStatementImport;
use App\Filament\Ims\Office\Resources\BankStatementImports\RelationManagers\LinesRelationManager;
use App\Models\BankStatementImport;
use App\Support\ImsNumbers;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** bKash / Nagad / Rocket / bank statements read in to confirm waiting money (IMS-V2/PRD.md FR-24). */
class BankStatementImportResource extends Resource
{
    protected static ?string $model = BankStatementImport::class;

    protected static ?string $slug = 'statement-matching';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('statement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Statement matching');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('ims.reconcile') || $user?->hasPermission('ims.investments.confirm')) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('source')->label(__('From'))->formatStateUsing(fn (string $state): string => BankStatementImport::SOURCES[$state] ?? $state),
            TextEntry::make('file_name')->label(__('File')),
            TextEntry::make('lines_count')->label(__('Money-in lines')),
            TextEntry::make('created_at')->label(__('Read in on'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('source')->label(__('From'))->formatStateUsing(fn (string $state): string => BankStatementImport::SOURCES[$state] ?? $state)->badge(),
                TextColumn::make('file_name')->label(__('File'))->wrap(),
                TextColumn::make('lines_count')->label(__('Money-in lines')),
                TextColumn::make('matched_count')->label(__('Possible matches')),
                TextColumn::make('created_at')->label(__('Read in on'))->formatStateUsing(fn ($state): string => ImsNumbers::date($state)),
            ])
            ->recordActions([ViewAction::make()->label(__('Open'))])
            ->emptyStateHeading(__('No statement read in yet'))
            ->emptyStateDescription(__('Press "Read a statement" and upload the CSV or Excel file from bKash, Nagad, Rocket or the bank.'));
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankStatementImports::route('/'),
            'view' => ViewBankStatementImport::route('/{record}'),
        ];
    }
}
