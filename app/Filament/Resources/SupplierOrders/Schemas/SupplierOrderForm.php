<?php

namespace App\Filament\Resources\SupplierOrders\Schemas;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SupplierOrder;
use App\Services\SupplierOrderService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SupplierOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Order'))
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('order_number')
                            ->label(__('Order Number'))
                            ->default(fn (): string => SupplierOrder::nextOrderNumber())
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Select::make('supplier_id')
                            ->label(__('Supplier'))
                            ->relationship('supplier', 'name', fn ($query) => $query->where('is_active', true))
                            ->searchable(['name', 'phone', 'company_name'])
                            ->preload()
                            ->live()
                            ->default(fn (): ?int => request()->integer('supplier') ?: null)
                            ->required(),

                        DatePicker::make('order_date')
                            ->label(__('Order Date'))
                            ->default(now())
                            ->required(),

                        DatePicker::make('delivery_date')
                            ->label(__('Delivery Date')),

                        TextInput::make('customer_name')
                            ->label(__('Customer Name'))
                            ->maxLength(255),

                        TextInput::make('customer_phone')
                            ->label(__('Customer Phone'))
                            ->tel()
                            ->maxLength(50),

                        Select::make('status')
                            ->label(__('Status'))
                            ->options(fn (): array => collect(SupplierOrder::STATUSES)
                                ->map(fn (string $label): string => __($label))
                                ->all())
                            ->default(SupplierOrder::STATUS_OPEN)
                            ->required()
                            ->helperText(__('Open and Delivered take the pieces out of our stock and charge the supplier. Returned and Cancelled give the stock back.')),

                        Toggle::make('books_stock')
                            ->label(__('Takes the pieces out of our stock'))
                            ->helperText(__('Turn this off for old orders that the current stock already counts. The order then only counts in the supplier account.'))
                            ->default(true),

                        Textarea::make('note')
                            ->label(__('Note'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Goods'))
                    ->description(__('The supplier\'s buy price is taken from his latest receipt and fixed when the line is saved. It is what comes off his account.'))
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->hiddenLabel()
                            ->addActionLabel(__('Add product'))
                            ->minItems(1)
                            ->defaultItems(1)
                            ->schema([
                                Select::make('product_id')
                                    ->label(__('Product'))
                                    ->relationship(
                                        'product',
                                        'name',
                                        fn (Builder $query, Get $get): Builder => self::productsFor($query, (int) $get('../../supplier_id')),
                                    )
                                    ->searchable(['name', 'sku', 'barcode', 'brand'])
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get, ?int $state): void {
                                        $set('product_variant_id', null);

                                        if (! $state || ! ($product = Product::query()->find($state))) {
                                            return;
                                        }

                                        $set('unit_price', (float) $product->selling_price);
                                        $set('supplier_unit_cost', app(SupplierOrderService::class)->defaultBuyPrice($product, (int) $get('../../supplier_id')));
                                    })
                                    ->required()
                                    ->columnSpan(2),

                                Select::make('product_variant_id')
                                    ->label(__('Variant'))
                                    ->options(fn (Get $get): array => ProductVariant::query()
                                        ->where('product_id', (int) $get('product_id'))
                                        ->get()
                                        ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->getKey() => $variant->label() ?: (string) $variant->sku])
                                        ->all())
                                    ->visible(fn (Get $get): bool => (bool) Product::query()->whereKey((int) $get('product_id'))->value('has_variants'))
                                    ->required(fn (Get $get): bool => (bool) Product::query()->whereKey((int) $get('product_id'))->value('has_variants'))
                                    ->columnSpan(2),

                                TextInput::make('quantity')
                                    ->label(__('Pieces'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->required(),

                                TextInput::make('unit_price')
                                    ->label(__('Customer price (per pc)'))
                                    ->numeric()
                                    ->prefix('৳')
                                    ->minValue(0)
                                    ->default(0)
                                    ->required(),

                                TextInput::make('supplier_unit_cost')
                                    ->label(__('Supplier buy price (per pc)'))
                                    ->numeric()
                                    ->prefix('৳')
                                    ->minValue(0)
                                    ->helperText(__('Leave 0 to use the latest buy price.')),
                            ])
                            ->columns(4),
                    ]),

                Section::make(__('Advance'))
                    ->description(__('Money the customer paid up front. If the customer paid in full, enter the full amount.'))
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('advance_amount')
                            ->label(__('Advance paid by customer'))
                            ->numeric()
                            ->prefix('৳')
                            ->minValue(0)
                            ->default(0)
                            ->required(),

                        Radio::make('advance_collected_by')
                            ->label(__('Money is with'))
                            ->options(fn (): array => collect(SupplierOrder::COLLECTED_BY)
                                ->map(fn (string $label): string => __($label))
                                ->all())
                            ->default(SupplierOrder::COLLECTED_BY_SUPPLIER)
                            ->helperText(__('Advance held by the supplier is taken off what we owe him. If ZamZam received it, record it in Customer Payments.'))
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    /** Only the chosen supplier's products, once he has some assigned; every active product until then. */
    public static function productsFor(Builder $query, int $supplierId): Builder
    {
        $query->where('is_active', true);

        if ($supplierId && Product::query()->where('supplier_id', $supplierId)->exists()) {
            $query->where('supplier_id', $supplierId);
        }

        return $query;
    }
}
