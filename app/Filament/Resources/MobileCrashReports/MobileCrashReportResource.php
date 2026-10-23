<?php

namespace App\Filament\Resources\MobileCrashReports;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\MobileCrashReports\Pages\ListMobileCrashReports;
use App\Filament\Resources\MobileCrashReports\Pages\ViewMobileCrashReport;
use App\Models\MobileCrashReport;
use BackedEnum;
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

class MobileCrashReportResource extends Resource
{
    protected static ?string $model = MobileCrashReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $cluster = Settings::class;

    protected static ?string $navigationLabel = 'Mobile Crash Reports';

    /** Reached from Settings → Logs (App\Filament\Pages\Logs), not from the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'exception_class';

    public static function canViewAny(): bool
    {
        return SchemaFacade::hasTable('mobile_crash_reports') && (Auth::user()?->isSuperAdmin() ?? false);
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
                Section::make('Crash Details')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('created_at')->label('Received')->dateTime(),
                        TextEntry::make('occurred_at')->label('Occurred (device clock)')->dateTime()->placeholder('-'),
                        TextEntry::make('exception_class')->label('Exception'),
                        TextEntry::make('message')->label('Message')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('app_version_name')->label('App Version')->placeholder('-'),
                        TextEntry::make('app_version_code')->label('Version Code')->placeholder('-'),
                        TextEntry::make('os_version')->label('Android Version')->placeholder('-'),
                        TextEntry::make('device_manufacturer')->label('Manufacturer')->placeholder('-'),
                        TextEntry::make('device_model')->label('Model')->placeholder('-'),
                        TextEntry::make('ip_address')->label('IP Address')->placeholder('-'),
                    ])
                    ->columns(3),

                Section::make('Stack Trace')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('stack_trace')
                            ->label('')
                            ->fontFamily('mono')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
                TextColumn::make('exception_class')->label('Exception')->searchable()->badge()->color('danger'),
                TextColumn::make('message')->limit(60)->placeholder('-'),
                TextColumn::make('app_version_name')->label('App Version')->placeholder('-')->toggleable(),
                TextColumn::make('os_version')->label('Android')->placeholder('-')->toggleable(),
                TextColumn::make('device_model')->label('Device')->placeholder('-')->toggleable(),
                TextColumn::make('ip_address')->label('IP')->placeholder('-')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
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
            'index' => ListMobileCrashReports::route('/'),
            'view' => ViewMobileCrashReport::route('/{record}'),
        ];
    }
}
