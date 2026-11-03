<?php

namespace App\Services;

use App\Jobs\DispatchWebsiteWebhookJob;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorefrontSetting;
use Illuminate\Support\Facades\Cache;

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

    /**
     * A product went to Trash (plan T5b). The website already revalidates on `product.updated`,
     * so it is the same event with `removed: true`; the page then no longer finds the product.
     * Restoring sends the ordinary `product.updated`.
     */
    public function dispatchProductRemoved(Product $product): void
    {
        $this->dispatch($product->company_id, 'product.updated', [
            'sku' => $product->sku,
            'name' => $product->name,
            'removed' => true,
            'updated_at' => now()->toIso8601String(),
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
     * Storefront content changed (settings, slides, pages, categories,
     * offers). One delivery per company per few seconds, sent a moment after
     * the first change, so a form that saves several records at once — or
     * several quick edits — refreshes the website once and with the final data.
     */
    public function dispatchStorefrontUpdated(?int $companyId): void
    {
        if (! $companyId || ! Cache::add("website-webhook:storefront:{$companyId}", true, now()->addSeconds(5))) {
            return;
        }

        $this->dispatch($companyId, 'storefront.updated', ['updated_at' => now()->toIso8601String()], delaySeconds: 5);
    }

    /**
     * Also used for the Integrations page's "Send test webhook" action, with
     * event 'ping' and an empty payload.
     */
    public function dispatch(?int $companyId, string $event, array $data, int $delaySeconds = 0): void
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

            $pending = DispatchWebsiteWebhookJob::dispatch($url, $secret, $event, $data);

            if ($delaySeconds > 0) {
                $pending->delay(now()->addSeconds($delaySeconds));
            }
        }
    }
}
