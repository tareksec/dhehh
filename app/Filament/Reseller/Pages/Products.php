<?php

namespace App\Filament\Reseller\Pages;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ResellerProduct;
use App\Services\CompanyContext;
use App\Services\ResellerPricing;
use App\Support\ClipboardCopy;
use App\Support\CompanyMedia;
use App\Support\MoneyFormatter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Reseller portal Phase 4 (13_RESELLER_PORTAL_PLAN.md §3 row 3): the
 * company catalog with an "in my store" toggle, the reseller's own price
 * (never below the wholesale-tier-derived base price -- ResellerPricing is
 * the single source of truth for that floor, same rule the storefront's own
 * price-update endpoint enforces), and per-product share/download actions.
 *
 * Picks and prices are loaded once per request into $picks (product_id =>
 * ['is_active' => bool, 'price' => ?float]) and mutated in place so the
 * table doesn't need to re-query ResellerProduct on every cell.
 */
class Products extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected string $view = 'filament.reseller.pages.products';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('Products');
    }

    /** @var array<int, array{is_active: bool, price: ?float, variant_prices: array<int, float>}> */
    public array $picks = [];

    public static function canAccess(array $parameters = []): bool
    {
        $reseller = Filament::auth()->user();

        return $reseller instanceof Customer && $reseller->isApprovedReseller();
    }

    public function getTitle(): string
    {
        return __('Products');
    }

    public function mount(): void
    {
        $this->reloadPicks();
    }

    public function reseller(): Customer
    {
        /** @var Customer $customer */
        $customer = Filament::auth()->user();

        return $customer;
    }

    protected function company(): Company
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->company();

        return $company;
    }

    protected function costPerUnit(): float
    {
        return (float) ($this->company()->storefrontSetting?->reseller_cost_per_unit ?? 0);
    }

    protected function reloadPicks(): void
    {
        $this->picks = ResellerProduct::query()
            ->where('customer_id', $this->reseller()->getKey())
            ->get(['product_id', 'is_active', 'price', 'variant_prices'])
            ->mapWithKeys(fn (ResellerProduct $row): array => [
                (int) $row->product_id => [
                    'is_active' => (bool) $row->is_active,
                    'price' => $row->price === null ? null : (float) $row->price,
                    'variant_prices' => collect($row->variant_prices ?? [])
                        ->filter(fn ($price): bool => is_numeric($price))
                        ->mapWithKeys(fn ($price, $variantId): array => [(int) $variantId => (float) $price])
                        ->all(),
                ],
            ])
            ->all();
    }

    public function table(Table $table): Table
    {
        $costPerUnit = $this->costPerUnit();
        $currency = $this->company()->currency ?: 'BDT';

        return $table
            ->query(fn (): Builder => Product::query()
                ->with(['category', 'variants'])
                ->where('is_active', true)
                ->where('status', Product::STATUS_AVAILABLE))
            ->columns([
                // The main image, else the first gallery photo; opens the details on click.
                ImageColumn::make('image')
                    ->label('')
                    ->state(fn (Product $record): ?string => CompanyMedia::filamentPublicUrl($record->image ?: Arr::first($record->gallery_images ?? []), $record))
                    ->height(64)
                    ->square(),

                TextColumn::make('name')
                    ->label(__('Product'))
                    ->description(fn (Product $record): ?string => $record->sku)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label(__('Category'))
                    ->toggleable(),

                ToggleColumn::make('in_store')
                    ->label(__('In my store'))
                    ->getStateUsing(fn (Product $record): bool => $this->picks[$record->getKey()]['is_active'] ?? false)
                    ->updateStateUsing(function (Product $record, mixed $state): bool {
                        $this->setInStore($record, (bool) $state);

                        return (bool) $state;
                    }),

                TextColumn::make('suggested_price')
                    ->label(__('Suggested price'))
                    ->state(function (Product $record) use ($currency): string {
                        if ($record->has_variants && ($rows = $this->variationRows($record)) !== []) {
                            return __('From :amount', ['amount' => MoneyFormatter::currency(min(array_column($rows, 'suggested')), $currency)]);
                        }

                        return MoneyFormatter::currency($record->baseSellingPrice(), $currency);
                    }),

                TextColumn::make('your_cost')
                    ->label(__('Your cost'))
                    ->state(function (Product $record) use ($costPerUnit, $currency): string {
                        if ($record->has_variants) {
                            $costs = array_filter(array_column($this->variationRows($record), 'cost'), fn ($cost): bool => $cost !== null);

                            return $costs === [] ? __('No wholesale rate') : __('From :amount', ['amount' => MoneyFormatter::currency(min($costs), $currency)]);
                        }

                        $base = ResellerPricing::basePrice($record, $costPerUnit);

                        return $base === null ? __('No wholesale rate') : MoneyFormatter::currency($base, $currency);
                    }),

                // A product with variations is priced per variation (the Variation prices action).
                TextInputColumn::make('your_price')
                    ->label(__('Your price'))
                    ->type('number')
                    ->placeholder(fn (Product $record): ?string => $record->has_variants ? __('Per variation') : null)
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->getStateUsing(fn (Product $record): ?float => $this->picks[$record->getKey()]['price'] ?? null)
                    ->updateStateUsing(function (Product $record, mixed $state) use ($costPerUnit): ?float {
                        return $this->setPrice($record, $state === null || $state === '' ? null : (float) $state, $costPerUnit);
                    })
                    ->disabled(fn (Product $record): bool => ! ($this->picks[$record->getKey()]['is_active'] ?? false)
                        || $record->has_variants
                        || ResellerPricing::basePrice($record, $costPerUnit) === null),

                TextColumn::make('commission_per_unit')
                    ->label(__('Commission per unit'))
                    ->state(function (Product $record) use ($costPerUnit, $currency): string {
                        $pick = $this->picks[$record->getKey()] ?? null;

                        if ($record->has_variants) {
                            $commissions = array_filter(array_column($this->variationRows($record), 'commission'), fn ($commission): bool => $commission !== null);

                            if (! ($pick['is_active'] ?? false) || $commissions === []) {
                                return '—';
                            }

                            return min($commissions) === max($commissions)
                                ? MoneyFormatter::currency(min($commissions), $currency)
                                : MoneyFormatter::number(min($commissions)).' – '.MoneyFormatter::currency(max($commissions), $currency);
                        }

                        $base = ResellerPricing::basePrice($record, $costPerUnit);

                        if ($base === null || ! ($pick['is_active'] ?? false)) {
                            return '—';
                        }

                        $price = $pick['price'] ?? $record->baseSellingPrice();

                        return MoneyFormatter::currency(max(0, $price - $base), $currency);
                    }),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label(__('Category'))
                    ->relationship('category', 'name')
                    ->searchable(),

                Filter::make('in_store')
                    ->label(__('In my store'))
                    ->query(fn (Builder $query): Builder => $query->whereIn('id', $this->activePickedProductIds()))
                    ->toggle(),

                Filter::make('not_in_store')
                    ->label(__('Not in my store'))
                    ->query(fn (Builder $query): Builder => $query->whereNotIn('id', $this->activePickedProductIds()))
                    ->toggle(),

                Filter::make('no_wholesale_rate')
                    ->label(__('No wholesale rate'))
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q
                        ->where(fn (Builder $plain) => $plain
                            ->where('has_variants', false)
                            ->where(fn (Builder $tiers) => $tiers->whereNull('tier_prices')->orWhereJsonLength('tier_prices', 0)))
                        ->orWhere(fn (Builder $variable) => $variable
                            ->where('has_variants', true)
                            ->whereDoesntHave('variants', fn (Builder $variants) => $variants->where('is_active', true)))))
                    ->toggle(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('addToStore')
                        ->label(__('Add to store'))
                        ->icon(Heroicon::OutlinedPlus)
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $this->setInStore($record, true);
                            }

                            $this->notifyDone(__('Added :count products to your store.', ['count' => $records->count()]));
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('removeFromStore')
                        ->label(__('Remove from store'))
                        ->icon(Heroicon::OutlinedMinus)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $this->setInStore($record, false);
                            }

                            $this->notifyDone(__('Removed :count products from your store.', ['count' => $records->count()]));
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('setPriceFromSuggested')
                        ->label(__('Set price = suggested + %'))
                        ->icon(Heroicon::OutlinedCalculator)
                        ->schema([
                            TextInput::make('percent')
                                ->label(__('Percent above suggested price'))
                                ->numeric()
                                ->default(0)
                                ->minValue(-100)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) use ($costPerUnit): void {
                            $percent = (float) $data['percent'];
                            $updated = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                $base = ResellerPricing::basePrice($record, $costPerUnit);
                                $inStore = $this->picks[$record->getKey()]['is_active'] ?? false;

                                if ($record->has_variants) {
                                    // Each priced variation gets the suggested price + the percent.
                                    $priced = $inStore ? $this->setVariationPricesFromSuggested($record, $percent) : false;
                                    $priced ? $updated++ : $skipped++;

                                    continue;
                                }

                                if (! $inStore || $base === null) {
                                    $skipped++;

                                    continue;
                                }

                                $price = round($record->baseSellingPrice() * (1 + $percent / 100), 2);
                                $this->setPrice($record, max($price, $base), $costPerUnit);
                                $updated++;
                            }

                            $this->notifyDone(__('Updated the price for :updated products (:skipped skipped -- not in your store or no wholesale rate).', [
                                'updated' => $updated,
                                'skipped' => $skipped,
                            ]));
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->recordAction('viewDetails')
            ->recordActions([
                Action::make('viewDetails')
                    ->label(__('Details'))
                    ->icon(Heroicon::OutlinedEye)
                    ->slideOver()
                    ->modalWidth('3xl')
                    ->modalHeading(fn (Product $record): string => $record->name)
                    ->modalContent(fn (Product $record) => view('filament.reseller.pages.partials.product-details', $this->detailsFor($record)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),

                Action::make('variationPrices')
                    ->label(__('Variation prices'))
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible(fn (Product $record): bool => $record->has_variants && ($this->picks[$record->getKey()]['is_active'] ?? false))
                    ->modalHeading(fn (Product $record): string => __('Variation prices: :name', ['name' => $record->name]))
                    ->modalDescription(__('Set the price your customer pays for each variation, never below what you pay the store. Leave a price empty to use the suggested price.'))
                    ->schema(fn (Product $record): array => $this->variationFields($record))
                    ->fillForm(fn (Product $record): array => $this->variationFormState($record))
                    ->action(fn (Product $record, array $data) => $this->saveVariationPrices($record, $data)),

                Action::make('copyLink')
                    ->label(__('Copy link'))
                    ->icon(Heroicon::OutlinedLink)
                    ->alpineClickHandler(fn (Product $record): string => ClipboardCopy::alpineHandler(
                        $this->storeProductUrl($record),
                        __('Copied to clipboard'),
                    )),

                Action::make('shareWhatsapp')
                    ->label(__('Share on WhatsApp'))
                    ->icon(Heroicon::OutlinedShare)
                    ->url(fn (Product $record): string => 'https://wa.me/?text='.rawurlencode($record->name.' -- '.$this->storeProductUrl($record)))
                    ->openUrlInNewTab(),

                Action::make('downloadPhotos')
                    ->label(__('Download photos'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->visible(fn (Product $record): bool => $record->allImagePaths() !== [])
                    ->url(fn (Product $record): string => route('reseller.portal.products.photos', $record))
                    ->openUrlInNewTab(),
            ])
            ->searchPlaceholder(__('Search name or SKU...'))
            ->defaultSort('name');
    }

    /**
     * Everything the details slide-over shows. Only the product's own facts
     * and the signed-in reseller's own cost, price and commission: the
     * picks come from $this->picks (this reseller's rows only), the cost is
     * the company-wide rate, and no other reseller's data is ever read.
     * The company's purchase cost and wholesale tiers are not shown.
     *
     * @return array<string, mixed>
     */
    public function detailsFor(Product $record): array
    {
        $costPerUnit = $this->costPerUnit();
        $base = ResellerPricing::basePrice($record, $costPerUnit);
        $pick = $this->picks[$record->getKey()] ?? null;
        $inStore = (bool) ($pick['is_active'] ?? false);
        $suggested = $record->baseSellingPrice();
        $yourPrice = $inStore ? ($pick['price'] ?? $suggested) : null;

        return [
            'product' => $record->loadMissing(['category', 'variants']),
            'currency' => $this->company()->currency ?: 'BDT',
            'images' => $record->allImagePaths(),
            'activeVariants' => $record->variants->where('is_active', true)->values(),
            'variationRows' => $this->variationRows($record),
            'cost' => $base,
            'suggested' => $suggested,
            'inStore' => $inStore,
            'yourPrice' => $yourPrice,
            'commission' => $base !== null && $yourPrice !== null ? max(0, $yourPrice - $base) : null,
            'storeUrl' => $this->storeProductUrl($record),
        ];
    }

    /**
     * What this reseller sees for each active variation of $record: the
     * suggested (normal) price, what they pay the store (its wholesale price
     * plus the reseller cost per unit; null when the variation has no price),
     * their own customer price and the commission per unit. Only this
     * reseller's own saved prices are read.
     *
     * @return list<array{variant: ProductVariant, suggested: float, cost: ?float, price: ?float, commission: ?float}>
     */
    protected function variationRows(Product $record): array
    {
        $pick = $this->picks[$record->getKey()] ?? null;
        $inStore = (bool) ($pick['is_active'] ?? false);

        return $record->variants
            ->where('is_active', true)
            ->map(function (ProductVariant $variant) use ($pick, $inStore): array {
                $cost = $variant->resellerCost($this->costPerUnit());
                $price = $inStore ? ResellerPricing::variantPrice($variant, $pick['variant_prices'][$variant->getKey()] ?? null, $this->costPerUnit()) : null;

                return [
                    'variant' => $variant,
                    'suggested' => $variant->baseSalePrice(),
                    'cost' => $cost,
                    'price' => $inStore ? ($price ?? $variant->baseSalePrice()) : null,
                    'commission' => $cost !== null && $price !== null ? max(0.0, round($price - $cost, 2)) : null,
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<TextInput> */
    protected function variationFields(Product $record): array
    {
        $currency = $this->company()->currency ?: 'BDT';

        return collect($this->variationRows($record))
            ->map(fn (array $row): TextInput => TextInput::make('price_'.$row['variant']->getKey())
                ->label($row['variant']->label() ?: ($row['variant']->sku ?: '#'.$row['variant']->getKey()))
                ->numeric()
                ->prefix($currency)
                ->minValue($row['cost'])
                ->disabled($row['cost'] === null)
                ->placeholder(MoneyFormatter::number($row['suggested']))
                ->helperText($row['cost'] === null
                    ? __('This variation has no price yet, so it cannot be sold.')
                    : __('You pay :cost. Suggested price :suggested.', ['cost' => MoneyFormatter::currency($row['cost'], $currency), 'suggested' => MoneyFormatter::currency($row['suggested'], $currency)])))
            ->all();
    }

    /** @return array<string, ?float> */
    protected function variationFormState(Product $record): array
    {
        $saved = $this->picks[$record->getKey()]['variant_prices'] ?? [];

        return collect($this->variationRows($record))
            ->mapWithKeys(fn (array $row): array => ['price_'.$row['variant']->getKey() => $row['cost'] === null ? null : ($saved[$row['variant']->getKey()] ?? null)])
            ->all();
    }

    /**
     * Saves the reseller's customer price for each variation that has a
     * price. A price below it is refused; a blank one goes back to the
     * suggested price. Only this reseller's own pick is changed.
     *
     * @param  array<string, mixed>  $data
     */
    protected function saveVariationPrices(Product $record, array $data): void
    {
        $pick = ResellerProduct::query()
            ->where('customer_id', $this->reseller()->getKey())
            ->where('product_id', $record->getKey())
            ->first();

        if (! $pick) {
            Notification::make()->title(__('Add this product to your store first.'))->warning()->send();

            return;
        }

        $prices = [];

        foreach ($record->variants->where('is_active', true) as $variant) {
            $cost = $variant->resellerCost($this->costPerUnit());
            $raw = $data['price_'.$variant->getKey()] ?? null;

            if ($cost === null || $raw === null || $raw === '') {
                continue;
            }

            $price = round((float) $raw, 2);

            if ($price < $cost) {
                Notification::make()
                    ->title(__('Your price for :variation must be at least :amount.', ['variation' => $variant->label(), 'amount' => MoneyFormatter::number($cost)]))
                    ->danger()
                    ->send();

                return;
            }

            $prices[$variant->getKey()] = $price;
        }

        $pick->update(['variant_prices' => $prices === [] ? null : $prices]);
        $this->reloadPicks();

        $this->notifyDone(__('Variation prices saved.'));
    }

    /** Sets every priced variation to its suggested price + $percent (never below its store price). */
    protected function setVariationPricesFromSuggested(Product $record, float $percent): bool
    {
        $pick = ResellerProduct::query()
            ->where('customer_id', $this->reseller()->getKey())
            ->where('product_id', $record->getKey())
            ->first();
        $prices = [];

        foreach ($record->variants->where('is_active', true) as $variant) {
            $cost = $variant->resellerCost($this->costPerUnit());

            if ($cost !== null) {
                $prices[$variant->getKey()] = max(round($variant->baseSalePrice() * (1 + $percent / 100), 2), $cost);
            }
        }

        if (! $pick || $prices === []) {
            return false;
        }

        $pick->update(['variant_prices' => $prices]);
        $this->reloadPicks();

        return true;
    }

    protected function setInStore(Product $record, bool $inStore): void
    {
        $existing = ResellerProduct::query()
            ->where('customer_id', $this->reseller()->getKey())
            ->where('product_id', $record->getKey())
            ->first();

        if ($inStore && ! $existing) {
            ResellerProduct::query()->create([
                'customer_id' => $this->reseller()->getKey(),
                'product_id' => $record->getKey(),
                'is_active' => true,
            ]);
        } elseif (! $inStore && $existing) {
            $existing->delete();
        }

        $this->reloadPicks();
    }

    protected function setPrice(Product $record, ?float $price, float $costPerUnit): ?float
    {
        $pick = ResellerProduct::query()
            ->where('customer_id', $this->reseller()->getKey())
            ->where('product_id', $record->getKey())
            ->first();

        if (! $pick) {
            Notification::make()
                ->title(__('Add this product to your store first.'))
                ->warning()
                ->send();

            return $this->picks[$record->getKey()]['price'] ?? null;
        }

        $base = ResellerPricing::basePrice($record, $costPerUnit);

        if ($base !== null && $price !== null && $price < $base) {
            Notification::make()
                ->title(__('Your price must be at least :amount.', ['amount' => MoneyFormatter::number($base)]))
                ->danger()
                ->send();

            return $pick->price === null ? null : (float) $pick->price;
        }

        $pick->update(['price' => $price === null ? null : round($price, 2)]);
        $this->reloadPicks();

        return $price;
    }

    /** @return array<int, int> */
    protected function activePickedProductIds(): array
    {
        return SupportCollection::make($this->picks)
            ->filter(fn (array $pick): bool => $pick['is_active'])
            ->keys()
            ->all();
    }

    protected function storeProductUrl(Product $record): string
    {
        $company = $this->company();
        $reseller = $this->reseller();

        return 'https://'.$company->domain.route('storefront.reseller_store.products.show', [$reseller->reseller_slug, $record->slug], false);
    }

    protected function notifyDone(string $message): void
    {
        Notification::make()->title($message)->success()->send();
    }
}
