<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WooCommerceOrderWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_order_created_webhook_creates_an_order_with_matched_items(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-secret-1');
        $product = $this->createProduct($company, 'Webhook Product', 'WOO-SKU-1');

        $payload = $this->orderPayload(id: 501, sku: $product->sku);

        $response = $this->postWebhook($company, $payload, 'order.created', 'woo-secret-1');

        $response->assertOk()->assertJson(['ok' => true]);

        $order = Order::query()->where('company_id', $company->getKey())->where('external_reference', 'woo-501')->first();

        $this->assertNotNull($order);
        $this->assertSame(Order::SOURCE_WOOCOMMERCE, $order->source);
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame($product->getKey(), $order->items()->first()->product_id);
        $this->assertSame('Woo Customer', $order->customer_name);

        $customer = Customer::query()->where('phone', '01700000099')->first();
        $this->assertNotNull($customer);
        $this->assertSame($order->customer_id, $customer->getKey());
    }

    public function test_a_redelivered_webhook_updates_the_same_order_instead_of_duplicating_it(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-secret-2');
        $product = $this->createProduct($company, 'Webhook Product 2', 'WOO-SKU-2');

        $payload = $this->orderPayload(id: 502, sku: $product->sku, status: 'pending');
        $this->postWebhook($company, $payload, 'order.created', 'woo-secret-2')->assertOk();

        $updatedPayload = $this->orderPayload(id: 502, sku: $product->sku, status: 'completed');
        $this->postWebhook($company, $updatedPayload, 'order.updated', 'woo-secret-2')->assertOk();

        $orders = Order::query()->where('company_id', $company->getKey())->where('external_reference', 'woo-502')->get();

        $this->assertCount(1, $orders);
        $this->assertSame(Order::STATUS_COMPLETED, $orders->first()->status);
    }

    public function test_a_line_item_with_no_matching_sku_is_skipped_without_failing_the_whole_order(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-secret-3');
        $product = $this->createProduct($company, 'Matched Product', 'WOO-SKU-3');

        $payload = $this->orderPayload(id: 503, sku: $product->sku);
        $payload['line_items'][] = [
            'sku' => 'DOES-NOT-EXIST',
            'name' => 'Unknown product',
            'quantity' => 1,
            'total' => '50.00',
        ];

        $response = $this->postWebhook($company, $payload, 'order.created', 'woo-secret-3');

        $response->assertOk();

        $order = Order::query()->where('company_id', $company->getKey())->where('external_reference', 'woo-503')->first();

        $this->assertNotNull($order);
        $this->assertSame(1, $order->items()->count());
    }

    /**
     * Regression test for a real production bug: a real WooCommerce catalog
     * that doesn't set a SKU on every product left every line item
     * unmatched (blank SKU never matches anything), so the order synced
     * with zero items and a zero subtotal even though the order itself
     * came through fine. Line items now also match by product name (via
     * the same slug normalization WooCommerceImportService already uses).
     */
    public function test_a_line_item_with_a_blank_sku_matches_by_product_name_instead(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-secret-8');
        $product = $this->createProduct($company, 'Matched By Name Product', 'WOO-SKU-8');

        $payload = $this->orderPayload(id: 508, sku: '');
        $payload['line_items'][0]['name'] = 'Matched By Name Product';

        $this->postWebhook($company, $payload, 'order.created', 'woo-secret-8')->assertOk();

        $order = Order::query()->where('company_id', $company->getKey())->where('external_reference', 'woo-508')->first();

        $this->assertNotNull($order);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame($product->getKey(), $order->items()->first()->product_id);
    }

    public function test_an_unmatched_line_item_leaves_a_visible_note_on_the_order(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-secret-9');

        $payload = $this->orderPayload(id: 509, sku: 'DOES-NOT-EXIST-EITHER');
        $payload['line_items'][0]['name'] = 'Totally Unknown Product';

        $this->postWebhook($company, $payload, 'order.created', 'woo-secret-9')->assertOk();

        $order = Order::query()->where('company_id', $company->getKey())->where('external_reference', 'woo-509')->first();

        $this->assertNotNull($order);
        $this->assertSame(0, $order->items()->count());
        $this->assertStringContainsString('could not be matched', $order->note);
        $this->assertStringContainsString('Totally Unknown Product', $order->note);
    }

    /**
     * Regression test for a real production bug (screenshot: WooCommerce
     * order #38043, "608 Easy Power Rechargeble Solar Fan" / SKU FAN-975):
     * an order with one unmatched item still showed a total of just the
     * shipping fee (৳120) instead of the ৳1,870 the customer actually
     * paid, because subtotal/total_amount are always recalculated purely
     * from whichever OrderItems got created (OrderWorkflowService::sync())
     * — an unmatched item's price was silently missing from both. The
     * order's total should always reflect what WooCommerce actually
     * recorded, even when a line item couldn't be tied to an ERP product.
     */
    public function test_an_order_with_an_unmatched_item_still_gets_woocommerces_full_total_not_just_shipping(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-secret-10');

        $payload = $this->orderPayload(id: 510, sku: 'DOES-NOT-EXIST');
        $payload['line_items'][0]['name'] = 'Unmatched Fan';
        $payload['line_items'][0]['total'] = '1750.00';
        $payload['shipping_total'] = '120.00';

        $this->postWebhook($company, $payload, 'order.created', 'woo-secret-10')->assertOk();

        $order = Order::query()->where('company_id', $company->getKey())->where('external_reference', 'woo-510')->first();

        $this->assertNotNull($order);
        $this->assertSame(0, $order->items()->count());
        $this->assertEquals(1750.0, (float) $order->subtotal);
        $this->assertEquals(1870.0, (float) $order->total_amount);
        $this->assertEquals(1870.0, (float) $order->due_amount);
    }

    public function test_totals_still_reconcile_correctly_when_only_some_items_are_unmatched(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-secret-12');
        $product = $this->createProduct($company, 'Matched Product Twelve', 'WOO-SKU-12');

        $payload = $this->orderPayload(id: 512, sku: $product->sku);
        $payload['line_items'][0]['total'] = '200.00';
        $payload['line_items'][] = [
            'sku' => 'DOES-NOT-EXIST',
            'name' => 'Unmatched Extra Item',
            'quantity' => 1,
            'total' => '50.00',
        ];
        $payload['shipping_total'] = '30.00';

        $this->postWebhook($company, $payload, 'order.created', 'woo-secret-12')->assertOk();

        $order = Order::query()->where('company_id', $company->getKey())->where('external_reference', 'woo-512')->first();

        $this->assertNotNull($order);
        $this->assertSame(1, $order->items()->count());
        $this->assertEquals(250.0, (float) $order->subtotal); // 200 matched + 50 unmatched
        $this->assertEquals(280.0, (float) $order->total_amount); // 250 + 30 shipping
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        Log::spy();

        $company = $this->createCompanyWithWebhookSecret('woo-secret-4');
        $payload = $this->orderPayload(id: 504, sku: 'WHATEVER');

        $response = $this->post(route('woocommerce.webhook', $company), $payload, [
            'X-WC-Webhook-Topic' => 'order.created',
            'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', json_encode($payload), 'wrong-secret', true)),
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('orders', ['external_reference' => 'woo-504']);

        // WooCommerce's own delivery-failure banner only ever shows the
        // status code, never why — the response body is the owner's only
        // self-serve diagnostic (no server/log access needed), so it must
        // confirm the signature header did arrive without leaking either
        // secret or signature.
        $response->assertJson([
            'ok' => false,
            'error' => 'signature_mismatch',
            'signature_header_present' => true,
        ]);

        // A rejected delivery must leave a forensic trail — this exact log
        // line is what let a real production 403 get diagnosed at all
        // (WooCommerce's own delivery-failure banner only ever shows the
        // status code, never why).
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'WooCommerce webhook rejected: signature does not match the secret saved for this company.'
                && $context['company'] === $company->getKey()
                && $context['signature_header_present'] === true);
    }

    public function test_a_missing_webhook_secret_is_reported_with_a_self_serve_hint(): void
    {
        $company = Company::query()->create([
            'name' => 'Woo Webhook Company No Secret',
            'slug' => 'woo-webhook-company-no-secret',
            'invoice_prefix' => 'NOSC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'woocommerce_base_url' => 'https://example-store.test',
            'woocommerce_credentials' => [
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
            ],
        ]);

        $response = $this->postWebhook($company, $this->orderPayload(id: 505, sku: 'WHATEVER'), 'order.created', 'irrelevant-since-no-secret-is-saved');

        $response->assertNotFound();
        $response->assertJson(['ok' => false, 'error' => 'no_webhook_secret_saved']);
        $this->assertDatabaseMissing('orders', ['external_reference' => 'woo-505']);
    }

    public function test_a_webhook_secret_cannot_be_used_against_a_different_companys_route(): void
    {
        $companyA = $this->createCompanyWithWebhookSecret('woo-secret-a');
        $companyB = $this->createCompanyWithWebhookSecret('woo-secret-b');

        $payload = $this->orderPayload(id: 505, sku: 'WHATEVER');

        // Signed with company A's secret but posted to company B's delivery URL.
        $response = $this->postWebhook($companyB, $payload, 'order.created', 'woo-secret-a');

        $response->assertForbidden();
        $this->assertDatabaseMissing('orders', ['company_id' => $companyB->getKey(), 'external_reference' => 'woo-505']);
    }

    /**
     * Regression test for a real production bug: Order::creating() auto-fills
     * shipping_zone/shipping_fee from the ERP's own zone-keyword calculator
     * whenever shipping_zone is still null on a brand-new order — a
     * WooCommerce-synced order used to leave shipping_zone null, so this
     * silently overwrote the real WooCommerce shipping_total with a guessed
     * ERP fee. Configures a company whose ERP-calculated fee (70) would
     * differ from WooCommerce's real one (150) if the bug were still
     * present, so this only passes when WooCommerce's value actually wins.
     */
    public function test_shipping_fee_and_total_use_woocommerces_real_values_not_the_erp_zone_calculator(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-secret-7');
        $product = $this->createProduct($company, 'Webhook Product 7', 'WOO-SKU-7');

        $company->update(['settings' => array_merge($company->settings ?? [], [
            'shipping_zones' => ['inside' => ['dhaka'], 'outside' => [], 'suburb' => []],
        ])]);
        CourierProvider::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Test Courier',
            'driver' => 'manual',
            'is_active' => true,
            'settings' => ['delivery_fees' => ['inside' => 70, 'outside' => 100, 'suburb' => 90]],
        ]);

        $payload = $this->orderPayload(id: 507, sku: $product->sku);
        $payload['shipping_total'] = '150.00';
        $payload['discount_total'] = '10.00';
        $payload['total_tax'] = '5.00';

        $this->postWebhook($company, $payload, 'order.created', 'woo-secret-7')->assertOk();

        $order = Order::query()->where('company_id', $company->getKey())->where('external_reference', 'woo-507')->first();

        $this->assertNotNull($order);
        $this->assertSame(150.0, (float) $order->shipping_fee);
        // subtotal (200 from the 2x100 line item) - discount (10) + vat (5) + shipping (150) = 345
        $this->assertSame(345.0, (float) $order->total_amount);
    }

    public function test_order_deleted_topic_cancels_the_order_instead_of_deleting_it(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-secret-6');
        $product = $this->createProduct($company, 'Webhook Product 6', 'WOO-SKU-6');

        $this->postWebhook($company, $this->orderPayload(id: 506, sku: $product->sku), 'order.created', 'woo-secret-6')->assertOk();

        $this->postWebhook($company, ['id' => 506], 'order.deleted', 'woo-secret-6')->assertOk();

        $order = Order::query()->where('company_id', $company->getKey())->where('external_reference', 'woo-506')->first();

        $this->assertNotNull($order);
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
    }

    /**
     * Regression test for a real production bug: a WooCommerce order whose
     * line-item quantity exceeded the ERP's own tracked stock used to fail
     * to sync at all — StockMovementService::validate()'s usual
     * negative-stock guard (correct for a manually-entered ERP sale) threw
     * ValidationException, which bubbled up as an HTTP 500 and left the
     * order missing from the ERP entirely, silently, since WooCommerce's
     * own delivery log doesn't show response bodies without WP_DEBUG. The
     * sale already happened on the storefront regardless of ERP stock, so
     * the sync must always succeed; the shortfall is surfaced as a note
     * instead (see WooCommerceOrderSyncService::noteOversoldStock()).
     */
    public function test_an_order_syncs_even_when_it_oversells_the_available_stock(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-secret-11');
        $product = $this->createProduct($company, 'Webhook Product 11', 'WOO-SKU-11');

        // createProduct() opens with 20 in stock — bring the real ledger
        // down to 1 (not just the cached `stock` column, which the sale
        // movement below would recompute from the ledger anyway).
        StockMovement::query()->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity' => -19,
            'reason' => 'Test setup: bring stock down to 1',
        ]);

        $payload = $this->orderPayload(id: 511, sku: $product->sku);
        $payload['line_items'][0]['quantity'] = 5;

        $this->postWebhook($company, $payload, 'order.created', 'woo-secret-11')->assertOk();

        $order = Order::query()->where('company_id', $company->getKey())->where('external_reference', 'woo-511')->first();

        $this->assertNotNull($order);
        $this->assertSame(5, (int) $order->items()->sum('quantity'));
        $this->assertSame(-4, (int) $product->refresh()->stock);
        $this->assertStringContainsString('Webhook Product 11 (-4)', $order->note);
    }

    private function postWebhook(Company $company, array $payload, string $topic, string $secret)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            route('woocommerce.webhook', $company),
            server: [
                'HTTP_X-WC-Webhook-Topic' => $topic,
                'HTTP_X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );
    }

    private function orderPayload(int $id, string $sku, string $status = 'processing'): array
    {
        return [
            'id' => $id,
            'number' => (string) $id,
            'status' => $status,
            'date_created' => '2026-08-20T10:00:00',
            'discount_total' => '0.00',
            'total_tax' => '0.00',
            'shipping_total' => '0.00',
            'billing' => [
                'first_name' => 'Woo',
                'last_name' => 'Customer',
                'phone' => '01700000099',
                'email' => 'woo-customer@example.test',
                'address_1' => '123 Test Road',
                'city' => 'Dhaka',
            ],
            'line_items' => [
                [
                    'sku' => $sku,
                    'name' => 'Line item',
                    'quantity' => 2,
                    'total' => '200.00',
                ],
            ],
        ];
    }

    public function test_the_webhook_is_verified_with_the_next_js_sites_secret_once_the_company_has_one(): void
    {
        $company = $this->createCompanyWithWebhookSecret('woo-blade-secret');
        $product = $this->createProduct($company, 'Primary Row Product', 'WOO-SKU-PRIMARY');
        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'nextjs_site_enabled' => true,
            'woocommerce_base_url' => 'https://example-store.test',
            'woocommerce_credentials' => ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test', 'webhook_secret' => 'woo-next-secret'],
        ]);

        $payload = $this->orderPayload(id: 590, sku: $product->sku);

        // WooCommerce is configured on the Next.js site (the main storefront); the Blade row's old secret no longer counts.
        $this->postWebhook($company, $payload, 'order.created', 'woo-blade-secret')->assertForbidden();
        $this->postWebhook($company, $payload, 'order.created', 'woo-next-secret')->assertOk();
        $this->assertTrue(Order::query()->where('external_reference', 'woo-590')->exists());
    }

    private function createCompanyWithWebhookSecret(string $secret): Company
    {
        $company = Company::query()->create([
            'name' => 'Woo Webhook Company '.$secret,
            'slug' => 'woo-webhook-company-'.$secret,
            'invoice_prefix' => strtoupper(substr($secret, -4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'woocommerce_base_url' => 'https://example-store.test',
            'woocommerce_credentials' => [
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
                'webhook_secret' => $secret,
            ],
        ]);

        return $company;
    }

    private function createProduct(Company $company, string $name, string $sku): Product
    {
        $category = Category::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Webhook Category',
            'slug' => 'webhook-category-'.$company->getKey(),
        ]);

        $product = Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => $name,
            'sku' => $sku,
            'unit' => 'pcs',
            'cost_price' => 50,
            'sale_price' => 100,
            'price' => 100,
            'stock' => 20,
            'reorder_level' => 2,
            'is_active' => true,
            'category_id' => $category->id,
        ]);

        StockMovement::query()->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => 20,
            'reference_type' => Product::class,
            'reference_id' => $product->id,
            'note' => 'Webhook test opening stock',
        ]);

        return $product;
    }
}
