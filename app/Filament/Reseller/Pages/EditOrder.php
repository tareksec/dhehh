<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Concerns\ForApprovedResellers;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ResellerOrderPlacementService;
use App\Services\ResellerOwnCourierService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Reseller portal: the reseller changes their own-courier order (owner rules,
 * 2026-10-06) -- add products, raise quantities, change their customer's
 * prices, correct the courier, parcel ID and their customer's details --
 * until the store verifies any of the advance. Nothing already on the order
 * can be removed or lowered, and when the total goes up they send only the
 * extra. Staff cannot change these items (OrderForm). The rules live in
 * ResellerOrderPlacementService::updateOwnCourierOrder().
 */
class EditOrder extends Page
{
    use ForApprovedResellers;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'orders/{record}/edit';

    protected string $view = 'filament.reseller.pages.edit-order';

    #[Locked]
    public int $orderId;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array{key: string, summary: array<string, float>|null}|null summary() of this request, by form lines */
    protected ?array $summaryCache = null;

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'orders.edit';
    }

    public function getTitle(): string
    {
        return __('Change order :number', ['number' => $this->order()->order_number]);
    }

    public function mount(int|string $record): void
    {
        $order = $this->reseller()->resellerOrders()->whereKey((int) $record)->first();

        abort_unless($order instanceof Order && $order->reseller_own_courier, 404);

        $this->orderId = (int) $order->getKey();

        if (! app(ResellerOwnCourierService::class)->canEdit($order)) {
            Notification::make()
                ->title(__('This order can no longer be changed: the store has verified your payment or packed the parcel.'))
                ->warning()
                ->send();
            $this->redirect(Orders::getUrl());

            return;
        }

        $order->load(['customer', 'items']);

        $this->form->fill([
            'name' => $order->customer?->name ?? $order->customer_name,
            'phone' => $order->customer?->phone,
            'address' => $order->customer?->address,
            'courier_name' => $order->reseller_courier_name,
            'parcel_id' => $order->reseller_parcel_id,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'item_id' => $item->getKey(),
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'quantity' => (int) $item->quantity,
                'price' => $item->buyerUnitPrice(),
            ])->all(),
        ]);
    }

    public function order(): Order
    {
        return $this->reseller()->resellerOrders()->with('items.product')->findOrFail($this->orderId);
    }

    public function form(Schema $schema): Schema
    {
        $service = app(ResellerOrderPlacementService::class);
        $reseller = $this->reseller();
        $order = $this->order();
        $items = $order->items->keyBy('id');
        $catalog = $service->catalog($reseller)->keyBy('id');
        // Products already on the order stay listed even if they have left the store since.
        $productNames = $catalog->mapWithKeys(fn (Product $product): array => [
            $product->getKey() => $product->name.' ('.__('Stock').': '.(int) $product->stock.')',
        ])->union($items->mapWithKeys(fn (OrderItem $item): array => [
            $item->product_id => $item->product?->name ?? __('Product'),
        ]))->all();
        $paymentMethods = app(ResellerOwnCourierService::class)->paymentMethods($reseller->company)->keyBy('id');
        // The send-money numbers plus the store's active online gateway.
        $paymentOptions = app(ResellerOwnCourierService::class)->paymentOptions($reseller->company);
        $existing = fn (Get $get): ?OrderItem => filled($get('item_id')) ? $items->get((int) $get('item_id')) : null;
        // The variation a new line has chosen; null for a product without variations.
        $lineVariant = fn (Get $get): ?ProductVariant => ($product = $catalog->get((int) $get('product_id'))) && $product->has_variants
            ? $product->activeVariants->firstWhere('id', (int) $get('variant_id'))
            : null;
        $hasVariants = fn (Get $get): bool => $existing($get) ? $existing($get)->product_variant_id !== null : (bool) $catalog->get((int) $get('product_id'))?->has_variants;
        // Lowest customer price on a line: the store price it was or will be charged at.
        $minPrice = function (Get $get) use ($existing, $catalog, $service, $reseller, $lineVariant, $hasVariants): ?float {
            if ($item = $existing($get)) {
                return (float) $item->unit_price;
            }

            $product = $catalog->get((int) $get('product_id'));

            if (! $product || ($hasVariants($get) && ! $lineVariant($get))) {
                return null;
            }

            $variant = $lineVariant($get);

            return round($service->basePrice($reseller, $product, $variant) ?? $service->suggestedPrice($product, $variant) ?? 0.0, 2);
        };
        $owesExtra = fn (): bool => ($this->summary()['to_send'] ?? 0) > 0;

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Customer'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Customer name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label(__('Phone'))
                            ->tel()
                            ->required()
                            ->maxLength(40),
                        Textarea::make('address')
                            ->label(__('Delivery address'))
                            ->required()
                            ->maxLength(1000)
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Delivery'))
                    ->schema([
                        TextInput::make('courier_name')
                            ->label(__('Courier name'))
                            ->maxLength(100),
                        TextInput::make('parcel_id')
                            ->label(__('Parcel ID'))
                            ->helperText(__('The parcel or consignment ID from your courier. The store puts it on the package.'))
                            ->required()
                            ->maxLength(100),
                    ])
                    ->columns(2),

                Section::make(__('Products'))
                    ->description(__('You can add products, raise quantities and change your customer\'s prices. Products already on the order cannot be removed or lowered.'))
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->addActionLabel(__('Add product'))
                            ->minItems(1)
                            ->live()
                            ->deleteAction(fn (Action $action): Action => $action->hidden(
                                fn (array $arguments, Repeater $component): bool => filled($component->getRawItemState($arguments['item'])['item_id'] ?? null)
                            ))
                            ->schema([
                                Hidden::make('item_id'),
                                Select::make('product_id')
                                    ->label(__('Product'))
                                    ->options($productNames)
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->disabled(fn (Get $get): bool => $existing($get) !== null)
                                    ->dehydrated()
                                    ->afterStateUpdated(function (Set $set, ?string $state) use ($catalog, $service, $reseller): void {
                                        $product = $catalog->get((int) $state);
                                        // A product with variations is priced once an option is chosen.
                                        $set('variant_id', null);
                                        $set('price', $product && ! $product->has_variants ? $service->defaultPrice($reseller, $product) : null);
                                    })
                                    ->columnSpan(3),
                                Select::make('variant_id')
                                    ->label(__('Option'))
                                    ->options(function (Get $get) use ($existing, $catalog): array {
                                        if ($item = $existing($get)) {
                                            return $item->product_variant_id ? [$item->product_variant_id => $item->variant_label ?: '#'.$item->product_variant_id] : [];
                                        }

                                        $product = $catalog->get((int) $get('product_id'));

                                        return $product?->has_variants
                                            ? $product->activeVariants->mapWithKeys(fn (ProductVariant $variant): array => [
                                                $variant->getKey() => ($variant->label() ?: ($variant->sku ?: '#'.$variant->getKey())).' ('.__('Stock').': '.(int) $variant->stock.')',
                                            ])->all()
                                            : [];
                                    })
                                    ->visible($hasVariants)
                                    ->required($hasVariants)
                                    ->live()
                                    ->disabled(fn (Get $get): bool => $existing($get) !== null)
                                    ->dehydrated()
                                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state) use ($catalog, $service, $reseller): void {
                                        $product = $catalog->get((int) $get('product_id'));
                                        $variant = $product?->activeVariants->firstWhere('id', (int) $state);
                                        $set('price', $product && $variant ? $service->defaultPrice($reseller, $product, $variant) : null);
                                    })
                                    ->columnSpan(3),
                                TextInput::make('quantity')
                                    ->label(__('Quantity'))
                                    ->numeric()
                                    ->minValue(fn (Get $get): int => (int) ($existing($get)?->quantity ?? 1))
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->columnSpan(1),
                                TextInput::make('price')
                                    ->label(__("Your customer's price"))
                                    ->numeric()
                                    ->minValue(fn (Get $get): ?float => $minPrice($get))
                                    ->helperText(fn (Get $get): ?string => ($min = $minPrice($get)) !== null
                                        ? __('At least :amount.', ['amount' => MoneyFormatter::number($min)])
                                        : null)
                                    ->live(onBlur: true)
                                    ->columnSpan(2),
                            ])
                            ->columns(6),
                    ]),

                Section::make(__('Payment for the extra'))
                    ->description(fn (): string => __('The new total is higher. Send only the extra :amount, then enter the payment details. The store checks it before packing the parcel.', [
                        'amount' => MoneyFormatter::currency((float) ($this->summary()['to_send'] ?? 0), $this->currency()),
                    ]))
                    ->visible($owesExtra)
                    ->schema([
                        Select::make('payment_method_id')
                            ->label(__('Paid to'))
                            ->options(fn (): array => $paymentOptions)
                            ->required($owesExtra)
                            ->live()
                            ->helperText(fn (Get $get): ?string => $get('payment_method_id') === ResellerOwnCourierService::ONLINE
                                ? __('You will go to the secure payment page to pay :amount. The store packs the parcel once the payment is confirmed.', [
                                    'amount' => MoneyFormatter::currency((float) ($this->summary()['to_send'] ?? 0), $this->currency()),
                                ])
                                : $paymentMethods->get((int) $get('payment_method_id'))?->instructions)
                            ->columnSpanFull(),
                        TextInput::make('sender')
                            ->label(__('Paid from (number or account)'))
                            ->required(fn (Get $get): bool => $owesExtra() && $get('payment_method_id') !== ResellerOwnCourierService::ONLINE)
                            ->visible(fn (Get $get): bool => $get('payment_method_id') !== ResellerOwnCourierService::ONLINE)
                            ->maxLength(40),
                        TextInput::make('transaction_id')
                            ->label(__('Transaction ID'))
                            ->required(fn (Get $get): bool => $owesExtra() && $get('payment_method_id') !== ResellerOwnCourierService::ONLINE)
                            ->visible(fn (Get $get): bool => $get('payment_method_id') !== ResellerOwnCourierService::ONLINE)
                            ->maxLength(100),
                        CreateOrder::amountSentField(fn (): float => (float) ($this->summary()['to_send'] ?? 0), $this->currency())
                            ->visible(fn (Get $get): bool => $get('payment_method_id') !== ResellerOwnCourierService::ONLINE),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Live totals after the change, or null while a line is not complete
     * enough to price.
     *
     * @return array<string, float>|null
     */
    public function summary(): ?array
    {
        $lines = collect($this->data['items'] ?? [])
            ->filter(fn ($line): bool => is_array($line) && (filled($line['item_id'] ?? null) || filled($line['product_id'] ?? null)))
            ->all();

        if ($lines === []) {
            return null;
        }

        $key = md5(json_encode($lines));

        if (($this->summaryCache['key'] ?? null) === $key) {
            return $this->summaryCache['summary'];
        }

        try {
            $summary = app(ResellerOrderPlacementService::class)->quoteEdit($this->reseller(), $this->order(), $lines);
        } catch (ValidationException) {
            $summary = null;
        }

        $this->summaryCache = ['key' => $key, 'summary' => $summary];

        return $summary;
    }

    public function save(): void
    {
        $data = $this->form->getState();

        app(ResellerOrderPlacementService::class)->updateOwnCourierOrder($this->reseller(), $this->order(), [
            'name' => trim($data['name']),
            'phone' => trim($data['phone']),
            'address' => trim($data['address']),
        ], $data['items'], [
            'courier_name' => $data['courier_name'] ?? null,
            'parcel_id' => (string) ($data['parcel_id'] ?? ''),
        ], [
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'sender' => (string) ($data['sender'] ?? ''),
            'transaction_id' => (string) ($data['transaction_id'] ?? ''),
            'amount' => $data['amount'] ?? null,
        ]);

        $onlinePayment = app(ResellerOwnCourierService::class)->pendingOnlinePayment($this->order());

        Notification::make()
            ->title($onlinePayment
                ? __('Order :number was updated. Finish the payment for the extra.', ['number' => $this->order()->order_number])
                : __('Order :number was updated.', ['number' => $this->order()->order_number]))
            ->success()
            ->send();

        $this->redirect($onlinePayment?->payload['payment_url'] ?? Orders::getUrl());
    }
}
