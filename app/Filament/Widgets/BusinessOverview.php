<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\TransactionLedgers\TransactionLedgerResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Services\CustomerDueAlertService;
use App\Services\LowStockAlertService;
use App\Services\ReportService;
use App\Support\MoneyFormatter;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Every stat card except Account Balance opens a modal listing the records
 * behind that number (dashboard drilldown, 09_DASHBOARD_WOOCOMMERCE_SECURITY_PLAN.md
 * step 1.2). Stays a StatsOverviewWidget (not a bespoke Widget) so the grid,
 * spacing, and responsive columns are byte-identical to every other stats
 * widget in the app — only the view is swapped to also render action modals.
 */
class BusinessOverview extends StatsOverviewWidget implements HasActions
{
    use GuardsWidgetRendering;
    use InteractsWithActions;

    protected ?string $heading = 'Business Overview';

    protected ?string $description = 'Today and current balance summary';

    protected string $view = 'filament.widgets.business-overview';

    protected int $drilldownLimit = 50;

    /**
     * Keep the overview dense on both phone and desktop while retaining
     * Filament's native responsive grid implementation.
     *
     * @return array<string, int>
     */
    protected function getColumns(): array
    {
        return [
            'default' => 2,
            'lg' => 5,
        ];
    }

    protected function getStats(): array
    {
        $summary = app(ReportService::class)->dashboardSummary();

        $stats = [
            Stat::make('Today Sales', $this->money($summary['sales_today']))
                ->icon(Heroicon::OutlinedDocumentCurrencyBangladeshi)
                ->color('success')
                ->extraAttributes(['wire:click' => "mountAction('viewTodaySales')"], merge: true),
            Stat::make('Storefront Pending', $summary['storefront_pending_orders'])
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color($summary['storefront_pending_orders'] > 0 ? 'warning' : 'success')
                ->extraAttributes(['wire:click' => "mountAction('viewStorefrontPending')"], merge: true),
            Stat::make('Today Purchases', $this->money($summary['purchases_today']))
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('warning')
                ->extraAttributes(['wire:click' => "mountAction('viewTodayPurchases')"], merge: true),
            Stat::make('Customer Payments', $this->money($summary['customer_payments_today']))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->extraAttributes(['wire:click' => "mountAction('viewCustomerPayments')"], merge: true),
            Stat::make('Supplier Payments', $this->money($summary['supplier_payments_today']))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('danger')
                ->extraAttributes(['wire:click' => "mountAction('viewSupplierPayments')"], merge: true),
            Stat::make('Today Expenses', $this->money($summary['expenses_today']))
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->color('danger')
                ->extraAttributes(['wire:click' => "mountAction('viewTodayExpenses')"], merge: true),
            Stat::make('Customer Due', $this->money($summary['customer_due']))
                ->icon(Heroicon::OutlinedUserGroup)
                ->color('warning')
                ->extraAttributes(['wire:click' => "mountAction('viewCustomerDue')"], merge: true),
            Stat::make('Supplier Payable', $this->money($summary['supplier_due']))
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->color('warning')
                ->extraAttributes(['wire:click' => "mountAction('viewSupplierPayable')"], merge: true),
            Stat::make('Account Balance', $this->money($summary['account_balance']))
                ->icon(Heroicon::OutlinedWallet)
                ->color('success')
                ->url(AccountResource::getUrl('index')),
            Stat::make('Low Stock Items', $summary['low_stock_count'])
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color($summary['low_stock_count'] > 0 ? 'danger' : 'success')
                ->extraAttributes(['wire:click' => "mountAction('viewLowStock')"], merge: true),
            Stat::make('Coming Soon Products', $summary['coming_soon_count'])
                ->icon(Heroicon::OutlinedClock)
                ->color($summary['coming_soon_count'] > 0 ? 'warning' : 'success')
                ->extraAttributes(['wire:click' => "mountAction('viewComingSoon')"], merge: true),
        ];

        foreach ($stats as $stat) {
            $stat->extraAttributes(['class' => 'zz-business-overview-stat'], merge: true);
        }

        return $stats;
    }

    public function viewTodaySalesAction(): Action
    {
        [$from, $to] = app(ReportService::class)->dateRange();
        $rows = app(ReportService::class)->sales($from, $to);

        return $this->drilldownAction(
            name: 'viewTodaySales',
            label: "Today's Sales",
            rows: $rows->take($this->drilldownLimit),
            total: $rows->count(),
            columns: [
                'Invoice' => fn (Order $order): string => $order->order_number ?? '—',
                'Customer' => fn (Order $order): string => $order->customer?->name ?? '—',
                'Status' => fn (Order $order): string => Order::STATUSES[$order->status] ?? $order->status,
                'Amount' => fn (Order $order): string => $this->money($order->total_amount),
            ],
            emptyMessage: 'No sales recorded today yet.',
        );
    }

