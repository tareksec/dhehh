<?php

namespace App\Filament\Resources\PurchaseReceipts;

use App\Filament\Clusters\Purchasing;
use App\Filament\Resources\PurchaseReceipts\Pages\CreatePurchaseReceipt;
use App\Filament\Resources\PurchaseReceipts\Pages\EditPurchaseReceipt;
use App\Filament\Resources\PurchaseReceipts\Pages\ListPurchaseReceipts;
use App\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Filament\Resources\PurchaseReceipts\Schemas\PurchaseReceiptForm;
use App\Filament\Resources\PurchaseReceipts\Schemas\PurchaseReceiptInfolist;
use App\Filament\Resources\PurchaseReceipts\Tables\PurchaseReceiptsTable;
use App\Models\PurchaseReceipt;
use App\Support\SafeDelete;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PurchaseReceiptResource extends Resource
{
    protected static ?string $model = PurchaseReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $cluster = Purchasing::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'receipt_number';

    public static function getNavigationLabel(): string
    {
        return __('Goods Receipts');
    }

    public static function getModelLabel(): string
    {
        return __('Goods Receipt');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Goods Receipts');
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseReceiptForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseReceiptInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseReceiptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseReceipts::route('/'),
            'create' => CreatePurchaseReceipt::route('/create'),
            'view' => ViewPurchaseReceipt::route('/{record}'),
            'edit' => EditPurchaseReceipt::route('/{record}/edit'),
        ];
    }

    /**
     * Deleting a receipt takes its stock back out; that is refused when the
     * goods were already sold, so show the reason instead of failing silently.
     */
    public static function deleteSafely(Model $record): bool
    {
        return SafeDelete::delete($record, __('This receipt cannot be deleted'));
    }
}
