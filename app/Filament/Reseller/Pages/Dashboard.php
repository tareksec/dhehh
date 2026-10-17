<?php

namespace App\Filament\Reseller\Pages;

use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ResellerCommission;
use App\Services\CompanyContext;
use App\Services\ResellerCommissionService;
use App\Support\CompanyMedia;
use App\Support\QrCodeSvg;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * The portal home page. A pending/rejected applicant sees only their status
 * (Phase 2); an approved reseller sees the overview from
 * 13_RESELLER_PORTAL_PLAN.md §3 row 1, redesigned 2026-10-12: a welcome banner
 * with the withdrawable balance, quick actions, this-month cards with a
 * last-month comparison, the store link with a QR code, the order pipeline,
 * the last 5 orders and a 6-month commission chart
 * (App\Filament\Reseller\Widgets\CommissionTrend, embedded directly in the
 * view). Every figure is read through this reseller's own relations.
 */
class Dashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected string $view = 'filament.reseller.pages.dashboard';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Dashboard');
    }

    public function getTitle(): string
    {
        return __('Dashboard');
    }

    public function reseller(): Customer
    {
        /** @var Customer $customer */
        $customer = Filament::auth()->user();

        return $customer;
    }

    protected function company(): ?Company
    {
        return app(CompanyContext::class)->company();
    }

    public function currency(): string
    {
        return $this->company()?->currency ?: 'BDT';
    }

    /** @return array{pending: float, earned: float, withdrawn: float, in_process: float, available: float} */
    public function balances(): array
    {
        return app(ResellerCommissionService::class)->balances($this->reseller());
    }

    public function waitingForDeliveryCount(): int
    {
        return $this->reseller()->resellerOrders()
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->whereNotIn('delivery_status', [
                CourierBooking::STATUS_DELIVERED,
                CourierBooking::STATUS_RETURNED,
                CourierBooking::STATUS_CANCELLED,
                CourierBooking::STATUS_FAILED,
            ])
            ->count();
    }

    /** "Good morning" / "Good afternoon" / "Good evening" by the company's local time. */
    public function greeting(): string
    {
        $hour = now($this->company()?->timezone ?: config('app.timezone'))->hour;

        return match (true) {
            $hour < 12 => __('Good morning'),
            $hour < 17 => __('Good afternoon'),
            default => __('Good evening'),
        };
    }

    /**
     * The four overview cards: this month so far, set against the same days of
     * last month, with the last 14 days as a sparkline. Only this reseller's
     * own orders and commissions are read.
     *
     * @return array<string, array{value: float|int, delta: int|null, spark: list<float|int>}>
     */
    public function kpis(): array
    {
        $today = now()->startOfDay();
        $monthStart = $today->copy()->startOfMonth();
        $previousStart = $monthStart->copy()->subMonthNoOverflow();
        $previousEnd = $previousStart->copy()->addDays($today->day - 1)->min($previousStart->copy()->endOfMonth())->startOfDay();
        $sparkStart = $today->copy()->subDays(13);

        $days = [];

        for ($day = $sparkStart->copy(); $day->lte($today); $day->addDay()) {
            $days[$day->toDateString()] = ['orders' => 0, 'sales' => 0.0, 'commission' => 0.0];
        }

        $totals = [
            'current' => ['orders' => 0, 'sales' => 0.0, 'commission' => 0.0],
            'previous' => ['orders' => 0, 'sales' => 0.0, 'commission' => 0.0],
        ];

        $tally = function (string $date, string $metric, float $amount) use (&$totals, &$days, $monthStart, $today, $previousStart, $previousEnd): void {
            $when = Carbon::parse($date);
            $period = match (true) {
                $when->between($monthStart, $today) => 'current',
                $when->between($previousStart, $previousEnd) => 'previous',
                default => null,
            };

            if ($period !== null) {
                $totals[$period][$metric] += $amount;
            }

            if (isset($days[$date])) {
                $days[$date][$metric] += $amount;
            }
        };

        $this->reseller()->resellerOrders()
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->whereDate('order_date', '>=', $previousStart)
            ->with('items')
            ->get()
            ->each(function (Order $order) use ($tally): void {
                $date = $order->order_date?->toDateString();

                if ($date === null) {
                    return;
                }

                $tally($date, 'orders', 1);
                // Own-courier orders count at the reseller's own sale price.
                $tally($date, 'sales', $order->buyerTotal());
            });

        ResellerCommission::query()
            ->where('customer_id', $this->reseller()->getKey())
            ->where('status', ResellerCommission::STATUS_PAYABLE)
            ->where('payable_at', '>=', $previousStart)
            ->get(['amount', 'payable_at'])
            ->each(function (ResellerCommission $commission) use ($tally): void {
                if ($commission->payable_at !== null) {
                    $tally($commission->payable_at->toDateString(), 'commission', (float) $commission->amount);
                }
            });

        $card = function (string $metric) use ($totals, $days): array {
            $current = $totals['current'][$metric];
            $previous = $totals['previous'][$metric];

            return [
                'value' => $metric === 'orders' ? (int) $current : round($current, 2),
                // No percentage when there is nothing to compare with.
                'delta' => $previous > 0 ? (int) round(($current - $previous) / $previous * 100) : null,
                'spark' => array_map($metric === 'orders' ? intval(...) : floatval(...), array_column($days, $metric)),
            ];
        };

        return [
            'orders' => $card('orders'),
            'sales' => $card('sales'),
            'commission' => $card('commission'),
        ];
    }

    /**
     * How many of this reseller's orders are in each live stage, in the order
     * a parcel moves through them, plus the closed ones.
     *
     * @return array{steps: array<string, int>, closed: array<string, int>}
     */
    public function pipeline(): array
    {
        $counts = array_fill_keys([
            Order::STAGE_CONFIRMED, Order::STAGE_PROCESSING, Order::STAGE_SHIPPING, Order::STAGE_COMPLETED,
            Order::STATUS_CANCELLED, Order::STATUS_RETURNED,
        ], 0);

        $this->reseller()->resellerOrders()->toBase()
            ->selectRaw('status, delivery_status, count(*) as total')
            ->groupBy('status', 'delivery_status')
            ->get()
            ->each(function (object $row) use (&$counts): void {
                $stage = (new Order)->forceFill(['status' => $row->status, 'delivery_status' => $row->delivery_status])->workflowStage();

                if (array_key_exists($stage, $counts)) {
                    $counts[$stage] += (int) $row->total;
                }
            });

        return [
            'steps' => array_intersect_key($counts, array_flip([
                Order::STAGE_CONFIRMED, Order::STAGE_PROCESSING, Order::STAGE_SHIPPING, Order::STAGE_COMPLETED,
            ])),
            'closed' => array_filter(array_intersect_key($counts, array_flip([Order::STATUS_CANCELLED, Order::STATUS_RETURNED]))),
        ];
    }

    /** @return Collection<int, Order> */
    public function recentOrders(): Collection
    {
        return $this->reseller()->resellerOrders()
            ->with(['customer', 'items.product'])
            ->latest('order_date')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    /** The first product's photo and name for an order row, with how many other lines it has. */
    public function orderSummary(Order $order): array
    {
        $first = $order->items->first();
        $product = $first?->product;
        $path = $product ? ($product->image ?: Arr::first($product->gallery_images ?? [])) : null;

        return [
            'name' => $product ? $product->name.($first->variant_label ? ' ('.$first->variant_label.')' : '') : '—',
            'image' => $product ? CompanyMedia::filamentPublicUrl($path, $product) : null,
            'more' => max(0, $order->items->count() - 1),
        ];
    }

    /**
     * The company's notice for resellers (Reseller Settings → Portal
     * announcement), or null when it is off or empty.
     */
    public function announcement(): ?string
    {
        $setting = $this->company()?->storefrontSetting;

        if (! $setting || ! $setting->reseller_announcement_enabled || blank($setting->reseller_announcement)) {
            return null;
        }

        return trim((string) $setting->reseller_announcement);
    }

    /**
     * The first things a new reseller sets up, each with whether it is done and
     * where to do it. The checklist disappears once everything is done.
     *
     * @return list<array{key: string, label: string, hint: string, done: bool, url: string}>
     */
    public function firstSteps(): array
    {
        $reseller = $this->reseller();

        return [
            [
                'key' => 'store-name',
                'label' => __('Name your store'),
                'hint' => __('Your customers see this name, not ours.'),
                'done' => filled($reseller->reseller_store_name),
                'url' => MyStore::getUrl(),
            ],
            [
                'key' => 'products',
                'label' => __('Add products to your store'),
                'hint' => __('Pick what you sell and set your prices.'),
                'done' => $reseller->resellerProducts()->where('is_active', true)->exists(),
                'url' => Products::getUrl(),
            ],
            [
                'key' => 'payout-account',
                'label' => __('Add a payout account'),
                'hint' => __('This is where your profit is sent when you withdraw.'),
                'done' => $reseller->resellerPayoutAccounts()->exists(),
                'url' => PayoutAccounts::getUrl(),
            ],
            [
                'key' => 'first-order',
                'label' => __('Get your first order'),
                'hint' => __('Share your store link, or create an order yourself.'),
                'done' => $reseller->resellerOrders()->exists(),
                'url' => CreateOrder::getUrl(),
            ],
        ];
    }

    /**
     * The products this reseller sold the most pieces of in the last 30 days,
     * from their own orders only (cancelled, returned and draft orders do not
     * count). A product that no longer exists is left out.
     *
     * @return list<array{name: string, image: ?string, pieces: int}>
     */
    public function bestSellers(): array
    {
        $ownOrderIds = $this->reseller()->resellerOrders()
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->whereDate('order_date', '>=', now()->subDays(29)->toDateString())
            ->select('orders.id');

        $totals = OrderItem::query()
            ->whereIn('order_id', $ownOrderIds)
            ->selectRaw('product_id, SUM(quantity) as pieces')
            ->groupBy('product_id')
            ->orderByDesc('pieces')
            ->orderBy('product_id')
            ->limit(5)
            ->pluck('pieces', 'product_id');

        if ($totals->isEmpty()) {
            return [];
        }

        $products = Product::query()->whereIn('id', $totals->keys())->get()->keyBy('id');

        return $totals
            ->map(function ($pieces, $productId) use ($products): ?array {
                /** @var Product|null $product */
                $product = $products->get($productId);

                if (! $product) {
                    return null;
                }

                $path = $product->image ?: Arr::first($product->gallery_images ?? []);

                return [
                    'name' => $product->name,
                    'image' => CompanyMedia::filamentPublicUrl($path, $product),
                    'pieces' => (int) $pieces,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function hasStoreSlug(): bool
    {
        return filled($this->reseller()->reseller_slug);
    }

    public function storeUrl(): ?string
    {
        return $this->reseller()->resellerStoreUrl();
    }

    /** The store link as a QR code, for printing on a card or showing a walk-in customer. */
    public function storeQrCode(): ?string
    {
        $url = $this->storeUrl();

        return $url ? QrCodeSvg::dataUri($url) : null;
    }

    /** The reseller picked a subdomain that is not reachable yet (My store shows the countdown). */
    public function subdomainIsSettingUp(): bool
    {
        $reseller = $this->reseller();

        return filled($reseller->reseller_subdomain) && ! $reseller->resellerSubdomainIsReady();
    }
}
