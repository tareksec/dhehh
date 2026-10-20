<?php

namespace App\Observers;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\BusinessNotificationService;

/**
 * Fires the two order-related business alerts the owner asked for: a new
 * order arriving, and any status/delivery_status change (every transition,
 * not just a curated subset). Registered as a second, independent observer
 * on Order alongside AuditObserver -- kept separate from Order::booted()'s
 * own lifecycle hooks so that already-dense method doesn't grow further.
 */
class OrderNotificationObserver
{
    // The link goes to the admin panel's order page. The panel is named
    // explicitly because an order can be created or changed while another
    // panel (the reseller portal) is the current one, and that panel has no
    // order resource route.
    public function __construct(
        protected BusinessNotificationService $notifications,
    ) {}

    public function created(Order $order): void
    {
        // An import of past orders must not alert the owner once per order, and an
        // open order brought in from Nuport is not a new order.
        if ($order->isHistoricalImport() || $order->source === Order::SOURCE_NUPORT_OPEN || ! $order->company) {
            return;
        }

        $this->notifications->notifyCompany(
            $order->company,
            'order.created',
            'notifications.orders',
            'New order received',
            $this->orderSummary($order),
            actionUrl: OrderResource::getUrl('view', ['record' => $order], panel: 'admin'),
            actionLabel: 'View order',
        );
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged(['status', 'delivery_status'])) {
            return;
        }

        if ($order->isHistoricalImport() || ! $order->company) {
            return;
        }

        $this->notifications->notifyCompany(
            $order->company,
            'order.status_changed',
            'notifications.order_status',
            'Order status updated',
            $this->orderSummary($order).' is now '.str_replace('_', ' ', (string) $order->status)
                .($order->delivery_status ? ' ('.str_replace('_', ' ', (string) $order->delivery_status).')' : ''),
            actionUrl: OrderResource::getUrl('view', ['record' => $order], panel: 'admin'),
            actionLabel: 'View order',
        );
    }

    protected function orderSummary(Order $order): string
    {
        $number = filled($order->order_number) ? "#{$order->order_number}" : "#{$order->getKey()}";
        $customer = filled($order->customer_name) ? " ({$order->customer_name})" : '';

        return "Order {$number}{$customer}";
    }
}