    public function viewStorefrontPendingAction(): Action
    {
        $rows = app(ReportService::class)->storefrontPendingOrders($this->drilldownLimit);

        return $this->drilldownAction(
            name: 'viewStorefrontPending',
            label: 'Storefront Pending Orders',
            rows: $rows,
            total: $rows->count(),
            columns: [
                'Invoice' => fn (Order $order): string => $order->order_number ?? '—',
                'Customer' => fn (Order $order): string => $order->customer?->name ?? '—',
                'Source' => fn (Order $order): string => Order::SOURCES[$order->source] ?? $order->source,
                'Amount' => fn (Order $order): string => $this->money($order->total_amount),
            ],
            emptyMessage: 'No pending storefront/offer orders.',
            seeAllUrl: OrderResource::getUrl('index', [
                'filters' => ['status' => ['value' => Order::STATUS_DRAFT]],
            ]),
            seeAllLabel: 'Open all pending orders',
        );
    }

    public function viewTodayPurchasesAction(): Action
    {
        [$from, $to] = app(ReportService::class)->dateRange();
        $rows = app(ReportService::class)->purchases($from, $to);

        return $this->drilldownAction(
            name: 'viewTodayPurchases',
            label: "Today's Purchases",
            rows: $rows->take($this->drilldownLimit),
            total: $rows->count(),
            columns: [
                'Purchase #' => fn (Purchase $purchase): string => $purchase->purchase_number ?? '—',
                'Supplier' => fn (Purchase $purchase): string => $purchase->supplier?->name ?? '—',
                'Status' => fn (Purchase $purchase): string => Purchase::STATUSES[$purchase->status] ?? $purchase->status,
                'Amount' => fn (Purchase $purchase): string => $this->money($purchase->total_amount),
            ],
            emptyMessage: 'No purchases recorded today yet.',
            seeAllUrl: PurchaseResource::getUrl('index'),
            seeAllLabel: 'Open purchases',
        );
    }

    public function viewCustomerPaymentsAction(): Action
    {
        [$from, $to] = app(ReportService::class)->dateRange();

        return $this->ledgerDrilldownAction(
            name: 'viewCustomerPayments',
            label: "Today's Customer Payments",
            type: 'customer_payment',
            from: $from,
            to: $to,
            emptyMessage: 'No customer payments recorded today yet.',
        );
    }

    public function viewSupplierPaymentsAction(): Action
    {
        [$from, $to] = app(ReportService::class)->dateRange();

        return $this->ledgerDrilldownAction(
            name: 'viewSupplierPayments',
            label: "Today's Supplier Payments",
            type: 'supplier_payment',
            from: $from,
            to: $to,
            emptyMessage: 'No supplier payments recorded today yet.',
        );
    }

    public function viewTodayExpensesAction(): Action
    {
        [$from, $to] = app(ReportService::class)->dateRange();
        $rows = app(ReportService::class)->expenses($from, $to);

        return $this->drilldownAction(
            name: 'viewTodayExpenses',
            label: "Today's Expenses",
            rows: $rows->take($this->drilldownLimit),
            total: $rows->count(),
            columns: [
                'Category' => fn ($expense): string => $expense->category?->name ?? '—',
                'Account' => fn ($expense): string => $expense->account?->name ?? '—',
                'Note' => fn ($expense): string => $expense->note ?? '—',
                'Amount' => fn ($expense): string => $this->money($expense->amount),
            ],
            emptyMessage: 'No expenses recorded today yet.',
            seeAllUrl: ExpenseResource::getUrl('index'),
            seeAllLabel: 'Open expenses',
        );
    }

    public function viewCustomerDueAction(): Action
    {
        $rows = app(CustomerDueAlertService::class)->customers($this->drilldownLimit);
        $total = app(CustomerDueAlertService::class)->count();

        return $this->drilldownAction(
            name: 'viewCustomerDue',
            label: 'Customers With Due',
            rows: $rows,
            total: $total,
            columns: [
                'Customer' => fn ($customer): string => $customer->name ?? '—',
                'Phone' => fn ($customer): string => $customer->phone ?? '—',
                'Due' => fn ($customer): string => $this->money($customer->current_balance),
            ],
            emptyMessage: 'No customer has an outstanding due.',
            seeAllUrl: CustomerResource::getUrl('index', [
                'filters' => ['has_due' => ['isActive' => true]],
            ]),
            seeAllLabel: 'Open customers with due',
        );
    }

