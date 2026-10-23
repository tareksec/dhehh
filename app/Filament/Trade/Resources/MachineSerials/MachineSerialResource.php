<?php

namespace App\Filament\Trade\Resources\MachineSerials;

use App\Filament\Trade\Resources\MachineSerials\Pages\ListMachineSerials;
use App\Models\MachineSerial;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every machine serial number in the Trade Portal, searchable: which item,
 * which LC it came on, and whether it is in the shop or which challan and
 * buyer it left with — to find a buyer's machine for a repair or a claim.
 * Serials are written on the LC file and chosen on the challan.
 */
class MachineSerialResource extends Resource
{
    protected static ?string $model = MachineSerial::class;

    protected static ?string $slug = 'serials';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('serial number');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Serial numbers');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('trade.lc') || $user?->hasPermission('trade.sales')) ?? false;
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
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('serial_number')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'purchase', 'challan']))
            ->columns([
                TextColumn::make('serial_number')->label(__('Serial no.'))->searchable()->sortable()->weight('bold'),
                TextColumn::make('product.name')->label(__('Item'))->searchable()->wrap(),
                TextColumn::make('purchase.lc_number')->label(__('LC no.'))->placeholder('—')->searchable(),
                TextColumn::make('where')
                    ->label(__('Where'))
                    ->state(fn (MachineSerial $record): string => $record->isSold()
                        ? __('Challan :number, :buyer', ['number' => $record->challan?->challan_number, 'buyer' => $record->challan?->buyer_name])
                        : __('In the shop'))
                    ->wrap(),
            ])
            ->filters([
                TernaryFilter::make('sold')
                    ->label(__('Sold'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('delivery_challan_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('delivery_challan_id'),
                    ),
            ])
            ->emptyStateHeading(__('No serial number yet'))
            ->emptyStateDescription(__('Write serial numbers on an LC file (button "Serial numbers"); choose them on the challan when a machine is sold.'));
    }

    public static function getPages(): array
    {
        return ['index' => ListMachineSerials::route('/')];
    }
}
