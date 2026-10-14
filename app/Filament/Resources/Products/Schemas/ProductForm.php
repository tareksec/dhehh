<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Concerns\OptimizesUploadedImages;
use App\Filament\Concerns\SelectableFromMediaHub;
use App\Filament\Forms\Components\AttachedVideoPreview;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Models\Category;
use App\Models\Product;
use App\Support\CompanyMedia;
use App\Support\CompanyScopedUnique;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    use OptimizesUploadedImages;
    use SelectableFromMediaHub;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Basic Information')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Product Name')
                            ->required()
                            ->maxLength(255),

                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->createOptionForm(self::categoryCreateOptionForm())
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
                                    'image' => $data['image'] ?? null,
                                    'icon' => $data['icon'] ?? null,
                                    'is_active' => $data['is_active'] ?? true,
                                ])->getKey();
                            })
                            ->required(),

                        TextInput::make('sku')
                            ->label('SKU')
                            ->required()
                            ->unique(ignoreRecord: true, modifyRuleUsing: CompanyScopedUnique::rule()),

                        TextInput::make('barcode')
                            ->label('Barcode')
                            ->unique(ignoreRecord: true, modifyRuleUsing: CompanyScopedUnique::rule())
                            ->maxLength(255),

                        TextInput::make('brand')
                            ->label('Brand')
                            ->maxLength(255),

                        TextInput::make('unit')
                            ->label('Unit')
                            ->default('pcs')
                            ->required()
                            ->maxLength(50),

                        TextInput::make('weight_kg')
                            ->label('Weight (kg)')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.001')
                            ->suffix('kg')
                            ->helperText('Shown on the invoice weight column and used for courier weight.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        Select::make('status')
                            ->label('Status')
                            ->options(Product::STATUSES)
                            ->default(Product::STATUS_AVAILABLE)
                            ->required(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->persistCollapsed(),

                Section::make('Pricing and Stock')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('cost_price')
                            ->label('Cost Price')
                            ->numeric()
                            ->prefix('৳')
                            ->minValue(0),

                        TextInput::make('sale_price')
                            ->label('Sale Price')
                            ->numeric()
                            ->prefix('৳')
                            ->minValue(0)
                            ->required(),

                        TextInput::make('stock')
                            ->label('Stock Quantity')
                            ->integer()
                            ->default(0)
                            ->minValue(0)
                            ->required(fn ($get): bool => ! $get('has_variants'))
                            ->disabled(fn ($get): bool => (bool) $get('has_variants'))
                            ->dehydrated(fn ($get): bool => ! $get('has_variants'))
                            ->helperText(fn (?Product $record): string => $record?->stock_pool_id
                                ? 'This product\'s stock is shared with a linked product in another company (Inventory > Shared Stock Pools) — selling either one updates this same live number.'
                                : 'Saving this value creates an opening or adjustment stock movement. When variations are enabled, stock is tracked per variation and this field becomes the automatic sum of variation stock.'),

                        TextInput::make('reorder_level')
                            ->label('Reorder Level')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->required(),

                        TextInput::make('vat_rate')
                            ->label('VAT Rate')
                            ->numeric()
                            ->suffix('%')
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->persistCollapsed(),

                Section::make('Wholesale (B2B)')
                    ->columnSpanFull()
                    ->description('Optional storefront wholesale rules. Leave empty for normal retail behavior.')
                    ->schema([
                        Toggle::make('is_preorder')
                            ->label('Allow pre-order')
                            ->default(false)
                            ->live()
                            ->helperText('Customers can order beyond current stock. Quantities above stock require an online advance payment and cash on delivery is not offered for them.'),
                        TextInput::make('preorder_advance_percent')
                            ->label('Pre-order advance (%)')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100)
                            ->visible(fn ($get): bool => (bool) $get('is_preorder'))
                            ->helperText('Percentage of the pre-order line payable online at checkout. Empty means full payment (100%).'),
                        TextInput::make('moq')
                            ->label('Minimum order quantity (MOQ)')
                            ->integer()
                            ->minValue(1)
                            ->helperText('Storefront customers cannot order fewer than this quantity. Leave empty for no minimum.'),
                        Repeater::make('tier_prices')
                            ->label('Tiered prices')
                            ->schema([
                                TextInput::make('min_qty')
                                    ->label('From quantity')
                                    ->integer()
                                    ->minValue(1)
                                    ->required(),
                                TextInput::make('price')
                                    ->label('Unit price')
                                    ->numeric()
                                    ->prefix('৳')
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add price tier')
                            ->defaultItems(0)
                            ->helperText('Per-unit price when the ordered quantity reaches each tier. Overrides the sale price at those quantities on the storefront. Does not apply to variation prices.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->persistCollapsed(),

                Section::make('Product Images')
                    ->columnSpanFull()
                    ->schema([
                        FileUpload::make('image')
                            ->label('Featured Image')
                            ->helperText('Main image shown in product lists and as the default on the product page. Recommended: square, at least 800x800px. Automatically compressed to WebP on upload.')
                            ->image()
                            ->maxSize(2048)
                            ->tap(static::browserImagePrecompression())
                            ->disk(fn (): string => CompanyMedia::publicDiskName())
                            ->directory(fn ($record): string => CompanyMedia::publicDirectory('products', $record))
                            ->fetchFileInformation(false)
                            ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                            ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                            ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                            ->disabled(fn ($record): bool => ! CompanyMedia::canResolve($record))
                            ->imageEditor()
                            ->saveUploadedFileUsing(static::optimizeImageUpload())
                            ->hintAction(static::selectFromMediaHubAction())
                            ->downloadable()
                            ->openable(),
                        FileUpload::make('gallery_images')
                            ->label('Gallery Images')
                            ->helperText('Additional product photos shown as a gallery on the product page. Drag to reorder. Automatically compressed to WebP on upload.')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->maxSize(2048)
                            ->tap(static::browserImagePrecompression())
                            ->maxFiles(10)
                            ->disk(fn (): string => CompanyMedia::publicDiskName())
                            ->directory(fn ($record): string => CompanyMedia::publicDirectory('products/gallery', $record))
                            ->fetchFileInformation(false)
                            ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                            ->getOpenableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                            ->getDownloadableFileUrlUsing(CompanyMedia::publicFileUrlCallback())
                            ->disabled(fn ($record): bool => ! CompanyMedia::canResolve($record))
                            ->imageEditor()
                            ->saveUploadedFileUsing(static::optimizeImageUpload())
                            ->hintAction(static::selectFromMediaHubAction())
                            ->downloadable()
                            ->openable(),
                        AttachedVideoPreview::make('video_preview')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->persistCollapsed(),

                Section::make('Variations')
                    ->columnSpanFull()
                    ->description('Enable for products that come in multiple options (size, color, model). Each variation has its own SKU, price, stock, and images — like WooCommerce variable products.')
                    ->schema([
                        Toggle::make('has_variants')
                            ->label('This product has variations')
                            ->live()
                            ->default(false),
                        Repeater::make('variants')
                            ->relationship('variants')
                            ->visible(fn ($get): bool => (bool) $get('has_variants'))
                            ->schema([
                                KeyValue::make('options')
                                    ->label('Options')
                                    ->keyLabel('Attribute (e.g. Size, Color)')
                                    ->valueLabel('Value (e.g. M, Red)')
                                    ->required()
                                    ->columnSpanFull(),
                                TextInput::make('sku')
                                    ->label('Variation SKU')
                                    ->maxLength(100),
                                TextInput::make('sale_price')
                                    ->label('Sale Price')
                                    ->numeric()
                                    ->prefix('৳')
                                    ->minValue(0)
                                    ->helperText('Leave empty to use the product sale price.'),
                                TextInput::make('cost_price')
                                    ->label('Cost Price')
                                    ->numeric()
                                    ->prefix('৳')
                                    ->minValue(0),
                                TextInput::make('stock')
                                    ->label('Stock')
                                    ->integer()
                                    ->default(0)
                                    ->minValue(0)
                                    ->required(),
                                TextInput::make('weight_kg')
                                    ->label(__('Variation weight (kg)'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('0.001')
                                    ->suffix('kg')
                                    ->helperText(__('Leave empty to use the product weight.')),
                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true),
                                FileUpload::make('images')
                                    ->label('Variation Images')
                                    ->helperText('Shown when this variation is selected on the storefront.')
                                    ->image()
                                    ->multiple()
                                    ->reorderable()
                                    ->maxSize(2048)
                                    ->tap(static::browserImagePrecompression())
                                    ->maxFiles(6)
                                    ->disk(fn (): string => CompanyMedia::publicDiskName())
                                    ->directory(fn ($record): string => CompanyMedia::publicDirectory('products/variants', $record))
                                    ->fetchFileInformation(false)
                                    ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                                    ->disabled(fn ($record): bool => ! CompanyMedia::canResolve($record))
                                    ->imageEditor()
                                    ->saveUploadedFileUsing(static::optimizeImageUpload())
                                    ->hintAction(static::selectFromMediaHubAction())
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->orderColumn('sort_order')
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => collect($state['options'] ?? [])->map(fn ($v, $k) => "$k: $v")->implode(' / ') ?: null)
                            ->addActionLabel('Add variation')
                            ->defaultItems(0),
                    ])
                    ->collapsible()
                    ->persistCollapsed(),

                Section::make('Description')
                    ->columnSpanFull()
                    ->schema([
                        RichEditor::make('description')
                            ->label('Description')
                            ->required(false)
                            ->fileAttachmentsDisk(fn (): string => CompanyMedia::publicDiskName())
                            ->fileAttachmentsDirectory(fn ($record): string => CompanyMedia::publicDirectory('products/description', $record))
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->persistCollapsed(),
            ]);
    }

    public static function categoryCreateOptionForm(): array
    {
        return [
            TextInput::make('name')
                ->label('Category Name')
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->rows(3)
                ->columnSpanFull(),
            ...CategoryForm::mediaFields(),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ];
    }
}
