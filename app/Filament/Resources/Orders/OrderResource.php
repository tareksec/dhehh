<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Clusters\Sales;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\CostsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\StatusTransitionsRelationManager;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyBangladeshi;

    protected static ?string $cluster = Sales::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function canDelete(Model $record): bool
    {
        // A returned or refunded order is never trashed (owner decision 2026-10-15).
        if ($record instanceof Order && ! $record->canBeMovedToTrash()) {
            return false;
        }

        return Auth::user()?->canDeleteSensitiveRecords() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->canDeleteSensitiveRecords() ?? false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return Auth::user()?->canDeleteSensitiveRecords() ?? false;
    }

    public static function canForceDeleteAny(): bool
    {
        return Auth::user()?->canDeleteSensitiveRecords() ?? false;
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
            CostsRelationManager::class,
            StatusTransitionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
