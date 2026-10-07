<?php

namespace App\Filament\Reseller\Pages;

use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ResellerCommission;
use App\Services\CompanyContext;
use App\Services\ResellerCommissionService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * The portal home page. A pending/rejected applicant sees only their status
 * (Phase 2); an approved reseller sees the overview from
 * 13_RESELLER_PORTAL_PLAN.md §3 row 1: balances, a 6-month commission
 * chart (App\Filament\Reseller\Widgets\CommissionTrend, embedded directly
 * in the view), the last 5 orders, and their store link.
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

    public function thisMonthOrdersCount(): int
    {
        return $this->reseller()->resellerOrders()
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->whereDate('order_date', '>=', now()->startOfMonth())
            ->count();
    }

    public function thisMonthSales(): float
    {
        return (float) $this->reseller()->resellerOrders()
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->whereDate('order_date', '>=', now()->startOfMonth())
            ->with('items')
            ->get()
            // Own-courier orders count at the reseller's own sale price.
            ->sum(fn (Order $order): float => $order->buyerTotal());
    }

    public function thisMonthCommission(): float
    {
        return (float) ResellerCommission::query()
            ->where('customer_id', $this->reseller()->getKey())
            ->where('status', ResellerCommission::STATUS_PAYABLE)
            ->where('payable_at', '>=', now()->startOfMonth())
            ->sum('amount');
    }

    /** @return Collection<int, Order> */
    public function recentOrders(): Collection
    {
        return $this->reseller()->resellerOrders()
            ->latest('order_date')
            ->limit(5)
            ->get();
    }

    public function hasStoreSlug(): bool
    {
        return filled($this->reseller()->reseller_slug);
    }

    public function storeUrl(): ?string
    {
        return $this->reseller()->resellerStoreUrl();
    }
}