    public function viewSupplierPayableAction(): Action
    {
        $rows = app(ReportService::class)->supplierDues();

        return $this->drilldownAction(
            name: 'viewSupplierPayable',
            label: 'Suppliers Payable',
            rows: $rows->take($this->drilldownLimit),
            total: $rows->count(),
            columns: [
                'Supplier' => fn ($supplier): string => $supplier->name ?? '—',
                'Phone' => fn ($supplier): string => $supplier->phone ?? '—',
                'Payable' => fn ($supplier): string => $this->money($supplier->current_balance),
            ],
            emptyMessage: 'No supplier has an outstanding payable.',
            seeAllUrl: SupplierResource::getUrl('index'),
            seeAllLabel: 'Open suppliers',
        );
    }

    public function viewLowStockAction(): Action
    {
        $rows = app(LowStockAlertService::class)->products($this->drilldownLimit);
        $total = app(LowStockAlertService::class)->count();

        return $this->drilldownAction(
            name: 'viewLowStock',
            label: 'Low Stock Items',
            rows: $rows,
            total: $total,
            columns: [
                'Product' => fn (Product $product): string => $product->name,
                'SKU' => fn (Product $product): string => $product->sku ?? '—',
                'Stock' => fn (Product $product): string => (string) $product->stock,
                'Reorder Level' => fn (Product $product): string => (string) $product->reorder_level,
            ],
            emptyMessage: 'No product is at or below its reorder level.',
            seeAllUrl: ProductResource::getUrl('index', [
                'filters' => ['low_stock' => ['isActive' => true]],
            ]),
            seeAllLabel: 'Open low stock products',
        );
    }

    public function viewComingSoonAction(): Action
    {
        $rows = app(ReportService::class)->comingSoonProducts($this->drilldownLimit);

        return $this->drilldownAction(
            name: 'viewComingSoon',
            label: 'Coming Soon Products',
            rows: $rows,
            total: $rows->count(),
            columns: [
                'Product' => fn (Product $product): string => $product->name,
                'SKU' => fn (Product $product): string => $product->sku ?? '—',
            ],
            emptyMessage: 'No product is marked as coming soon.',
            seeAllUrl: ProductResource::getUrl('index', [
                'filters' => ['status' => ['value' => Product::STATUS_COMING_SOON]],
            ]),
            seeAllLabel: 'Open coming soon products',
        );
    }

    protected function ledgerDrilldownAction(string $name, string $label, string $type, CarbonInterface $from, CarbonInterface $to, string $emptyMessage): Action
    {
        $rows = $type === 'customer_payment'
            ? app(ReportService::class)->customerPayments($from, $to, $this->drilldownLimit)
            : app(ReportService::class)->supplierPayments($from, $to, $this->drilldownLimit);

        return $this->drilldownAction(
            name: $name,
            label: $label,
            rows: $rows,
            total: $rows->count(),
            columns: [
                'Account' => fn ($ledger): string => $ledger->account?->name ?? '—',
                'Note' => fn ($ledger): string => $ledger->note ?? '—',
                'Amount' => fn ($ledger): string => $this->money($ledger->amount),
            ],
            emptyMessage: $emptyMessage,
            seeAllUrl: TransactionLedgerResource::getUrl('index', [
                'filters' => ['type' => ['value' => $type]],
            ]),
            seeAllLabel: 'Open transaction ledger',
        );
    }

    /**
     * @param  array<string, \Closure>  $columns
     */
    protected function drilldownAction(
        string $name,
        string $label,
        Collection $rows,
        int $total,
        array $columns,
        string $emptyMessage,
        ?string $seeAllUrl = null,
        ?string $seeAllLabel = null,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->modalHeading($label)
            ->modalContent(fn () => view('filament.widgets.partials.dashboard-drilldown-list', [
                'rows' => $rows,
                'total' => $total,
                'columns' => $columns,
                'emptyMessage' => $emptyMessage,
                'seeAllUrl' => $seeAllUrl,
                'seeAllLabel' => $seeAllLabel,
            ]))
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    protected function money(float|int|string $amount): string
    {
        return MoneyFormatter::currency($amount);
    }
}
