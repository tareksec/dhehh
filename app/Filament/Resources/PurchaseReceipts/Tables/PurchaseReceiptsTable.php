<?php

namespace App\Filament\Resources\PurchaseReceipts\Tables;

use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Models\PurchaseReceipt;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PurchaseReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier', 'items']))
            ->defaultSort('received_on', 'desc')
            ->columns([
                TextColumn::make('receipt_number')
                    ->label(__('Receipt Number'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label(__('Supplier'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('received_on')
                    ->label(__('Received On'))
                    ->date()
                    ->sortable(),

                TextColumn::make('reference')
                    ->label(__('Supplier Challan / Reference'))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('total_pieces')
                    ->label(__('Received (pcs)'))
                    ->getStateUsing(fn (PurchaseReceipt $record): int => $record->totalPieces())
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('total_value')
                    ->label(__('Value'))
                    ->getStateUsing(fn (PurchaseReceipt $record): float => $record->totalValue())
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->alignEnd(),

                IconColumn::make('is_opening')
                    ->label(__('Opening receipt'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label(__('Supplier'))
                    ->relationship('supplier', 'name')
                    ->searchable(),

                Filter::make('received_on')
                    ->label(__('Received On'))
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('To')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('received_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('received_on', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(fn (Collection $records) => $records->each(
                            fn ($record): bool => PurchaseReceiptResource::deleteSafely($record)
                        )),
                ]),
            ]);
    }
}
