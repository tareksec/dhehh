<?php

namespace App\Filament\Resources\Offers\Schemas;

use App\Filament\Concerns\OptimizesUploadedImages;
use App\Filament\Concerns\SelectableFromMediaHub;
use App\Filament\Forms\Components\AttachedVideoPreview;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Services\CompanyContext;
use App\Support\CompanyMedia;
use App\Support\MoneyFormatter;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class OfferForm
{
    use OptimizesUploadedImages;
    use SelectableFromMediaHub;

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Offer')
                ->columnSpanFull()
                ->schema([
                    Select::make('company_id')
                        ->relationship('company', 'name', modifyQueryUsing: fn ($query) => CompanyMedia::constrainCompanyQuery($query))
                        ->rule(CompanyMedia::companyAccessRule())
                        ->required(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->visible(fn (): bool => app(CompanyContext::class)->isAllCompanies())
                        ->helperText('Select the company that will own this offer.')
                        ->searchable()
                        ->preload()
                        ->live(),
                    Select::make('type')
                        ->options(fn (): array => array_map(fn (string $label): string => __($label), Offer::TYPES))
                        ->default(Offer::TYPE_SINGLE)
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('status')
                        ->options(Offer::STATUSES)
                        ->default(Offer::STATUS_DRAFT)
                        ->required()
                        ->native(false)
                        ->helperText('Only published offers are visible on the storefront.'),
                    TextInput::make('title')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get, ?string $state) => $get('slug') ? null : $set('slug', Offer::normalizeSlug($state)))
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(120)
                        ->alphaDash()
                        ->unique(table: 'offers', column: 'slug', ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => $rule->where('company_id', $get('company_id')))
                        ->helperText(fn (Get $get): string => $get('type') === Offer::TYPE_COLLECTION
                            ? __('Only an identifier for this collection — it has no public page.')
                            : 'Used in the public URL: /offers/your-slug'),
                    Textarea::make('short_description')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                    FileUpload::make('cover_image')
                        ->label('Cover image')
                        ->helperText('Used as the landing page hero banner and social share image. Automatically compressed to WebP.')
                        ->image()
                        ->maxSize(2048)
                        ->tap(static::browserImagePrecompression())
                        ->disk(fn (): string => CompanyMedia::publicDiskName())
                        ->directory(fn (Get $get, ?Offer $record): string => CompanyMedia::publicDirectory('offers', $record, $get('company_id')))
                        ->fetchFileInformation(false)
                        ->getUploadedFileUsing(CompanyMedia::publicFileMetadataCallback())
                        ->disabled(fn (Get $get, ?Offer $record): bool => ! CompanyMedia::canResolve($record, $get('company_id')))
                        ->imageEditor()
                        ->saveUploadedFileUsing(static::optimizeImageUpload())
                        ->hintAction(static::selectFromMediaHubAction())
                        ->columnSpanFull(),
                    AttachedVideoPreview::make('video_preview')
                        ->columnSpanFull(),
                    Toggle::make('online_payment_required')
                        ->visible(fn (Get $get): bool => $get('type') !== Offer::TYPE_COLLECTION)
                        ->label('Require full online payment')
                        ->helperText('When on, checkout for this offer requires the full amount paid online (no Cash on Delivery).')
                        ->inline(false),
                ])
                ->columns(2),

            Section::make(__('Collection'))
                ->columnSpanFull()
                ->description(__('A curated product list for the Next.js storefront. It has no landing page or checkout of its own.'))
                ->schema([
                    Select::make('collection_kind')
                        ->label(__('Collection kind'))
                        ->options(fn (): array => array_map(fn (string $label): string => __($label), Offer::COLLECTION_KINDS))
                        ->native(false)
                        ->required()
                        ->live(),
                    DateTimePicker::make('starts_at')
                        ->label(__('Starts at'))
                        ->seconds(false)
                        ->helperText(__('Leave empty to start immediately.')),
                    DateTimePicker::make('ends_at')
                        ->label(__('Ends at'))
                        ->seconds(false)
                        ->required(fn (Get $get): bool => $get('collection_kind') === Offer::KIND_FLASH_SALE)
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $starts = $get('starts_at');

                            if (filled($value) && filled($starts) && strtotime((string) $value) <= strtotime((string) $starts)) {
                                $fail(__('The end time must be after the start time.'));
                            }
                        })
                        ->helperText(__('A Flash Sale needs an end time — it drives the countdown. The collection disappears automatically after this time.')),
                ])
                ->columns(3)
                ->visible(fn (Get $get): bool => $get('type') === Offer::TYPE_COLLECTION),

            Section::make('Components')
                ->columnSpanFull()
                ->description('Combo offers: add every component product. Single offers: usually one row. Collections: add the products in display order.')
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Select::make('product_id')
                                ->label('Product')
                                ->relationship('product', 'name', fn ($query) => $query->where('is_active', true))
                                ->searchable()
                                ->live()
                                ->required()
                                ->afterStateUpdated(fn (Set $set) => $set('product_variant_id', null)),
                            Select::make('product_variant_id')
                                ->label('Variation')
                                ->options(function (Get $get): array {
                                    $product = Product::query()->with('activeVariants')->find($get('product_id'));

                                    if (! $product?->has_variants) {
                                        return [];
                                    }

                                    return $product->activeVariants
                                        ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->getKey() => $variant->label()])
                                        ->all();
                                })
                                ->visible(fn (Get $get): bool => $get('../../type') !== Offer::TYPE_COLLECTION
                                    && (bool) Product::query()->whereKey($get('product_id'))->value('has_variants'))
                                ->live(),
                            TextInput::make('quantity')
                                ->integer()
                                ->minValue(1)
                                ->default(1)
                                ->required()
                                ->visible(fn (Get $get): bool => $get('../../type') !== Offer::TYPE_COLLECTION)
                                ->live(onBlur: true),
                            TextInput::make('deal_price')
                                ->label(__('Deal price'))
                                ->numeric()
                                ->prefix('৳')
                                ->minValue(0)
                                ->visible(fn (Get $get): bool => $get('../../type') === Offer::TYPE_COLLECTION)
                                ->helperText(__('Optional. Shown instead of the normal price while this collection is live.')),
                        ])
                        ->columns(3)
                        ->defaultItems(1)
                        ->addActionLabel('Add product')
                        ->reorderable()
                        ->orderColumn('sort_order')
                        ->live()
                        ->columnSpanFull(),
                ]),

            Section::make('Pricing')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('type') !== Offer::TYPE_COLLECTION)
                ->schema([
                    Select::make('price_mode')
                        ->options(Offer::PRICE_MODES)
                        ->default(Offer::PRICE_MODE_AUTO_SUM)
                        ->required()
                        ->native(false)
                        ->live(),
                    TextInput::make('manual_price')
                        ->numeric()
                        ->prefix('৳')
                        ->minValue(0)
                        ->visible(fn (Get $get): bool => $get('price_mode') === Offer::PRICE_MODE_MANUAL)
                        ->required(fn (Get $get): bool => $get('price_mode') === Offer::PRICE_MODE_MANUAL)
                        ->live(onBlur: true),
                    Select::make('discount_type')
                        ->options(Offer::DISCOUNT_TYPES)
                        ->native(false)
                        ->placeholder('No discount')
                        ->live(),
                    TextInput::make('discount_value')
                        ->numeric()
                        ->minValue(0)
                        ->visible(fn (Get $get): bool => filled($get('discount_type')))
                        ->live(onBlur: true),
                    Placeholder::make('components_subtotal_preview')
                        ->label('Components subtotal')
                        ->content(fn (Get $get): string => MoneyFormatter::currency(static::previewComponentsSubtotal($get))),
                    Placeholder::make('final_price_preview')
                        ->label('Final selling price (preview)')
                        ->content(fn (Get $get): string => MoneyFormatter::currency(static::previewFinalPrice($get))),
                ])
                ->columns(2),

            Section::make('Landing Page')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('type') !== Offer::TYPE_COLLECTION)
                ->description('Sections shown top to bottom on the public landing page. The checkout form always appears fixed at the bottom, regardless of these sections. Use the "Generate with AI" button (after saving) to draft this automatically.')
                ->schema([
                    Repeater::make('blocks')
                        ->label('Sections')
                        ->schema([
                            Select::make('type')
                                ->options(Offer::BLOCK_TYPES)
                                ->live()
                                ->required(),

                            TextInput::make('data.headline')
                                ->label('Headline')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $get('type') === 'hero')
                                ->required(fn (Get $get): bool => $get('type') === 'hero'),
                            Textarea::make('data.subheadline')
                                ->label('Subheadline')
                                ->rows(2)
                                ->maxLength(500)
                                ->visible(fn (Get $get): bool => $get('type') === 'hero'),
                            TextInput::make('data.cta_label')
                                ->label('Button text')
                                ->maxLength(60)
                                ->default('এখনই অর্ডার করুন')
                                ->visible(fn (Get $get): bool => $get('type') === 'hero'),

                            Placeholder::make('product_gallery_note')
                                ->label('')
                                ->content('Automatically shows this offer\'s component product photos — nothing to configure here.')
                                ->visible(fn (Get $get): bool => $get('type') === 'product_gallery'),

                            TextInput::make('data.heading')
                                ->label('Heading')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => in_array($get('type'), ['usp_list', 'how_to_buy', 'faq', 'testimonials'], true)),

                            Repeater::make('data.items')
                                ->label('Points')
                                ->schema([
                                    TextInput::make('icon')->maxLength(40)->default('check'),
                                    TextInput::make('text')->required()->maxLength(255),
                                ])
                                ->columns(2)
                                ->defaultItems(1)
                                ->addActionLabel('Add point')
                                ->visible(fn (Get $get): bool => $get('type') === 'usp_list'),

                            Repeater::make('data.steps')
                                ->label('Steps')
                                ->simple(TextInput::make('step')->required()->maxLength(255))
                                ->defaultItems(1)
                                ->addActionLabel('Add step')
                                ->visible(fn (Get $get): bool => $get('type') === 'how_to_buy'),

                            Repeater::make('data.questions')
                                ->label('Questions')
                                ->schema([
                                    TextInput::make('question')->required()->maxLength(255),
                                    Textarea::make('answer')->required()->rows(2)->maxLength(1000),
                                ])
                                ->defaultItems(1)
                                ->addActionLabel('Add question')
                                ->visible(fn (Get $get): bool => $get('type') === 'faq'),

                            Select::make('data.review_ids')
                                ->label('Reviews to show (leave blank for auto top-rated)')
                                ->multiple()
                                ->options(fn (Get $get, ?Offer $record): array => ProductReview::query()
                                    ->where('company_id', $get('../../company_id') ?: $record?->company_id)
                                    ->approved()
                                    ->with('product')
                                    ->latest()
                                    ->limit(100)
                                    ->get()
                                    ->mapWithKeys(fn (ProductReview $review): array => [
                                        $review->getKey() => "{$review->customer_name} ({$review->rating}★) — {$review->product?->name}",
                                    ])
                                    ->all())
                                ->visible(fn (Get $get): bool => $get('type') === 'testimonials'),

                            RichEditor::make('data.html')
                                ->label('Content')
                                ->visible(fn (Get $get): bool => $get('type') === 'rich_text'),
                        ])
                        ->columns(1)
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => Offer::BLOCK_TYPES[$state['type'] ?? ''] ?? null)
                        ->addActionLabel('Add section')
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ]),

            Section::make('SEO')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('type') !== Offer::TYPE_COLLECTION)
                ->schema([
                    TextInput::make('meta_title')
                        ->maxLength(70),
                    Textarea::make('meta_description')
                        ->rows(3)
                        ->maxLength(160)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    /** Kept in sync with OfferPricingService::componentsSubtotal() — pure preview over unsaved form state, so it can't call the service directly. */
    public static function previewComponentsSubtotal(Get $get): float
    {
        return collect($get('items') ?? [])->sum(function (array $item): float {
            if (blank($item['product_id'] ?? null)) {
                return 0.0;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            if (filled($item['product_variant_id'] ?? null)) {
                $variant = ProductVariant::query()->find($item['product_variant_id']);

                if ($variant) {
                    return $variant->effectiveSalePrice() * $quantity;
                }
            }

            $product = Product::query()->find($item['product_id']);

            return $product ? (float) $product->selling_price * $quantity : 0.0;
        });
    }

    /** Kept in sync with OfferPricingService::finalPrice(). */
    public static function previewFinalPrice(Get $get): float
    {
        $base = $get('price_mode') === Offer::PRICE_MODE_MANUAL && filled($get('manual_price'))
            ? (float) $get('manual_price')
            : self::previewComponentsSubtotal($get);

        $discountType = $get('discount_type');
        $discountValue = (float) ($get('discount_value') ?? 0);

        if ($discountType === Offer::DISCOUNT_PERCENT && $discountValue) {
            $base -= $base * ($discountValue / 100);
        } elseif ($discountType === Offer::DISCOUNT_FLAT && $discountValue) {
            $base -= $discountValue;
        }

        return max(0, round($base, 2));
    }
}
