<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockMovement;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 2026-10-15: when an order is returned its goods stay out of
 * stock until staff have looked at them. For every item staff enter how many
 * pieces are fine (a "Return" movement tied to the order puts them back on the
 * shelf) and how many came back damaged. A damaged piece is counted, not
 * shelved: it is booked as a Return and straight away written off as a
 * customer-return "Damage" (so on-hand stock does not change, and the pieces
 * appear in the damage totals labelled as customer-return damage). Pieces that
 * are not entered stay on the list, so this can be done in parts, and it never
 * takes more than was sold.
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
            ->modalDescription(__('Check what came back. For each item enter the pieces in good condition (they are added to stock) and the pieces that came back damaged (they are counted as customer-return damage and not added to stock). Pieces you leave out stay on this list, so you can do this in parts.'))
            ->modalSubmitActionLabel(__('Save'))
            ->schema(fn (Order $record): array => collect(static::lines($record))
                ->map(fn (array $line): Section => Section::make($line['label'])
                    ->description(__('Sold :sold, already checked :back, still to check :left.', [
                        'sold' => $line['sold'],
                        'back' => $line['back'],
                        'left' => $line['remaining'],
                    ]))
                    ->columns(2)
                    ->schema([
                        TextInput::make('good_'.$line['item']->getKey())
                            ->label(__('Good pieces'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue($line['remaining'])
                            ->default($line['remaining'])
                            ->required(),
                        TextInput::make('damaged_'.$line['item']->getKey())
                            ->label(__('Damaged pieces'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue($line['remaining'])
                            ->default(0)
                            ->required()
                            ->rules([
                                fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get, $line): void {
                                    $total = (int) $get('good_'.$line['item']->getKey()) + (int) $value;

                                    if ($total > $line['remaining']) {
                                        $fail(__('Good and damaged pieces together cannot be more than :left.', ['left' => $line['remaining']]));
                                    }
                                },
                            ]),
                    ]))
                ->values()
                ->all())
            ->action(function (Order $record, array $data): void {
                abort_unless(static::allowed($record), 403);

                $good = 0;
                $damaged = 0;

                DB::transaction(function () use ($record, $data, &$good, &$damaged): void {
                    // Worked out inside the transaction so two clicks cannot both take the same pieces.
                    $order = Order::query()->lockForUpdate()->findOrFail($record->getKey());

                    foreach (static::lines($order) as $line) {
                        $key = $line['item']->getKey();
                        $goodQuantity = min(max((int) ($data['good_'.$key] ?? 0), 0), $line['remaining']);
                        $damagedQuantity = min(max((int) ($data['damaged_'.$key] ?? 0), 0), $line['remaining'] - $goodQuantity);

                        if ($goodQuantity > 0) {
                            static::record($order, $line['item'], 'return', $goodQuantity, "Returned goods checked: invoice {$order->order_number}");
                            $good += $goodQuantity;
                        }

                        if ($damagedQuantity > 0) {
                            // Booked in and straight out again: the shelf is unchanged, the damage is counted.
                            static::record($order, $line['item'], 'return', $damagedQuantity, "Returned goods checked (damaged): invoice {$order->order_number}");
                            static::record($order, $line['item'], 'damage', $damagedQuantity, "Customer returned it damaged: invoice {$order->order_number}", [
                                'reason' => "Customer return: invoice {$order->order_number}",
                                'damage_source' => StockMovement::DAMAGE_SOURCE_CUSTOMER_RETURN,
                            ]);
                            $damaged += $damagedQuantity;
                        }
                    }
                });

                $parts = [];

                if ($good > 0) {
                    $parts[] = __(':count pieces were added back to stock.', ['count' => $good]);
                }

                if ($damaged > 0) {
                    $parts[] = __(':count pieces were counted as customer-return damage.', ['count' => $damaged]);
                }

                Notification::make()
                    ->title($parts !== [] ? implode(' ', $parts) : __('Nothing was added to stock.'))
                    ->color($parts !== [] ? 'success' : 'warning')
                    ->send();
            });
    }

    /** @param array<string, mixed> $extra */
    protected static function record(Order $order, OrderItem $item, string $type, int $quantity, string $note, array $extra = []): void
    {
        StockMovement::query()->create([
            'company_id' => $order->company_id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => Order::class,
            'reference_id' => $order->getKey(),
            'note' => $note,
        ] + $extra);
    }

    /**
     * One entry per item still waiting to be checked.
     *
     * @return list<array{item: OrderItem, label: string, sold: int, back: int, remaining: int}>
     */
    protected static function lines(Order $order): array
    {
        if (! $order->keepsStockOutUntilGoodsAreChecked()) {
            return [];
        }

        // Only items whose sale is still in the stock ledger have anything to check.
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
