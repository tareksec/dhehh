<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every order this customer has placed with us, newest first: the date, what was in it, the stage it is in and
 * the money (owner request 2026-11-01). Read-only: an order is managed on the Orders resource, and each row
 * opens it.
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Order History';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['items.product']))
            ->columns([
                TextColumn::make('order_date')
                    ->label('Sale Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('order_number')
                    ->label('Invoice Number')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
                TextColumn::make('items_summary')
                    ->label('Items')
                    ->state(fn (Order $record): string => $record->items
                        ->map(fn (OrderItem $item): string => trim(($item->product?->name ?? __('Deleted product')).($item->variant_label ? ' ('.$item->variant_label.')' : '')).' × '.$item->quantity)
                        ->implode(', '))
                    ->placeholder('-')
                    ->wrap()
                    ->limit(120)
                    ->tooltip(fn (Order $record): ?string => $record->items->isEmpty() ? null : $record->items
                        ->map(fn (OrderItem $item): string => ($item->product?->name ?? __('Deleted product')).' × '.$item->quantity)
                        ->implode("\n")),
                TextColumn::make('stage')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Order $record): string => __(Order::WORKFLOW_STAGES[$record->workflowStage()] ?? str($record->workflowStage())->headline()->toString()))
                    ->color(fn (Order $record): string => match ($record->workflowStage()) {
                        Order::STAGE_CONFIRMED, Order::STAGE_COMPLETED, Order::STAGE_DELIVERED => 'success',
                        Order::STAGE_PROCESSING, Order::STAGE_SHIPPING => 'warning',
                        Order::STAGE_CANCELLED, Order::STAGE_RETURNED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total_amount')
                    ->label('Total Amount')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->sortable(),
                TextColumn::make('due_amount')
                    ->moneyWithoutTrailingZeroes('BDT')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Order::STATUSES),
            ])
            // Newest sale first; the id breaks the tie between orders of the same day.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('order_date')->orderByDesc('id'))
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading(__('This customer has no orders yet'));
    }
}
