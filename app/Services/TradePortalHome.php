<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Support\ModuleGuard;

/**
 * The four cards of the Trade Portal home (docs/tasneem-samples/BUILD-BRIEF.md
 * Phase 1): today's sales, money still due from customers, low stock, and the
 * LC files still open. Every figure comes from the services the ERP already
 * uses, for the open company only (CompanyScope). Each card is built on its
 * own inside ModuleGuard, so one failing card leaves the others standing.
 */
class TradePortalHome
{
    /** How many rows a card lists. */
    public const LIST_LIMIT = 5;

    /** @return array{total: float, count: int}|null */
    public function todaySales(): ?array
    {
        return ModuleGuard::run('trade-home-sales', function (): array {
            $reports = app(ReportService::class);
            [$from, $to] = $reports->dateRange();

            return $reports->salesTotals($from, $to);
        });
    }

    /** @return array{total: float, count: int, customers: list<array{name: string, due: float}>}|null */
    public function dues(): ?array
    {
        return ModuleGuard::run('trade-home-dues', function (): array {
            $dues = app(CustomerDueAlertService::class);

            return [
                'total' => $dues->totalDue(),
                'count' => $dues->count(),
                'customers' => $dues->customers(self::LIST_LIMIT)
                    ->map(fn ($customer): array => ['name' => (string) $customer->name, 'due' => (float) $customer->current_balance])
                    ->values()
                    ->all(),
            ];
        });
    }

    /** @return array{count: int, products: list<array{name: string, stock: float, reorder: float}>}|null */
    public function lowStock(): ?array
    {
        return ModuleGuard::run('trade-home-low-stock', function (): array {
            // The ERP's "coming soon" purchase-cost placeholders (C&F, Duty…) are not goods on the shelf.
            $query = fn () => app(LowStockAlertService::class)->query()->where('status', '!=', Product::STATUS_COMING_SOON);

            return [
                'count' => (int) $query()->count(),
                'products' => $query()->orderBy('stock')->orderBy('name')->limit(self::LIST_LIMIT)->get()
                    ->map(fn (Product $product): array => ['name' => (string) $product->name, 'stock' => (float) $product->stock, 'reorder' => (float) $product->reorder_level])
                    ->values()
                    ->all(),
            ];
        });
    }

    /**
     * Purchases that carry an LC number and are not received or cancelled yet,
     * newest LC first, each with how far its papers have come.
     *
     * @return array{count: int, files: list<array{lc: string, supplier: string|null, date: string|null, stage: string}>}|null
     */
    public function openLcs(): ?array
    {
        return ModuleGuard::run('trade-home-lcs', function (): array {
            $query = Purchase::query()
                ->whereNotNull('lc_number')
                ->where('lc_number', '!=', '')
                ->whereNotIn('status', ['received', 'cancelled']);

            return [
                'count' => (int) (clone $query)->count(),
                'files' => $query->with('supplier')
                    ->orderByDesc('lc_date')
                    ->orderByDesc('id')
                    ->limit(self::LIST_LIMIT)
                    ->get()
                    ->map(fn (Purchase $purchase): array => [
                        'lc' => (string) $purchase->lc_number,
                        'supplier' => $purchase->supplier?->name,
                        'date' => $purchase->lc_date?->format('d/m/Y'),
                        'stage' => static::lcStage($purchase),
                    ])
                    ->values()
                    ->all(),
            ];
        });
    }

    /** The furthest paper a purchase has: the Commercial Invoice means it is shipped, else the LC is open. */
    public static function lcStage(Purchase $purchase): string
    {
        return filled($purchase->ci_number) ? __('Shipped (invoice received)') : __('LC opened');
    }
}
