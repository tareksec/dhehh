<?php

namespace App\Filament\Resources\Products\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Resources\Products\Pages\BulkUpdateStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Support\MoneyFormatter;
use Filament\Widgets\Widget;

/**
 * The stat cards Nuport shows at the top of its "Products & Pricing" list
 * (Total SKU / Total Variants / New SKU / Active SKU / Inactive SKU) plus
 * 5 of its Inventory page's header cards that map onto real data we track:
 * Total Available Quantity / Total Stock Value / Total Purchase Cost, plus
 * **Total Shortage** (products at/below `reorder_level` — the exact same
 * predicate `ProductsTable`'s existing "Low stock" filter already uses,
 * not a new rule) and **Total Damage** (owner-requested in place of
 * Nuport's "Total Wastage", which we don't track — damage is a real
 * `StockMovement` type instead, see that model). Nuport's own "Total
 * Expired" has no backing concept anywhere in this app (no expiry-date
 * field) and is deliberately not built.
 *
 * Nuport counts every SKU-bearing row — a variant-less product is one
 * row, a variant-bearing product is one "Base Product" row plus one row
 * per variant — so the SKU-count stats sum the equivalent `Product` +
 * `ProductVariant` counts rather than counting products alone. Both
 * models are company-scoped via `CompanyScope` already.
 *
 * The quantity/value stats (Available Quantity, Stock Value) must NOT
 * naively sum Product + ProductVariant: a variant-bearing Product's own
 * `stock` is already kept as a live mirror of the sum of its variants'
 * stock (see ProductVariant::syncProductStock()), so summing both would
 * double-count. Those stats therefore only include simple (non-variant)
 * products' own stock, plus the full variant stock on top.
 *
 * Hand-built (not `StatsOverviewWidget`) so the grid can be a precise
 * 5-columns-desktop/2-columns-mobile layout with a specific value font
 * size and card padding — same reasoning and the same `fi-wi-stats-
 * overview-stat*` native Filament CSS classes `CourierMerchantDashboard`'s
 * hand-built status cards already reuse, so this renders pixel-consistent
 * with every other stat card in the app rather than an approximated card.
 */
class ProductStatsOverview extends Widget
{
    use GuardsWidgetRendering;

    protected static bool $isLazy = false;

    // Plain Widget defaults to a single header-grid column. The card grid
    // itself is responsive, so let it use the full Products header width,
    // matching Filament's native StatsOverviewWidget/main Dashboard layout.
    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.resources.products.widgets.product-stats-overview';

    // Nuport's own "New SKU" value (0) doesn't reveal its window; 7 days
    // matches the common default for "new" badges elsewhere in this app.
    private const NEW_WINDOW_DAYS = 7;

    public function formatCurrency(int|float $value): string
    {
        return html_entity_decode('&#2547;').' '.MoneyFormatter::number($value);
    }

    /**
     * @return array<int, array{label: string, value: int|float, icon: string, valueColor: ?string, isCurrency?: bool, url?: string}>
     */
    public function stats(): array
    {
        $totalProducts = Product::query()->count();
        $totalVariants = ProductVariant::query()->count();

        $newSince = now()->subDays(self::NEW_WINDOW_DAYS);
        $newSkus = Product::query()->where('created_at', '>=', $newSince)->count()
            + ProductVariant::query()->where('created_at', '>=', $newSince)->count();

        $activeSkus = Product::query()->where('is_active', true)->count()
            + ProductVariant::query()->where('is_active', true)->count();

        $totalSkus = $totalProducts + $totalVariants;
        $inactiveSkus = $totalSkus - $activeSkus;

        $simpleProducts = Product::query()->where('has_variants', false)->get(['stock', 'cost_price']);
        $variants = ProductVariant::query()->with('product:id,cost_price')->get(['id', 'product_id', 'stock', 'cost_price']);

        $availableQuantity = (int) $simpleProducts->sum('stock') + (int) $variants->sum('stock');

        $stockValue = $simpleProducts->sum(fn (Product $product): float => (int) $product->stock * (float) $product->cost_price)
            + $variants->sum(fn (ProductVariant $variant): float => (int) $variant->stock * $variant->effectiveCostPrice());

        // allocated_cost only holds the item's *share of extra landed costs*
        // (freight, duty, custom China-to-BD charges, etc.) — the item's own
        // subtotal (unit_cost x quantity) is a separate column. "Total
        // Purchase Cost" is everything actually spent, so it's the sum of
        // both, matching Purchase::landedCostTotal()'s per-purchase formula.
        $purchaseCost = (float) PurchaseItem::query()
            ->whereHas('purchase', fn ($query) => $query->where('status', 'received'))
            ->selectRaw('COALESCE(SUM(subtotal + allocated_cost), 0) as total')
            ->value('total');

        // Same predicate as ProductsTable's existing "Low stock" filter.
        // Scoped to simple + variant-bearing Product rows only — variants
        // have no reorder_level of their own, and the main Products list
        // itself doesn't list variant rows separately either.
        $shortageCount = Product::query()->whereColumn('stock', '<=', 'reorder_level')->count();

        // Damage quantities are stored positive (normalizeQuantity treats
        // 'damage' like 'sale'), so a plain sum is the total pieces damaged.
        $totalDamage = (int) StockMovement::query()->where('type', 'damage')->sum('quantity');

        return [
            ['label' => 'Total SKU', 'value' => $totalSkus, 'icon' => 'heroicon-o-tag', 'valueColor' => null],
            ['label' => 'Total Variants', 'value' => $totalVariants, 'icon' => 'heroicon-o-swatch', 'valueColor' => null],
            ['label' => 'New SKU', 'value' => $newSkus, 'icon' => 'heroicon-o-sparkles', 'valueColor' => null],
            ['label' => 'Active SKU', 'value' => $activeSkus, 'icon' => 'heroicon-o-check-circle', 'valueColor' => 'text-success-600 dark:text-success-400'],
            ['label' => 'Inactive SKU', 'value' => $inactiveSkus, 'icon' => 'heroicon-o-x-circle', 'valueColor' => $inactiveSkus > 0 ? 'text-danger-600 dark:text-danger-400' : null],
            ['label' => 'Total Available Quantity', 'value' => $availableQuantity, 'icon' => 'heroicon-o-archive-box', 'valueColor' => null],
            ['label' => 'Total Stock Value', 'value' => $stockValue, 'icon' => 'heroicon-o-banknotes', 'valueColor' => 'text-info-600 dark:text-info-400', 'isCurrency' => true],
            ['label' => 'Total Purchase Cost', 'value' => $purchaseCost, 'icon' => 'heroicon-o-shopping-cart', 'valueColor' => 'text-success-600 dark:text-success-400', 'isCurrency' => true],
            [
                'label' => 'Total Shortage',
                'value' => $shortageCount,
                'icon' => 'heroicon-o-exclamation-triangle',
                'valueColor' => $shortageCount > 0 ? 'text-danger-600 dark:text-danger-400' : null,
                'url' => BulkUpdateStock::getUrl().'?shortageOnly=1',
            ],
            ['label' => 'Total Damage', 'value' => $totalDamage, 'icon' => 'heroicon-o-exclamation-circle', 'valueColor' => $totalDamage > 0 ? 'text-danger-600 dark:text-danger-400' : null],
        ];
    }
}
