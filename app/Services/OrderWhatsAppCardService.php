<?php

namespace App\Services;

use App\Jobs\SendOrderWhatsAppCardJob;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Services\Crm\ContactSuppressionService;
use App\Services\Meta\MetaGraphService;
use App\Support\MoneyFormatter;
use App\Support\PhoneLinks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the customer the order summary card on WhatsApp by itself (owner
 * request 2026-10-22): once when the order is created and again every time
 * it reaches a new stage (the same stages the status SMS covers).
 *
 * Only through the official WhatsApp Cloud API (a Cloud API or Coexistence
 * channel) and an approved template with an image header, which is the only
 * way WhatsApp allows a business to message first. A QR-linked WhatsApp Web
 * channel never sends automatically (docs/whatsapp-web-rules.md, rule 4).
 * The template's body variables are, in this order: customer name, order
 * number, status, total, due.
 *
 * Not sent for: an import of past orders, a reseller's store order, a
 * customer without a phone or on the opt-out list, or a server that cannot
 * draw the card with correct Bangla (OrderSummaryCardImageService). A number
 * without WhatsApp simply fails at Meta; nothing else is affected, and the
 * SMS is separate.
 */
class OrderWhatsAppCardService
{
    public function __construct(
        protected OrderWhatsAppSettings $settings,
        protected OrderSummaryCardImageService $images,
        protected OrderStatusSmsService $stages,
        protected ContactSuppressionService $suppression,
        protected MetaGraphService $meta,
    ) {}

    /** Queues the card for a new order (after a short wait, so its items are saved). */
    public function dispatchForOrderPlaced(Order $order): void
    {
        if (! $this->wanted($order)) {
            return;
        }

        SendOrderWhatsAppCardJob::dispatch($order->getKey(), 'placed')
            ->delay(now()->addSeconds(OrderStatusSmsService::PLACED_DELAY_SECONDS))
            ->afterCommit();
    }

    /** Queues the card when the order reaches a new stage. */
    public function dispatchForTransition(OrderStatusTransition $transition): void
    {
        $order = Order::withoutGlobalScopes()->find($transition->order_id);
        $stage = $this->stages->stageFor($transition);

        if ($order === null || $stage === null || ! $this->wanted($order)) {
            return;
        }

        SendOrderWhatsAppCardJob::dispatch($order->getKey(), $stage, $transition->getKey())->afterCommit();
    }

    /**
     * Draws the card and sends it. $event is "placed" or the stage name. Returns whether Meta accepted it.
     */
    public function send(int $orderId, string $event, ?int $transitionId = null): bool
    {
        $order = Order::withoutGlobalScopes()->with(['customer', 'company'])->find($orderId);

        if ($order === null || ! $this->wanted($order)) {
            return false;
        }

        $companyId = (int) $order->company_id;
        $settings = $this->settings->get($companyId);
        $channel = $this->settings->channel($companyId);
        $phone = PhoneLinks::internationalDigits($order->customer?->phone);

        if ($channel === null || $settings['template'] === '' || $phone === null || $this->suppression->suppressed($companyId, $phone)) {
            return false;
        }

        // A retried job must never send the same news twice, and the same stage twice in a row is not news.
        $key = $transitionId !== null ? 'order-wa-card:t'.$transitionId : "order-wa-card:{$orderId}:{$event}";

        if (! Cache::add($key, true, now()->addDays(2)) || ! Cache::add("order-wa-card:{$orderId}:{$event}:recent", true, now()->addMinutes(5))) {
            return false;
        }

        $token = (string) $channel->access_token;
        $phoneNumberId = (string) $channel->external_id;

        if ($token === '' || $phoneNumberId === '') {
            $channel->recordDiagnosticError('The WhatsApp channel is missing its access token or Phone Number ID, so the order card was not sent.', 'outbound');

            return false;
        }

        $png = $this->images->render($order->loadMissing(['items.product', 'items.productVariant', 'latestCourierBooking.provider', 'reseller']));

        if ($png === null) {
            $channel->recordDiagnosticError('The order card was not sent: '.$this->images->problem(), 'outbound');
            Log::warning('Order WhatsApp card not sent: this server cannot draw the card.', ['order_id' => $orderId, 'problem' => $this->images->problem()]);

            return false;
        }

        $currency = $order->company?->currency ?: 'BDT';

        try {
            $mediaId = $this->meta->uploadWhatsAppMedia($phoneNumberId, $token, $png, 'image/png', 'order-'.$order->order_number.'.png');
            $this->meta->sendWhatsAppTemplate(
                $phoneNumberId,
                $token,
                $phone,
                $settings['template'],
                $settings['language'],
                [
                    (string) ($order->customer?->name ?? $order->customer_name),
                    (string) $order->order_number,
                    $this->statusLabel($order, $event),
                    MoneyFormatter::currency((float) $order->total_amount, $currency),
                    MoneyFormatter::currency((float) $order->due_amount, $currency),
                ],
                $mediaId,
            );
        } catch (Throwable $exception) {
            Log::warning('Order WhatsApp card send failed', ['order_id' => $orderId, 'error' => $exception->getMessage()]);
            $channel->recordDiagnosticError($exception->getMessage(), 'outbound');

            return false;
        }

        try {
            $channel->markOutboundSent();
        } catch (Throwable) {
            // Meta already accepted the message; a diagnostics failure must not look like a failed send.
        }

        return true;
    }

    /** Whether this order may be messaged automatically at all (before any customer or channel checks). */
    protected function wanted(Order $order): bool
    {
        if ($order->isHistoricalImport() || filled($order->reseller_customer_id) || blank($order->company_id)) {
            return false;
        }

        $settings = $this->settings->get((int) $order->company_id);

        return $settings['enabled'] && $settings['template'] !== '' && $this->settings->channel((int) $order->company_id) !== null;
    }

    protected function statusLabel(Order $order, string $event): string
    {
        if ($event !== 'placed' && isset(Order::WORKFLOW_STAGES[$event])) {
            return __(Order::WORKFLOW_STAGES[$event]);
        }

        return __(Order::STATUSES[$order->status] ?? (string) str((string) $order->status)->headline());
    }
}
