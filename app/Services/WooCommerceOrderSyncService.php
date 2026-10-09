<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorefrontSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ingests WooCommerce order.created/order.updated/order.deleted webhooks
 * (see WooCommerceWebhookController) into company-scoped ERP Orders.
 *
 * Upserts are keyed on `orders.external_reference` ("woo-{id}"), so a
 * redelivered webhook (WooCommerce guarantees at-least-once, not
 * exactly-once delivery) updates the same order instead of duplicating it.
 *
 * Status mapping is a reasonable default, not a confirmed business rule —
 * flagged in 09_DASHBOARD_WOOCOMMERCE_SECURITY_PLAN.md step 2.5 as needing
 * the owner's confirmation before go-live. Adjust STATUS_MAP if they want
 * different equivalents (e.g. WooCommerce "processing" vs ERP "confirmed").
 */
class WooCommerceOrderSyncService
{
    /** @var array<string, string> WooCommerce order status => Order::STATUS_* */
    public const STATUS_MAP = [
        'pending' => Order::STATUS_DRAFT,
        'on-hold' => Order::STATUS_DRAFT,
        'processing' => Order::STATUS_PROCESSING,
        'completed' => Order::STATUS_COMPLETED,
        'cancelled' => Order::STATUS_CANCELLED,
        'refunded' => Order::STATUS_REFUNDED,
        'failed' => Order::STATUS_CANCELLED,
        'trash' => Order::STATUS_CANCELLED,
    ];

    /**
     * @var array<string, string> Order::STATUS_* => WooCommerce order status
     *
     * The reverse of STATUS_MAP, for pushing an ERP-side status change back
     * to WooCommerce. Also a reasonable default, not a confirmed business
     * rule — ERP has no exact equivalent of WooCommerce's single flat status
     * list (e.g. "confirmed" and "processing" both become WooCommerce
     * "processing"; "returned" becomes "refunded", WooCommerce's closest
     * status). Adjust if the owner wants different equivalents.
     */
    public const REVERSE_STATUS_MAP = [
        Order::STATUS_DRAFT => 'pending',
        Order::STATUS_CONFIRMED => 'processing',
        Order::STATUS_PROCESSING => 'processing',
        Order::STATUS_COMPLETED => 'completed',
        Order::STATUS_CANCELLED => 'cancelled',
        Order::STATUS_RETURNED => 'refunded',
        Order::STATUS_REFUNDED => 'refunded',
    ];

    public function __construct(
        protected CompanyContext $context,
        protected StorefrontCheckoutPolicyService $checkoutPolicies,
    ) {}

    public function handleOrderEvent(Company $company, string $topic, array $payload): void
    {
        // Every BelongsToCompany read/write below relies on this being set —
        // this route is unauthenticated, so SetCurrentCompany has cleared
        // the context for the request (see CompanyScope's docblock).
        $this->context->set($company);

        if (str_contains($topic, 'deleted')) {
            $this->markCancelled($company, $payload);

            return;
        }

        $this->upsertOrder($company, $payload);
    }

    protected function markCancelled(Company $company, array $payload): void
    {
        $wooOrderId = (int) ($payload['id'] ?? 0);

        if ($wooOrderId <= 0) {
            return;
        }

        $order = Order::query()
            ->where('company_id', $company->getKey())
            ->where('external_reference', "woo-{$wooOrderId}")
            ->first();

        if (! $order) {
            return;
        }

        // Without this, cancelling here would immediately queue a push of
        // the identical "cancelled" status straight back to WooCommerce.
        $order->suppressWooCommercePush = true;
        $order->update(['status' => Order::STATUS_CANCELLED]);
    }

