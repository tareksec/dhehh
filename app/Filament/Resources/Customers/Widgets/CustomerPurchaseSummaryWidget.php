<?php

namespace App\Filament\Resources\Customers\Widgets;

use App\Filament\Concerns\GuardsWidgetRendering;
use App\Models\Customer;
use App\Services\CustomerOrderSummaryService;
use App\Support\MoneyFormatter;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The four cards at the top of a customer's page (owner request 2026-11-01): how many orders they placed, how
 * much of our goods they have taken, how much they have paid, and what they still owe. The numbers come from
 * CustomerOrderSummaryService, so they follow the same orders the customer's due follows.
 */
class CustomerPurchaseSummaryWidget extends StatsOverviewWidget
{
    use GuardsWidgetRendering;

    // The cards are the point of the page, so they render with it instead of loading afterwards.
    protected static bool $isLazy = false;

    /** Filament gives a widget on a resource page the page's record. */
    public ?Model $record = null;

    /**
     * @return array<string, int>
     */
    protected function getColumns(): array
    {
        return [
            'default' => 2,
            'lg' => 4,
        ];
    }

    protected function getStats(): array
    {
        if (! $this->record instanceof Customer) {
            return [];
        }

        $summary = app(CustomerOrderSummaryService::class)->for($this->record);

        $orderDates = $summary['first_order_date'] !== null
            ? __('First order :first, last order :last', [
                'first' => $this->date($summary['first_order_date']),
                'last' => $this->date($summary['last_order_date']),
            ])
            : __('No order yet');

        return [
            Stat::make(__('Orders'), $summary['orders'])
                ->description($orderDates)
                ->icon(Heroicon::OutlinedShoppingBag)
                ->color('primary'),
            Stat::make(__('Total purchased'), MoneyFormatter::currency($summary['purchased']))
                ->description(__('Value of the goods taken, cancelled and returned orders left out'))
                ->icon(Heroicon::OutlinedShoppingCart)
                ->color('info'),
            Stat::make(__('Total paid'), MoneyFormatter::currency($summary['paid']))
                ->description(__('On orders :orders, later payments :later', [
                    'orders' => MoneyFormatter::currency($summary['paid_on_orders']),
                    'later' => MoneyFormatter::currency($summary['paid_later']),
                ]))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success'),
            Stat::make(__('Current due'), MoneyFormatter::currency($summary['due']))
                ->description($summary['opening_balance'] != 0
                    ? __('Includes an opening balance of :amount', ['amount' => MoneyFormatter::currency($summary['opening_balance'])])
                    : __('Still to be paid'))
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->color($summary['due'] > 0 ? 'danger' : 'success'),
        ];
    }

    private function date(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('d M Y') : '—';
    }
}
