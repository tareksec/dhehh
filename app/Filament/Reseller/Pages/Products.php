<?php

namespace App\Filament\Reseller\Pages;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
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

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('Products');
    }

    /** @var array<int, array{is_active: bool, price: ?float}> */
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
            ->get(['product_id', 'is_active', 'price'])
            ->mapWithKeys(fn (ResellerProduct $row): array => [
                (int) $row->product_id => [
                    'is_active' => (bool) $row->is_active,
                    'price' => $row->price === null ? null : (float) $row->price,
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
                ->where('is_active', true)
                ->where('status', Product::STATUS_AVAILABLE))
            ->columns([
                ImageColumn::make('image')
                    ->label('')
                    ->state(fn (Product $record): ?string => CompanyMedia::filamentPublicUrl($record->image, $record))
                    ->height(40)
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
                    ->state(fn (Product $record): string => MoneyFormatter::currency($record->baseSellingPrice(), $currency)),

                TextColumn::make('your_cost')
                    ->label(__('Your cost'))
                    ->state(function (Product $record) use ($costPerUnit, $currency): string {
                        $base = ResellerPricing::basePrice($record, $costPerUnit);

                        return $base === null ? __('No wholesale rate') : MoneyFormatter::currency($base, $currency);
                    }),

                TextInputColumn::make('your_price')
                    ->label(__('Your price'))
                    ->type('number')
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->getStateUsing(fn (Product $record): ?float => $this->picks[$record->getKey()]['price'] ?? null)
                    ->updateStateUsing(function (Product $record, mixed $state) use ($costPerUnit): ?float {
                        return $this->setPrice($record, $state === null || $state === '' ? null : (float) $state, $costPerUnit);
                    })
                    ->disabled(fn (Product $record): bool => ! ($this->picks[$record->getKey()]['is_active'] ?? false)
                        || ResellerPricing::basePrice($record, $costPerUnit) === null),

                TextColumn::make('commission_per_unit')
                    ->label(__('Commission per unit'))
                    ->state(function (Product $record) use ($costPerUnit, $currency): string {
                        $base = ResellerPricing::basePrice($record, $costPerUnit);
                        $pick = $this->picks[$record->getKey()] ?? null;

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
                        ->where('has_variants', true)
                        ->orWhereNull('tier_prices')
                        ->orWhereJsonLength('tier_prices', 0)))
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
            ->recordActions([
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
                    ->visible(fn (Product $record): bool => filled($record->image) || filled($record->gallery_images))
                    ->url(fn (Product $record): string => route('reseller.portal.products.photos', $record))
                    ->openUrlInNewTab(),
            ])
            ->searchPlaceholder(__('Search name or SKU...'))
            ->defaultSort('name');
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
