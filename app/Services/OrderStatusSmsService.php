<?php

namespace App\Services;

use App\Jobs\SendOrderPlacedSmsJob;
use App\Jobs\SendOrderStatusSmsJob;
use App\Models\CourierBooking;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Services\Crm\ContactSuppressionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Texts the customer when their order reaches a new stage, with the wording
 * the owner wrote for that stage on the SMS Templates page (owner request
 * 2026-10-17). A stage with no wording sends nothing, so the SMS only starts
 * once the owner has written one.
 *
 * Not sent for: an import of past orders, a reseller's store order (the
 * reseller's own customer, who knows the reseller, not this company), a
 * customer without a phone, or a number on the opt-out list.
 */
class OrderStatusSmsService
{
    /** How long a new order waits before its "placed" message, so its items are saved by then. */
    public const PLACED_DELAY_SECONDS = 45;

    public function __construct(
        protected SmsTemplateService $templates,
        protected StorefrontNotificationService $notifications,
        protected ContactSuppressionService $suppression,
        protected StorefrontMetaTrackingService $tracking,
    ) {}

    /**
     * Queues the "order placed" SMS for a new order, but only when the company has written that wording.
     * It waits a little first: the items and the first payment are saved just after the order itself, and the
     * message lists them.
     */
    public function dispatchForOrderPlaced(Order $order): void
    {
        if ($order->isHistoricalImport()
            || filled($order->reseller_customer_id)
            || $this->templates->body((int) $order->company_id, SmsTemplateService::ORDER_PLACED) === null) {
            return;
        }

        SendOrderPlacedSmsJob::dispatch($order->getKey())->delay(now()->addSeconds(self::PLACED_DELAY_SECONDS))->afterCommit();
    }

    /** Queues the SMS for a status change, but only when that stage has wording. */
    public function dispatchForTransition(OrderStatusTransition $transition): void
    {
        $stage = $this->stageFor($transition);

        if ($stage === null || $this->templates->body((int) $transition->company_id, SmsTemplateService::orderKey($stage)) === null) {
            return;
        }

        SendOrderStatusSmsJob::dispatch($transition->getKey())->afterCommit();
    }

    /**
     * The stage the customer is told about, or null for a change they are not
     * texted about: not a stage with wording, or the order stayed in the same
     * stage (a parcel going from "picked up" to "in transit" is still Shipping).
     */
    public function stageFor(OrderStatusTransition $transition): ?string
    {
        $stage = $this->tracking->statusEventStage($transition);

        if (! in_array($stage, SmsTemplateService::ORDER_STAGES, true)
            || $stage === $this->stageOf($transition->from_status, $transition->from_delivery_status)) {
            return null;
        }

        return $stage;
    }

    /** The stage an order with this status and courier state is in (mirrors Order::workflowStage()). */
    protected function stageOf(?string $status, ?string $deliveryStatus): ?string
    {
        return match (true) {
            in_array($status, [Order::STATUS_CANCELLED, Order::STATUS_RETURNED, Order::STATUS_REFUNDED], true) => $status,
            in_array($deliveryStatus, Order::SHIPPING_DELIVERY_STATUSES, true) => Order::STAGE_SHIPPING,
            $status === Order::STATUS_COMPLETED && $deliveryStatus === CourierBooking::STATUS_DELIVERED => Order::STAGE_DELIVERED,
            default => $status,
        };
    }

    public function send(OrderStatusTransition $transition): bool
    {
        $stage = $this->stageFor($transition);
        $order = Order::withoutGlobalScopes()->with(['customer', 'company', 'storefrontSetting', 'latestCourierBooking'])->find($transition->order_id);

        if ($stage === null || $order === null || $order->isHistoricalImport() || filled($order->reseller_customer_id)) {
            return false;
        }

        $companyId = (int) $order->company_id;
        $phone = trim((string) $order->customer?->phone);

        if ($phone === '' || $this->suppression->suppressed($companyId, $phone)) {
            return false;
        }

        $values = $this->values($order, __(Order::WORKFLOW_STAGES[$stage]));
        $message = $this->templates->render($companyId, SmsTemplateService::orderKey($stage), $values);
        $setting = $this->templates->gatewayFor($companyId, $order->siteSetting());

        // A retried job must never text the same change twice, and a courier sending the same news twice
        // in a row must not text the customer twice.
        if (blank($message) || $setting === null
            || ! Cache::add('order-status-sms:'.$transition->getKey(), true, now()->addDays(2))
            || ! Cache::add("order-status-sms:{$order->getKey()}:{$stage}", true, now()->addMinutes(5))) {
            return false;
        }

        return $this->notifications->sendSms($setting, $phone, $message);
    }

    /** The "order placed" SMS (same exclusions as a status SMS). */
    public function sendPlaced(int $orderId): bool
    {
        $order = Order::withoutGlobalScopes()->with(['customer', 'company', 'storefrontSetting', 'latestCourierBooking', 'items.product'])->find($orderId);

        if ($order === null || $order->isHistoricalImport() || filled($order->reseller_customer_id)) {
            return false;
        }

        $companyId = (int) $order->company_id;
        $phone = trim((string) $order->customer?->phone);

        if ($phone === '' || $this->suppression->suppressed($companyId, $phone)) {
            return false;
        }

        $status = Order::STATUSES[$order->status] ?? (string) str((string) $order->status)->headline();
        $message = $this->templates->render($companyId, SmsTemplateService::ORDER_PLACED, $this->values($order, __($status)));
        $setting = $this->templates->gatewayFor($companyId, $order->siteSetting());

        if (blank($message) || $setting === null || ! Cache::add('order-placed-sms:'.$order->getKey(), true, now()->addDays(2))) {
            return false;
        }

        return $this->notifications->sendSms($setting, $phone, $message);
    }

    /**
     * What the owner's wording can use. `items` is "Product x2, Other x1", short enough to leave room in one SMS.
     *
     * @return array<string, string>
     */
    protected function values(Order $order, string $status): array
    {
        $order->loadMissing(['items.product', 'items.productVariant']);
        $items = $order->items
            ->map(fn ($item): string => trim(($item->product?->name ?? __('Item')).' x'.(int) $item->quantity))
            ->implode(', ');

        return [
            'name' => (string) ($order->customer?->name ?? $order->customer_name),
            'order' => (string) ($order->order_number ?: $order->getKey()),
            'status' => $status,
            'items' => Str::limit($items, 100, '...'),
            'total' => $this->money($order->total_amount),
            'due' => $this->money($order->due_amount),
            'tracking' => (string) $order->latestCourierBooking?->tracking_id,
            'company' => (string) $order->company?->name,
        ];
    }

    protected function money(mixed $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }
}
