<?php

namespace App\Services;

use App\Jobs\DispatchWebsiteWebhookJob;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorefrontSetting;

/**
 * Queues an outbound webhook to an external website whenever something it
 * needs to know about changes on the ERP side — a product's price/stock, an
 * order's status, or the status of a quote/inquiry it submitted as a lead.
 * Configured per company on StorefrontSetting's `website_api_credentials`
 * (webhook_url, webhook_secret, is_enabled), the
 * same "one encrypted JSON column per integration" convention as
 * `woocommerce_credentials`. A silent no-op when nothing is configured or
 * enabled — this is a best-effort notification, never something that should
 * fail the ERP-side change itself (see DispatchWebsiteWebhookJob for the
 * actual HTTP call).
 */
class WebsiteWebhookDispatcher
{
    public function dispatchProductUpdated(Product $product): void
    {
        $this->dispatch($product->company_id, 'product.updated', [
            'sku' => $product->sku,
            'name' => $product->name,
            'price' => (float) $product->price,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'stock' => (int) $product->stock,
            'updated_at' => $product->updated_at?->toIso8601String(),
        ]);
    }

    public function dispatchOrderStatusUpdated(Order $order): void
    {
        $this->dispatch($order->company_id, 'order.status_updated', [
            'order_number' => $order->order_number,
            'external_reference' => $order->external_reference,
            'status' => $order->status,
            'delivery_status' => $order->delivery_status,
            'workflow_stage' => $order->workflowStage(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ]);
    }

    public function dispatchLeadStatusUpdated(Lead $lead): void
    {
        $this->dispatch($lead->company_id, 'lead.status_updated', [
            'id' => $lead->getKey(),
            'external_reference' => $lead->external_reference,
            'status' => $lead->status,
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Also used for the Integrations page's "Send test webhook" action, with
     * event 'ping' and an empty payload.
     */
    public function dispatch(?int $companyId, string $event, array $data): void
    {
        if (! $companyId) {
            return;
        }

        // Each site (Blade, Next.js) can have its own webhook; two sites sharing
        // one URL and secret are notified once.
        $sent = [];

        foreach (StorefrontSetting::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->get() as $setting) {
            $credentials = $setting->website_api_credentials ?? [];

            $url = (string) ($credentials['webhook_url'] ?? '');
            $secret = (string) ($credentials['webhook_secret'] ?? '');
            $enabled = (bool) ($credentials['is_enabled'] ?? false);

            if (! $enabled || $url === '' || $secret === '' || isset($sent[$url.'|'.$secret])) {
                continue;
            }

            $sent[$url.'|'.$secret] = true;

            DispatchWebsiteWebhookJob::dispatch($url, $secret, $event, $data);
        }
    }
}
