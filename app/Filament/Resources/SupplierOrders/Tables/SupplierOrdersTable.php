<?php

namespace App\Filament\Resources\SupplierOrders\Tables;

use App\Filament\Resources\SupplierOrders\SupplierOrderResource;
use App\Models\SupplierOrder;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SupplierOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier', 'items']))
            ->defaultSort('order_date', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label(__('Order Number'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('supplier.name')
                    ->label(__('Supplier'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('order_date')
                    ->label(__('Order Date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label(__('Customer Name'))
                    ->description(fn (SupplierOrder $record): ?string => $record->customer_phone)
                    ->searchable(['customer_name', 'customer_phone'])
                    ->placeholder(__('Not set')),

                TextColumn::make('total_pieces')
                    ->label(__('Pieces'))
                    ->getStateUsing(fn (SupplierOrder $record): int => $record->totalPieces())
                    ->numeric()
                    ->alignEnd(),

                TextColumn::make('total_amount')
                    ->label(__('Customer pays'))
                    ->getStateUsing(fn (SupplierOrder $record): float => $record->totalAmount())
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->alignEnd(),

                TextColumn::make('advance_amount')
                    ->label(__('Advance'))
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->description(fn (SupplierOrder $record): string => __('with :who', ['who' => __(SupplierOrder::COLLECTED_BY[$record->advance_collected_by] ?? $record->advance_collected_by)]))
                    ->alignEnd(),

                TextColumn::make('taken_value')
                    ->label(__('Off supplier account'))
                    ->getStateUsing(fn (SupplierOrder $record): float => $record->isTaken() ? $record->takenValue() : 0.0)
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __(SupplierOrder::STATUSES[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        SupplierOrder::STATUS_DELIVERED => 'success',
                        SupplierOrder::STATUS_RETURNED, SupplierOrder::STATUS_CANCELLED => 'danger',
                        default => 'info',
                    }),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label(__('Supplier'))
                    ->relationship('supplier', 'name')
                    ->searchable(),

                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(fn (): array => collect(SupplierOrder::STATUSES)
                        ->map(fn (string $label): string => __($label))
                        ->all()),

                Filter::make('order_date')
                    ->label(__('Order Date'))
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('To')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(fn (Collection $records) => $records->each(
                            fn ($record): bool => SupplierOrderResource::deleteSafely($record)
                        )),
                ]),
            ]);
    }
}