    protected function upsertOrder(Company $company, array $payload): Order
    {
        $wooOrderId = (int) ($payload['id'] ?? 0);
        abort_if($wooOrderId <= 0, 422, 'WooCommerce order payload is missing an order id.');

        return DB::transaction(function () use ($company, $payload, $wooOrderId): Order {
            $customer = $this->resolveCustomer($company, $payload);

            $order = Order::query()
                ->where('company_id', $company->getKey())
                ->where('external_reference', "woo-{$wooOrderId}")
                ->first() ?? new Order([
                    'company_id' => $company->getKey(),
                    'source' => Order::SOURCE_WOOCOMMERCE,
                    'external_reference' => "woo-{$wooOrderId}",
                ]);

            $orderDate = $order->exists
                ? $order->order_date
                : $this->orderDate($payload);

            // Order::creating() auto-fills shipping_zone/shipping_fee from
            // the ERP's own zone-keyword calculator whenever shipping_zone
            // is still null on a brand-new order — it has no way to know a
            // WooCommerce order already carries its own real shipping_total,
            // so on a first sync it would silently overwrite the value set
            // below with a guessed ERP fee. Setting shipping_zone up front
            // (never null) bypasses that hook entirely; the zone is still
            // determined for reporting/filtering consistency where the
            // customer's address actually matches a configured keyword.
            if (! $order->exists) {
                $order->shipping_zone = app(ShippingFeeService::class)
                    ->determineZone($customer->address, $company) ?? '';
            }

            // Without this, every WooCommerce → ERP status sync would
            // immediately queue an ERP → WooCommerce push of that same
            // status straight back (see PushWooCommerceOrderStatusJob).
            $order->suppressWooCommercePush = true;

            $order->fill([
                'customer_id' => $customer->getKey(),
                'customer_name' => $customer->name,
                'order_date' => $orderDate,
                'discount' => (float) ($payload['discount_total'] ?? 0),
                'vat' => (float) ($payload['total_tax'] ?? 0),
                'shipping_fee' => (float) ($payload['shipping_total'] ?? 0),
                'status' => $this->mapStatus((string) ($payload['status'] ?? 'pending')),
                'note' => 'Synced from WooCommerce order #'.($payload['number'] ?? $wooOrderId).'.',
            ]);
            $order->save();

            $unmatchedNames = $this->syncItems($order, (array) ($payload['line_items'] ?? []));

            if ($unmatchedNames !== []) {
                $this->reconcileTotalsWithWooCommerce($order, $payload);
            }

            return $order->refresh();
        });
    }

