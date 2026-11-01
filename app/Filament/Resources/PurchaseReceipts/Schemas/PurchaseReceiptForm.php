<?php

namespace App\Filament\Resources\PurchaseReceipts\Schemas;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PurchaseReceiptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Receipt'))
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('receipt_number')
                            ->label(__('Receipt Number'))
                            ->default(fn (): string => PurchaseReceipt::nextReceiptNumber())
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

                        DatePicker::make('received_on')
                            ->label(__('Received On'))
                            ->default(now())
                            ->maxDate(now())
                            ->required(),

                        TextInput::make('reference')
                            ->label(__('Supplier Challan / Reference'))
                            ->maxLength(255),

                        Toggle::make('books_stock')
                            ->label(__('Add to stock'))
                            ->helperText(__('Turn this off for old goods that the current stock already counts. The receipt then only counts in the supplier account.'))
                            ->default(true),

                        Toggle::make('is_opening')
                            ->label(__('Opening receipt'))
                            ->helperText(__('Use this for goods whose real receive date and cartons are not known (old history). Stock is booked the same way.'))
                            ->default(false),

                        Textarea::make('note')
                            ->label(__('Note'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Goods Received'))
                    ->description(__('Received = pieces the supplier sent. Short = lost on the way, never reached the godown. Damage = arrived broken. Stock added = received - short - damage.'))
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
                                    ->relationship('product', 'name', fn ($query) => $query->where('is_active', true))
                                    ->searchable(['name', 'sku', 'barcode', 'brand'])
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get, ?int $state): void {
                                        $set('product_variant_id', null);

                                        if (! $state || ! ($product = Product::query()->find($state))) {
                                            return;
                                        }

                                        $set('pcs_per_carton', $product->pcs_per_carton);
                                        $set('unit_cost', self::lastUnitCost($product, (int) $get('../../supplier_id')));
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

                                TextInput::make('cartons')
                                    ->label(__('Cartons'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Set $set, Get $get) => self::fillPieces($set, $get)),

                                TextInput::make('pcs_per_carton')
                                    ->label(__('Pcs per carton'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Set $set, Get $get) => self::fillPieces($set, $get)),

                                TextInput::make('quantity')
                                    ->label(__('Received (pcs)'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->required(),

                                TextInput::make('short_quantity')
                                    ->label(__('Short (pcs)'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->default(0),

                                TextInput::make('damage_quantity')
                                    ->label(__('Damage (pcs)'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->default(0),

                                TextInput::make('unit_cost')
                                    ->label(__('Buy price (per pc)'))
                                    ->numeric()
                                    ->prefix('৳')
                                    ->minValue(0)
                                    ->default(0)
                                    ->required(),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }

    private static function fillPieces(Set $set, Get $get): void
    {
        $cartons = (int) $get('cartons');
        $perCarton = (int) $get('pcs_per_carton');

        if ($cartons > 0 && $perCarton > 0) {
            $set('quantity', $cartons * $perCarton);
        }
    }

    /** The buy price last used for this product, for this supplier first; the product's cost price otherwise. */
    private static function lastUnitCost(Product $product, int $supplierId): float
    {
        $last = PurchaseReceiptItem::query()
            ->where('product_id', $product->getKey())
            ->when($supplierId, fn ($query) => $query->whereHas('receipt', fn ($receipt) => $receipt->where('supplier_id', $supplierId)))
            ->latest('id')
            ->value('unit_cost');

        return (float) ($last ?? $product->cost_price ?? 0);
    }
}
