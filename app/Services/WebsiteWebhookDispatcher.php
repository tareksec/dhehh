<?php

namespace App\Services;

use App\Jobs\DispatchWebsiteWebhookJob;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Support\WebsiteProductPayload;
use Illuminate\Support\Facades\Cache;

/**
 * Queues an outbound webhook to an external website whenever something it
 * needs to know about changes on the ERP side — a product created, edited
 * (any WebsiteProductPayload field, stock included) or deleted, an order's
 * status, or the status of a quote/inquiry it submitted as a lead.
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
    public const CREATED_DELAY_SECONDS = 5;

    /** Every product payload field, the `changed_fields` of a product.created. */
    public const ALL_PRODUCT_FIELDS = [
        'name', 'slug', 'brand', 'category', 'description', 'specifications',
        'price', 'sale_price', 'stock', 'is_active', 'status', 'image', 'images',
    ];

    /**
     * The full product (WebsiteProductPayload) plus `changed_fields`: the
     * payload fields this change touched, so a website can apply only those
     * and keep its own copy of everything else (a stock movement must not
     * overwrite a description the website edited).
     *
     * Skipped while a product.created for the same product is still pending:
     * that delivery is built at send time and already carries this change, and
     * the website cannot update a product it has not created yet.
     *
     * @param  list<string>  $changedFields
     */
    public function dispatchProductUpdated(Product $product, array $changedFields = ['price', 'sale_price', 'stock']): void
    {
        if (Cache::has($this->createdKey($product))) {
            return;
        }

        $this->dispatch($product->company_id, 'product.updated', [
            ...WebsiteProductPayload::for($product),
            'changed_fields' => array_values($changedFields),
        ]);
    }

    /**
     * Sent a few seconds after the insert, so the product form's follow-up
     * saves (opening stock) are in before the payload is built.
     */
    public function dispatchProductCreated(Product $product): void
    {
        // The sync queue sends at once (no delay), so later changes must go out on their own.
        if (config('queue.default') !== 'sync') {
            Cache::put($this->createdKey($product), true, now()->addSeconds(self::CREATED_DELAY_SECONDS + 10));
        }

        $this->dispatch($product->company_id, 'product.created', [
            ...WebsiteProductPayload::for($product),
            'changed_fields' => self::ALL_PRODUCT_FIELDS,
        ], delaySeconds: self::CREATED_DELAY_SECONDS, productId: $product->getKey());
    }

    public function dispatchProductDeleted(Product $product): void
    {
        Cache::forget($this->createdKey($product));

        $this->dispatch($product->company_id, 'product.deleted', [
            'sku' => $product->sku,
            'name' => $product->name,
            'deleted_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Payload field names for the product columns in $columns (category_id is
     * sent as `category`, image + gallery_images as `image` + `images`).
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function changedProductFields(array $columns): array
    {
        return collect($columns)
            ->flatMap(fn (string $column): array => match ($column) {
                'category_id' => ['category'],
                'image', 'gallery_images' => ['image', 'images'],
                default => [$column],
            })
            ->unique()
            ->values()
            ->all();
    }

    protected function createdKey(Product $product): string
    {
        return "website-webhook:product-created:{$product->getKey()}";
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
    public function dispatch(?int $companyId, string $event, array $data, int $delaySeconds = 0, ?int $productId = null): void
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

            $pending = DispatchWebsiteWebhookJob::dispatch($url, $secret, $event, $data, $companyId, $productId);

            if ($delaySeconds > 0) {
                $pending->delay(now()->addSeconds($delaySeconds));
            }
        }
    }
}
