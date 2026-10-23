<?php

namespace App\Filament\Resources\DeploymentErrors;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\DeploymentErrors\Pages\ListDeploymentErrors;
use App\Filament\Resources\DeploymentErrors\Pages\ViewDeploymentError;
use App\Models\DeploymentError;
use App\Support\ClipboardCopy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;

/**
 * The durable side of the deploy-error-notification feature: every deploy/
 * migration failure DeploymentErrorReporter catches lands here permanently,
 * so the full log is still recoverable after its bell notification has been
 * cleared or marked read. See App\Services\DeploymentErrorReporter and
 * App\Console\Commands\DeployMigrate.
 */
class DeploymentErrorResource extends Resource
{
    protected static ?string $model = DeploymentError::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $cluster = Settings::class;

    protected static ?string $navigationLabel = 'Deploy Error Logs';

    /** Reached from Settings → Logs (App\Filament\Pages\Logs), not from the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'source';

    public static function canViewAny(): bool
    {
        return SchemaFacade::hasTable('deployment_errors') && (Auth::user()?->isSuperAdmin() ?? false);
    }

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
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Error Details')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('occurred_at')->label('Occurred')->dateTime(),
                        TextEntry::make('source')->label('Deploy Step')->badge()->color('danger'),
                        TextEntry::make('message')->label('Message')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Full Log')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('details')
                            ->label('')
                            ->fontFamily('mono')
                            ->copyable()
                            ->copyMessage('Copied to clipboard')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('Occurred')->dateTime()->sortable(),
                TextColumn::make('source')->label('Deploy Step')->searchable()->badge()->color('danger'),
                TextColumn::make('message')->limit(60),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                Action::make('copyLog')
                    ->label('Copy Log')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->color('gray')
                    ->alpineClickHandler(fn (DeploymentError $record): string => ClipboardCopy::alpineHandler($record->details)),
                ViewAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeploymentErrors::route('/'),
            'view' => ViewDeploymentError::route('/{record}'),
        ];
    }
}
