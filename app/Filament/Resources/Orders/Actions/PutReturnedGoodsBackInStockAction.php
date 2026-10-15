<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-10-15: when an order is returned its goods stay out of
 * stock until staff have looked at them. This records the pieces that are
 * fine as a "Return" stock movement tied to the order (damaged ones are just
 * left out). It can be done in parts and never takes back more than was sold.
 */
class PutReturnedGoodsBackInStockAction
{
    public static function make(): Action
    {
        return Action::make('putReturnedGoodsBackInStock')
            ->label(__('Put returned goods back in stock'))
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->color('warning')
            ->visible(fn (Order $record): bool => static::allowed($record) && static::lines($record) !== [])
            ->modalHeading(fn (Order $record): string => __('Put returned goods back in stock: :order', ['order' => $record->order_number]))
            ->modalDescription(__('Check what came back, then enter how many pieces are in good condition for each item. Only those are added to stock; leave damaged pieces out. You can do this in parts.'))
            ->modalSubmitActionLabel(__('Add to stock'))
            ->schema(fn (Order $record): array => collect(static::lines($record))
                ->map(fn (array $line): TextInput => TextInput::make('qty_'.$line['item']->getKey())
                    ->label($line['label'])
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue($line['remaining'])
                    ->default($line['remaining'])
                    ->required()
                    ->helperText(__('Sold :sold, already put back :back, can still be put back :left.', [
                        'sold' => $line['sold'],
                        'back' => $line['back'],
                        'left' => $line['remaining'],
                    ])))
                ->values()
                ->all())
            ->action(function (Order $record, array $data): void {
                abort_unless(static::allowed($record), 403);

                $added = 0;

                DB::transaction(function () use ($record, $data, &$added): void {
                    // Worked out inside the transaction so two clicks cannot both add the same pieces.
                    $order = Order::query()->lockForUpdate()->findOrFail($record->getKey());

                    foreach (static::lines($order) as $line) {
                        $quantity = min((int) ($data['qty_'.$line['item']->getKey()] ?? 0), $line['remaining']);

                        if ($quantity < 1) {
                            continue;
                        }

                        StockMovement::query()->create([
                            'company_id' => $order->company_id,
                            'product_id' => $line['item']->product_id,
                            'product_variant_id' => $line['item']->product_variant_id,
                            'type' => 'return',
                            'quantity' => $quantity,
                            'reference_type' => Order::class,
                            'reference_id' => $order->getKey(),
                            'note' => "Returned goods checked: invoice {$order->order_number}",
                        ]);

                        $added += $quantity;
                    }
                });

                Notification::make()
                    ->title($added > 0
                        ? __(':count pieces were added back to stock.', ['count' => $added])
                        : __('Nothing was added to stock.'))
                    ->color($added > 0 ? 'success' : 'warning')
                    ->send();
            });
    }

    /**
     * One entry per item still waiting to be put back.
     *
     * @return list<array{item: OrderItem, label: string, sold: int, back: int, remaining: int}>
     */
    protected static function lines(Order $order): array
    {
        if (! $order->keepsStockOutUntilGoodsAreChecked()) {
            return [];
        }

        // Only items whose sale is still in the stock ledger have anything to put back.
        $soldMovements = StockMovement::query()
            ->where('type', 'sale')
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->getKey())
            ->get(['product_id', 'product_variant_id', 'quantity'])
            ->groupBy(fn (StockMovement $movement): string => $movement->product_id.':'.($movement->product_variant_id ?? 0))
            ->map(fn ($group): int => (int) $group->sum(fn (StockMovement $movement): int => abs((int) $movement->quantity)));
        $returned = $order->returnedQuantities();

        return $order->items()->with(['product', 'productVariant'])->get()
            ->groupBy(fn (OrderItem $item): string => $item->product_id.':'.($item->product_variant_id ?? 0))
            ->map(function ($items, string $key) use ($soldMovements, $returned): ?array {
                /** @var OrderItem $first */
                $first = $items->first();
                $sold = (int) ($soldMovements[$key] ?? 0);
                $back = (int) ($returned[$key] ?? 0);
                $remaining = max(0, $sold - $back);

                if ($remaining < 1) {
                    return null;
                }

                return [
                    'item' => $first,
                    'label' => trim(($first->product?->name ?? '#'.$first->product_id).($first->variant_label ? ' ('.$first->variant_label.')' : '')),
                    'sold' => $sold,
                    'back' => $back,
                    'remaining' => $remaining,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected static function allowed(Order $order): bool
    {
        return Auth::user()?->can('create', StockMovement::class) ?? false;
    }
}
