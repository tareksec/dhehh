<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Filament\Forms\Components\EmailInput;
use App\Filament\Forms\Components\PhoneInput;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Support\CompanyScopedUnique;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Purchase Details')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('purchase_number')
                            ->label('Purchase Number')
                            ->default(fn (): string => Purchase::nextPurchaseNumber())
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Select::make('supplier_id')
                            ->label('Supplier')
                            ->relationship('supplier', 'name', fn ($query) => $query->where('is_active', true))
                            ->searchable(['name', 'phone', 'company_name'])
                            ->preload()
                            ->getOptionLabelFromRecordUsing(fn (Supplier $record): string => collect([
                                $record->name,
                                $record->phone ? "Phone: {$record->phone}" : null,
                                $record->company_name ? "Company: {$record->company_name}" : null,
                            ])->filter()->join(' | '))
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Supplier Name')
                                    ->required()
                                    ->maxLength(255),

                                TextInput::make('company_name')
                                    ->label('Company Name')
                                    ->maxLength(255),

                                PhoneInput::make(),

                                EmailInput::make(),

                                TextInput::make('opening_balance')
                                    ->numeric()
                                    ->prefix('৳')
                                    ->default(0)
                                    ->required(),

                                Textarea::make('address')
                                    ->label('Address')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                return Supplier::query()->create([
                                    'name' => $data['name'],
                                    'company_name' => $data['company_name'] ?? null,
                                    'phone' => $data['phone'] ?? null,
                                    'email' => $data['email'] ?? null,
                                    'address' => $data['address'] ?? null,
                                    'opening_balance' => $data['opening_balance'] ?? 0,
                                    'current_balance' => $data['opening_balance'] ?? 0,
                                    'is_active' => true,
                                ])->getKey();
                            })
                            ->required(),

                        DatePicker::make('purchase_date')
                            ->label('Purchase Date')
                            ->default(now())
                            ->required(),

                        Select::make('status')
                            ->options(Purchase::STATUSES)
                            ->default('draft')
                            ->required()
                            ->live()
                            ->helperText('Stock is increased only when the status is Received. Cancelled purchases remove related stock movements.'),

                        Toggle::make('update_cost_price')
                            ->label('Update product cost price')
                            ->default(false),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Section::make('Document Tracking')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('lc_number')
                            ->label('LC Number')
                            ->maxLength(255),

                        DatePicker::make('lc_date')
                            ->label('LC Date'),

                        TextInput::make('pi_number')
                            ->label('PI Number')
                            ->helperText(__('Leave empty — filled automatically the first time you click "Generate PI".'))
                            ->maxLength(255),

                        DatePicker::make('pi_date')
                            ->label('PI Date'),

                        TextInput::make('ci_number')
                            ->label('CI Number')
                            ->helperText(__('Leave empty — filled automatically the first time you click "Generate CI".'))
                            ->maxLength(255),

                        DatePicker::make('ci_date')
                            ->label('CI Date'),

                        TextInput::make('pl_number')
                            ->label(__('PL Number'))
                            ->helperText(__('Leave empty — filled automatically the first time you click "Generate PL".'))
                            ->maxLength(255),

                        DatePicker::make('pl_date')
                            ->label(__('PL Date')),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Section::make(__('Trade Documents'))
                    ->description(__('Feeds the printable PI / CI / PL documents (Purchase > Generate PI/CI/PL). CI and PL also need Delivery Terms, Port of Loading, and Port of Discharge filled in below before they can be generated.'))
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('delivery_terms')
                            ->label(__('Delivery Terms'))
                            ->placeholder(__('e.g. FOB Ningbo, CFR Chattogram by Sea'))
                            ->maxLength(255),

                        TextInput::make('country_of_origin')
                            ->label(__('Country of Origin'))
                            ->maxLength(255),

                        TextInput::make('port_of_loading')
                            ->label(__('Port of Loading'))
                            ->placeholder(__('e.g. Any Port From China'))
                            ->maxLength(255),

                        TextInput::make('port_of_discharge')
                            ->label(__('Port of Discharge'))
                            ->placeholder(__('e.g. Chattogram, Bangladesh'))
                            ->maxLength(255),

                        TextInput::make('payment_method_summary')
                            ->label(__('Payment Method'))
                            ->placeholder(__('e.g. 100% TT in advance, 100% LC at Sight'))
                            ->maxLength(255),

                        TextInput::make('freight_usd')
                            ->label(__('Freight (USD)'))
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->helperText(__('International freight only, in USD — separate from the "Freight to Ctg" BDT cost below, which may include additional inland charges.')),

                        TextInput::make('exchange_rate')
                            ->label(__('Exchange Rate (BDT per USD)'))
                            ->numeric()
                            ->minValue(0)
                            ->step('0.0001')
                            ->helperText(__('Informational only — does not change the BDT costs above.')),

                        Textarea::make('terms_conditions')
                            ->label(__('Payment Terms / Terms & Conditions'))
                            ->rows(4)
                            ->placeholder(__('Leave empty to use the default from Company Settings > Purchase Documents.'))
                            ->columnSpanFull(),

                        Textarea::make('pl_certification_note')
                            ->label(__('Packing List Certification Note'))
                            ->rows(3)
                            ->placeholder(__('Leave empty to use the default from Company Settings > Purchase Documents.'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Section::make('Items')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->schema([
                                Select::make('product_id')
                                    ->columnSpan(self::itemSpan(6))
                                    ->label('Product')
                                    ->relationship('product', 'name', fn ($query) => $query->where('is_active', true))
                                    ->searchable(['name', 'sku', 'barcode', 'brand'])
                                    ->preload()
                                    ->getOptionLabelFromRecordUsing(fn (Product $record): string => collect([
                                        $record->name,
                                        $record->sku ? "SKU: {$record->sku}" : null,
                                        $record->brand ? "Brand: {$record->brand}" : null,
                                    ])->filter()->join(' | '))
                                    ->createOptionForm([
                                        TextInput::make('name')
                                            ->label('Product Name')
                                            ->required()
                                            ->maxLength(255),

                                        Select::make('category_id')
                                            ->label('Category')
                                            ->relationship('category', 'name', fn ($query) => $query->where('is_active', true))
                                            ->searchable()
                                            ->preload()
                                            ->createOptionForm([
                                                TextInput::make('name')
                                                    ->label('Category Name')
                                                    ->required()
                                                    ->maxLength(255),

                                                Textarea::make('description')
                                                    ->rows(3)
                                                    ->columnSpanFull(),
                                            ])
                                            ->createOptionUsing(function (array $data): int {
                                                $slug = Str::slug($data['name']);
                                                $originalSlug = $slug;
                                                $suffix = 2;

                                                while (Category::query()->where('slug', $slug)->exists()) {
                                                    $slug = "{$originalSlug}-{$suffix}";
                                                    $suffix++;
                                                }

                                                return Category::query()->create([
                                                    'name' => $data['name'],
                                                    'slug' => $slug,
                                                    'description' => $data['description'] ?? null,
                                                    'is_active' => true,
                                                ])->getKey();
                                            })
                                            ->required(),

                                        TextInput::make('sku')
                                            ->label('SKU')
                                            ->required()
                                            ->unique(Product::class, 'sku', modifyRuleUsing: CompanyScopedUnique::rule())
                                            ->maxLength(255),

                                        TextInput::make('barcode')
                                            ->label('Barcode')
                                            ->unique(Product::class, 'barcode', modifyRuleUsing: CompanyScopedUnique::rule())
                                            ->maxLength(255),

                                        TextInput::make('brand')
                                            ->label('Brand')
                                            ->maxLength(255),

                                        TextInput::make('unit')
                                            ->label('Unit')
                                            ->default('pcs')
                                            ->required()
                                            ->maxLength(50),

                                        TextInput::make('hs_code')
                                            ->label(__('HS Code'))
                                            ->maxLength(50),

                                        TextInput::make('cost_price')
                                            ->label('Cost Price')
                                            ->numeric()
                                            ->prefix('৳')
                                            ->default(0)
                                            ->minValue(0)
                                            ->required(),

                                        TextInput::make('sale_price')
                                            ->label('Sale Price')
                                            ->numeric()
                                            ->prefix('৳')
                                            ->default(0)
                                            ->minValue(0)
                                            ->required(),

                                        TextInput::make('stock')
                                            ->label('Opening Stock')
                                            ->integer()
                                            ->default(0)
                                            ->minValue(0)
                                            ->required(),
                                    ])
                                    ->createOptionUsing(function (array $data): int {
                                        $openingStock = (int) ($data['stock'] ?? 0);

                                        $product = Product::query()->create([
                                            'name' => $data['name'],
                                            'category_id' => $data['category_id'],
                                            'sku' => $data['sku'],
                                            'barcode' => $data['barcode'] ?? null,
                                            'brand' => $data['brand'] ?? null,
                                            'unit' => $data['unit'] ?? 'pcs',
                                            'hs_code' => $data['hs_code'] ?? null,
                                            'cost_price' => $data['cost_price'] ?? 0,
                                            'sale_price' => $data['sale_price'] ?? 0,
                                            'price' => $data['sale_price'] ?? 0,
                                            'stock' => 0,
                                            'reorder_level' => 0,
                                            'vat_rate' => 0,
                                            'is_active' => true,
                                            'status' => Product::STATUS_AVAILABLE,
                                        ]);

                                        $product->setStockFromProductForm($openingStock);

                                        return $product->getKey();
                                    })
                                    ->live()
                                    ->required()
                                    ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                        $product = Product::find($state);

                                        if (! $product) {
                                            return;
                                        }

                                        $unitCost = (float) ($product->cost_price ?? 0);

                                        $set('unit_cost', $unitCost);
                                        $set('subtotal', (int) ($get('quantity') ?? 0) * $unitCost);
                                        $set('hs_code', $product->hs_code);
                                        self::setPurchaseTotalsFromRepeater($get, $set);
                                    }),

                                TextInput::make('quantity')
                                    ->columnSpan(self::itemSpan(2))
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        self::setItemSubtotal($get, $set);
                                        self::setPurchaseTotalsFromRepeater($get, $set);
                                    }),

                                TextInput::make('short_quantity')
                                    ->columnSpan(self::itemSpan(2))
                                    ->label(__('Short Qty'))
                                    ->integer()
                                    ->minValue(0)
                                    ->default(0)
                                    ->helperText(__('Billed but not delivered.')),

                                TextInput::make('damaged_quantity')
                                    ->columnSpan(self::itemSpan(2))
                                    ->label(__('Damaged Qty'))
                                    ->integer()
                                    ->minValue(0)
                                    ->default(0)
                                    ->helperText(__('Delivered but not sellable.')),

                                TextInput::make('unit_cost')
                                    ->columnSpan(self::itemSpan(3))
                                    ->numeric()
                                    ->prefix('৳')
                                    ->minValue(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        self::setItemSubtotal($get, $set);
                                        self::setPurchaseTotalsFromRepeater($get, $set);
                                    }),

                                TextInput::make('subtotal')
                                    ->columnSpan(self::itemSpan(3))
                                    ->numeric()
                                    ->prefix('৳')
                                    ->readOnly(),

                                TextInput::make('allocated_cost')
                                    ->columnSpan(self::itemSpan(3))
                                    ->label('Allocated Cost')
                                    ->numeric()
                                    ->prefix('৳')
                                    ->readOnly()
                                    ->dehydrated(false),

                                TextInput::make('landed_unit_cost')
                                    ->columnSpan(self::itemSpan(3))
                                    ->label('Landed Unit Cost')
                                    ->numeric()
                                    ->prefix('৳')
                                    ->readOnly()
                                    ->dehydrated(false),

                                TextInput::make('hs_code')
                                    ->columnSpan(self::itemSpan(2))
                                    ->label(__('HS Code'))
                                    ->helperText(__('Defaults from the product; override per purchase if needed.'))
                                    ->maxLength(50),

                                TextInput::make('spec_note')
                                    ->columnSpan(self::itemSpan(4))
                                    ->label(__('Spec / Model Note'))
                                    ->placeholder(__('e.g. 7.5L 220V 1850W'))
                                    ->maxLength(255),

                                TextInput::make('fob_unit_price_usd')
                                    ->columnSpan(self::itemSpan(2))
                                    ->label(__('FOB Price (USD)'))
                                    ->numeric()
                                    ->prefix('$')
                                    ->minValue(0),

                                TextInput::make('net_weight_kg')
                                    ->columnSpan(self::itemSpan(2))
                                    ->label(__('Net Weight (kg)'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('0.001'),

                                TextInput::make('gross_weight_kg')
                                    ->columnSpan(self::itemSpan(2))
                                    ->label(__('Gross Weight (kg)'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('0.001'),
                            ])
                            ->columns(['default' => 1, 'md' => 12])
                            ->defaultItems(1)
                            ->addActionLabel('Add item')
                            ->reorderable(false)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setPurchaseTotals($get, $set))
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Section::make('China to BD Costs')
                    ->columnSpanFull()
                    ->schema([
                        ...self::chinaToBdCostFields(),
                        Actions::make([
                            self::addCustomCostFieldAction(),
                        ])
                            ->columnSpanFull(),
                        Repeater::make('custom_costs')
                            ->label('Custom Fields')
                            ->hidden(fn (Get $get): bool => blank($get('custom_costs')))
                            ->table([
                                TableColumn::make('Field Name'),
                                TableColumn::make('Amount'),
                            ])
                            ->schema([
                                TextInput::make('label')
                                    ->label('Field Name')
                                    ->required()
                                    ->maxLength(255),

                                TextInput::make('amount')
                                    ->label('Amount')
                                    ->numeric()
                                    ->prefix('৳')
                                    ->minValue(0)
                                    ->default(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, Set $set) => self::setPurchaseTotalsFromRepeater($get, $set)),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addable(false)
                            ->reorderable(false)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setPurchaseTotals($get, $set))
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Section::make(__('Costs After Arrival'))
                    ->description(__('Packaging, ad and other costs paid per product after the goods reached the house. They feed the Supplier Report and do not change the purchase total.'))
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('product_costs')
                            ->label(__('Product cost lines'))
                            ->table([
                                TableColumn::make(__('Product')),
                                TableColumn::make(__('Cost name')),
                                TableColumn::make(__('Amount')),
                            ])
                            ->schema([
                                Select::make('product_id')
                                    ->label(__('Product'))
                                    ->options(function (Get $get): array {
                                        $productIds = collect($get('../../items') ?? [])
                                            ->pluck('product_id')
                                            ->filter()
                                            ->unique()
                                            ->all();

                                        return Product::query()
                                            ->whereIn('id', $productIds)
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all();
                                    })
                                    ->searchable()
                                    ->required(),

                                TextInput::make('label')
                                    ->label(__('Cost name'))
                                    ->datalist(array_map(fn (string $label): string => __($label), Purchase::PRODUCT_COST_SUGGESTIONS))
                                    ->required()
                                    ->maxLength(255),

                                TextInput::make('amount')
                                    ->label(__('Amount'))
                                    ->numeric()
                                    ->prefix('৳')
                                    ->minValue(0)
                                    ->default(0)
                                    ->required(),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel(__('Add cost line'))
                            ->reorderable(false)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Section::make('Totals')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('subtotal')
                            ->numeric()
                            ->prefix('৳')
                            ->readOnly(),

                        TextInput::make('discount')
                            ->numeric()
                            ->prefix('৳')
                            ->default(0)
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setPurchaseTotals($get, $set)),

                        TextInput::make('vat')
                            ->numeric()
                            ->prefix('৳')
                            ->default(0)
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setPurchaseTotals($get, $set)),

                        TextInput::make('total_amount')
                            ->numeric()
                            ->prefix('৳')
                            ->readOnly(),

                        TextInput::make('landed_cost_total')
                            ->label('Landed Cost Total')
                            ->numeric()
                            ->prefix('৳')
                            ->dehydrated(false)
                            ->readOnly()
                            ->afterStateHydrated(function (TextInput $component, ?Purchase $record): void {
                                $component->state($record?->landedCostTotal() ?? 0);
                            }),

                        TextInput::make('paid_amount')
                            ->numeric()
                            ->prefix('৳')
                            ->default(0)
                            ->minValue(0)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::setPurchaseTotals($get, $set)),

                        TextInput::make('due_amount')
                            ->numeric()
                            ->prefix('৳')
                            ->readOnly(),
                    ])
                    ->columns(3)
                    ->collapsible(),

                Section::make('Note')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('note')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),
            ]);
    }

    /**
     * Column span of one field of a purchase line: full width on a phone, a
     * share of a 12-column row from the md breakpoint up.
     *
     * @return array<string, int|string>
     */
    protected static function itemSpan(int $columns): array
    {
        return ['default' => 'full', 'md' => $columns];
    }

    protected static function setItemSubtotal(Get $get, Set $set): void
    {
        $set('subtotal', (int) ($get('quantity') ?? 0) * (float) ($get('unit_cost') ?? 0));
    }

    protected static function setPurchaseTotalsFromRepeater(Get $get, Set $set): void
    {
        self::setPurchaseTotals($get, $set, '../../');
    }

    protected static function chinaToBdCostFields(): array
    {
        return collect(Purchase::CHINA_TO_BD_COST_FIELDS)
            ->map(fn (string $label, string $field): TextInput => TextInput::make($field)
                ->label($label)
                ->numeric()
                ->prefix('৳')
                ->minValue(0)
                ->default(0)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::setPurchaseTotals($get, $set)))
            ->all();
    }

    protected static function addCustomCostFieldAction(): Action
    {
        return Action::make('add_custom_cost_field')
            ->label('Add new field')
            ->icon('heroicon-o-plus')
            ->modalHeading('Create custom cost field')
            ->schema([
                TextInput::make('label')
                    ->label('Field Name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->prefix('৳')
                    ->minValue(0)
                    ->default(0)
                    ->required(),
            ])
            ->action(function (array $data, Get $get, Set $set): void {
                $customCosts = $get('custom_costs') ?? [];
                $customCosts[] = [
                    'label' => $data['label'],
                    'amount' => (float) ($data['amount'] ?? 0),
                ];

                $set('custom_costs', $customCosts);
                self::setPurchaseTotals($get, $set);
            });
    }

    protected static function setPurchaseTotals(Get $get, Set $set, string $prefix = ''): void
    {
        $items = $get($prefix . 'items') ?? [];
        $subtotal = collect($items)
            ->sum(fn (array $item): float => (int) ($item['quantity'] ?? 0) * (float) ($item['unit_cost'] ?? 0));
        $chinaToBdCostTotal = collect(Purchase::CHINA_TO_BD_COST_FIELDS)
            ->keys()
            ->sum(fn (string $field): float => (float) ($get($prefix . $field) ?? 0));
        $customCostTotal = collect($get($prefix . 'custom_costs') ?? [])
            ->sum(fn (array $cost): float => (float) ($cost['amount'] ?? 0));
        $landedCostTotal = $subtotal + $chinaToBdCostTotal + $customCostTotal;
        $total = max($subtotal + $chinaToBdCostTotal + $customCostTotal - (float) ($get($prefix . 'discount') ?? 0) + (float) ($get($prefix . 'vat') ?? 0), 0);
        $due = max($total - (float) ($get($prefix . 'paid_amount') ?? 0), 0);

        $set($prefix . 'subtotal', $subtotal);
        $set($prefix . 'landed_cost_total', $landedCostTotal);
        $set($prefix . 'total_amount', $total);
        $set($prefix . 'due_amount', $due);
    }
}
