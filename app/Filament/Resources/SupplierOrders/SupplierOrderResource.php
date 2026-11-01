<?php

namespace App\Filament\Resources\SupplierOrders;

use App\Filament\Clusters\Purchasing;
use App\Filament\Resources\SupplierOrders\Pages\CreateSupplierOrder;
use App\Filament\Resources\SupplierOrders\Pages\EditSupplierOrder;
use App\Filament\Resources\SupplierOrders\Pages\ListSupplierOrders;
use App\Filament\Resources\SupplierOrders\Pages\ViewSupplierOrder;
use App\Filament\Resources\SupplierOrders\Schemas\SupplierOrderForm;
use App\Filament\Resources\SupplierOrders\Schemas\SupplierOrderInfolist;
use App\Filament\Resources\SupplierOrders\Tables\SupplierOrdersTable;
use App\Models\SupplierOrder;
use App\Support\SafeDelete;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SupplierOrderResource extends Resource
{
    protected static ?string $model = SupplierOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $cluster = Purchasing::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function getNavigationLabel(): string
    {
        return __('Supplier Orders');
    }

    public static function getModelLabel(): string
    {
        return __('Supplier Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Supplier Orders');
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupplierOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierOrders::route('/'),
            'create' => CreateSupplierOrder::route('/create'),
            'view' => ViewSupplierOrder::route('/{record}'),
            'edit' => EditSupplierOrder::route('/{record}/edit'),
        ];
    }

    /**
     * Deleting an order gives its pieces back to stock; show a refusal
     * instead of failing silently.
     */
    public static function deleteSafely(Model $record): bool
    {
        return SafeDelete::delete($record, __('This order cannot be deleted'));
    }
}
