<?php

namespace App\Filament\Resources\ExpenseScans;

use App\Filament\Clusters\Finance;
use App\Filament\Resources\ExpenseScans\Pages\CreateExpenseScan;
use App\Filament\Resources\ExpenseScans\Pages\ListExpenseScans;
use App\Filament\Resources\ExpenseScans\Pages\ReviewExpenseScan;
use App\Filament\Resources\ExpenseScans\Pages\ViewExpenseScan;
use App\Filament\Resources\ExpenseScans\Schemas\ExpenseScanForm;
use App\Filament\Resources\ExpenseScans\Schemas\ExpenseScanInfolist;
use App\Filament\Resources\ExpenseScans\Tables\ExpenseScansTable;
use App\Models\ExpenseScan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Smart Scan: upload a photo of an expense note, let the AI read it
 * into draft lines, review/correct them, then publish them as Expenses.
 * Every page is gated on the `expenses.ai_scan` permission (owner's call —
 * only roles granted it can upload, review or publish).
 */
class ExpenseScanResource extends Resource
{
    protected static ?string $model = ExpenseScan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static ?string $cluster = Finance::class;

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('Smart Scans');
    }

    public static function getModelLabel(): string
    {
        return __('smart scan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('smart scans');
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseScanForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseScanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpenseScansTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->canUseExpenseScan() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && $record instanceof ExpenseScan && ! $record->isPublished();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny() && $record instanceof ExpenseScan && ! $record->isPublished();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseScans::route('/'),
            'create' => CreateExpenseScan::route('/create'),
            'view' => ViewExpenseScan::route('/{record}'),
            'edit' => ReviewExpenseScan::route('/{record}/review'),
        ];
    }
}