    protected function orderDate(array $payload): string
    {
        $created = $payload['date_created'] ?? null;

        if (blank($created)) {
            return now()->toDateString();
        }

        try {
            return Carbon::parse($created)->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }

    protected function resolveCustomer(Company $company, array $payload): Customer
    {
        $billing = (array) ($payload['billing'] ?? []);
        $phone = trim((string) ($billing['phone'] ?? ''));
        $name = trim(collect([$billing['first_name'] ?? null, $billing['last_name'] ?? null])->filter()->implode(' '));
        $email = trim((string) ($billing['email'] ?? '')) ?: null;
        $address = trim(collect([
            $billing['address_1'] ?? null,
            $billing['address_2'] ?? null,
            $billing['city'] ?? null,
            $billing['state'] ?? null,
        ])->filter()->implode(', '));

        // Find-or-create by phone, same convention as the storefront checkout
        // (CheckoutController::createOrder()) — phone is the natural key
        // customers are actually deduplicated on across this app.
        $customer = $phone !== ''
            ? Customer::query()->whereIn('phone', $this->checkoutPolicies->phoneVariants($phone))->first()
            : null;

        $customer ??= new Customer(['phone' => $phone !== '' ? $phone : null]);

        $customer->fill([
            'name' => $name !== '' ? $name : ($customer->name ?: 'WooCommerce Customer'),
            'email' => $email ?? $customer->email,
            'address' => $address !== '' ? $address : $customer->address,
            'customer_type' => $customer->customer_type ?: 'regular',
            'customer_source' => $customer->customer_source ?: 'woocommerce',
            'opening_balance' => $customer->opening_balance ?? 0,
            'is_active' => true,
        ]);
        $customer->save();

        return $customer;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array<int, string> names/SKUs of any line items that couldn't be matched
     */
    protected function syncItems(Order $order, array $lineItems): array
    {
        // Every OrderItem on a WooCommerce-sourced order came from this sync,
        // so a full replace-on-each-delivery is simple and safe — no need to
        // track WooCommerce's own line-item ids to diff against. OrderItem's
        // own saved/deleted hooks keep totals, stock movements, and the
        // customer's balance in sync automatically (see OrderWorkflowService).
        $order->items()->delete();

        $unmatched = [];
        $matchedProductNames = [];

        foreach ($lineItems as $lineItem) {
            $sku = trim((string) ($lineItem['sku'] ?? ''));
            $name = trim((string) ($lineItem['name'] ?? ''));

            $product = $sku !== ''
                ? Product::query()->where('company_id', $order->company_id)->where('sku', $sku)->first()
                : null;

            // Falls back to matching by product name (via the same slug
            // normalization WooCommerceImportService::importProduct() uses
            // when a product doesn't have a WooCommerce-supplied slug
            // either) — many real WooCommerce catalogs don't set a SKU on
            // every product, so requiring an exact SKU match alone silently
            // dropped every line item on those orders, leaving the order
            // with no items and a zero subtotal.
            if (! $product && $name !== '') {
                $product = Product::query()
                    ->where('company_id', $order->company_id)
                    ->where('slug', Str::slug($name))
                    ->first();
            }

            if (! $product) {
                Log::warning('WooCommerce order line item skipped: no matching product SKU or name.', [
                    'company_id' => $order->company_id,
                    'order_id' => $order->getKey(),
                    'sku' => $sku,
                    'name' => $name,
                ]);

                $unmatched[] = $name !== '' ? $name : ($sku !== '' ? "SKU {$sku}" : 'an unnamed item');

                continue;
            }

            $quantity = max(1, (int) ($lineItem['quantity'] ?? 1));
            $lineTotal = (float) ($lineItem['total'] ?? 0);

            $order->items()->create([
                'product_id' => $product->getKey(),
                'quantity' => $quantity,
                'unit_price' => $lineTotal > 0 ? round($lineTotal / $quantity, 2) : (float) $product->sale_price,
            ]);

            $matchedProductNames[$product->getKey()] = $product->name;
        }

        // A silently-dropped line item was only ever visible in the server
        // log — surface it directly on the order too, so a skipped SKU/name
        // mismatch is obvious to whoever opens it, without needing log
        // access. A second, separate save() (status hasn't changed here,
        // so this never triggers the WooCommerce status-push job).
        if ($unmatched !== []) {
            $order->update([
                'note' => trim($order->note."\n".count($unmatched).' item(s) could not be matched to an ERP product and were skipped: '.implode(', ', $unmatched).". Order total was still set to WooCommerce's full reported amount."),
            ]);
        }

        $this->noteOversoldStock($order, $matchedProductNames);

        return $unmatched;
    }

    /**
     * A WooCommerce order is never blocked from syncing by insufficient ERP
     * stock (see OrderWorkflowService::syncStockMovements() — the sale
     * already happened on the storefront regardless of what this ledger
     * shows), so a shortfall would otherwise pass by completely silently.
     * The trade-off is this note: the item(s) that pushed a product's stock
     * negative, so it's obvious the product needs restocking without
     * digging through the movement ledger.
     *
     * @param  array<int, string>  $productNamesById
     */
    protected function noteOversoldStock(Order $order, array $productNamesById): void
    {
        if ($productNamesById === []) {
            return;
        }

        $oversold = Product::query()
            ->where('company_id', $order->company_id)
            ->whereIn('id', array_keys($productNamesById))
            ->where('stock', '<', 0)
            ->get(['id', 'stock'])
            ->map(fn (Product $product): string => "{$productNamesById[$product->getKey()]} ({$product->stock})")
            ->all();

        if ($oversold === []) {
            return;
        }

        $order->update([
            'note' => trim($order->note."\nSold below available stock, now negative on hand and needs restocking: ".implode(', ', $oversold).'.'),
        ]);
    }

    /**
     * A skipped line item never becomes an OrderItem, so OrderWorkflowService::sync()
     * (triggered by every OrderItem's own saved/deleted hook - see
     * OrderItem::booted()) recalculates subtotal/total_amount purely from
     * whichever items DID match, silently leaving the skipped item's price
     * out of both — the order's own stored total ends up lower than what
     * WooCommerce (and the customer) actually recorded, even though the
     * skip itself is correctly noted. Corrects both using the exact same
     * subtotal-then-total formula OrderWorkflowService::sync() itself uses
     * (`total = subtotal - discount + vat + shipping_fee`), except the
     * subtotal is summed directly from every line item WooCommerce
     * reported — matched or not — instead of only the ones that became a
     * real OrderItem row.
     */
    protected function reconcileTotalsWithWooCommerce(Order $order, array $payload): void
    {
        $lineItems = (array) ($payload['line_items'] ?? []);
        $subtotal = collect($lineItems)->sum(fn (array $item): float => (float) ($item['total'] ?? 0));

        $total = max($subtotal - (float) $order->discount + (float) $order->vat + (float) $order->shipping_fee, 0);
        $due = max($total - (float) $order->paid_amount, 0);

        $order->forceFill([
            'subtotal' => $subtotal,
            'total_amount' => $total,
            'due_amount' => $due,
        ])->saveQuietly();
    }

    protected function mapStatus(string $wooStatus): string
    {
        return self::STATUS_MAP[$wooStatus] ?? Order::STATUS_DRAFT;
    }

    /**
     * Pushes $order's current status to WooCommerce as a real REST API
     * update (PUT /wp-json/wc/v3/orders/{id}), so a status change made in
     * the ERP (e.g. an admin marking an order Completed) is reflected back
     * on the WooCommerce order it originated from. Called from
     * PushWooCommerceOrderStatusJob, queued by Order::booted()'s updated()
     * hook — never called synchronously from a request.
     *
     * A silent no-op for anything that isn't an existing WooCommerce-sourced
     * order with credentials configured — this is a best-effort push, not
     * something that should ever fail the ERP-side status change itself.
     */
    public function pushStatusToWooCommerce(Order $order): void
    {
        if ($order->source !== Order::SOURCE_WOOCOMMERCE) {
            return;
        }

        $wooOrderId = $this->externalOrderId((string) $order->external_reference);

        if ($wooOrderId === null) {
            return;
        }

        // Runs from a queued job with no request-bound CompanyContext — set
        // it explicitly, matching handleOrderEvent()'s own pattern, even
        // though every read below is already constrained to this specific
        // order's own company_id via its relations regardless.
        if ($order->company) {
            $this->context->set($order->company);
        }

        $wooStatus = self::REVERSE_STATUS_MAP[$order->status] ?? null;

        if ($wooStatus === null) {
            return;
        }

        $setting = StorefrontSetting::primaryFor((int) $order->company_id);
        $baseUrl = rtrim((string) $setting?->woocommerce_base_url, '/');
        $key = (string) data_get($setting instanceof StorefrontSetting ? $setting->woocommerce_credentials : null, 'consumer_key');
        $secret = (string) data_get($setting instanceof StorefrontSetting ? $setting->woocommerce_credentials : null, 'consumer_secret');

        if ($baseUrl === '' || $key === '' || $secret === '') {
            Log::warning('Could not push order status to WooCommerce: site URL or consumer key/secret is not configured.', [
                'order_id' => $order->getKey(),
                'company_id' => $order->company_id,
            ]);

            return;
        }

        try {
            $response = Http::timeout(15)
                ->retry(2, 1000)
                ->withBasicAuth($key, $secret)
                ->put("{$baseUrl}/wp-json/wc/v3/orders/{$wooOrderId}", ['status' => $wooStatus]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to push order status to WooCommerce.', [
                'order_id' => $order->getKey(),
                'company_id' => $order->company_id,
                'woo_order_id' => $wooOrderId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($response->failed()) {
            Log::warning('WooCommerce rejected an order status push.', [
                'order_id' => $order->getKey(),
                'company_id' => $order->company_id,
                'woo_order_id' => $wooOrderId,
                'status' => $response->status(),
            ]);
        }
    }

    protected function externalOrderId(string $externalReference): ?int
    {
        if (! str_starts_with($externalReference, 'woo-')) {
            return null;
        }

        $id = (int) substr($externalReference, 4);

        return $id > 0 ? $id : null;
    }
}
