<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CompanySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner request 2026-11-01: an invoice field that nobody filled in is hidden; a filled one shows. Covers the
 * printable invoice (and the courier slip under it) and the PDF.
 */
class InvoiceEmptyFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    /** An order whose customer has only a name, for a product with no weight and no picture. */
    private function bareOrder(): Order
    {
        $customer = Customer::query()->create(['name' => 'Name Only']);
        $product = Product::query()->create(['name' => 'Plain Lamp', 'sku' => 'EMPTY-001', 'price' => 100, 'sale_price' => 100, 'stock' => 50]);
        $order = Order::query()->create(['customer_id' => $customer->id, 'status' => 'draft']);
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]);
        $order->forceFill(['order_date' => null])->saveQuietly();

        return $order->refresh();
    }

    public function test_the_printed_invoice_leaves_out_what_is_empty(): void
    {
        app(CompanySettingsService::class)->saveInvoice(['show_slip' => true, 'show_images' => true, 'show_weight' => true], Company::defaultCompany());
        $order = $this->bareOrder();

        $html = $this->actingAs($this->admin())->get(route('orders.print', $order))->assertOk()->getContent();

        $this->assertStringContainsString('Name Only', $html);
        // No phone, no address, no date, no delivery partner, no discount, VAT, delivery charge or payment.
        $this->assertStringNotContainsString('class="phone"', $html);
        $this->assertStringNotContainsString('Date:', $html);
        $this->assertStringNotContainsString('Delivery Partner:', $html);
        $this->assertStringNotContainsString('>Discount<', $html);
        $this->assertStringNotContainsString('>VAT<', $html);
        $this->assertStringNotContainsString('>Delivery Charge<', $html);
        $this->assertStringNotContainsString('>Paid<', $html);
        // No product has a weight or a picture, so neither column is printed.
        $this->assertStringNotContainsString('>Weight<', $html);
        $this->assertStringNotContainsString('>Image<', $html);
    }

    public function test_the_printed_invoice_shows_what_is_filled(): void
    {
        $order = $this->bareOrder();
        $order->customer->forceFill(['phone' => '01712345678', 'address' => 'House 5, Road 2, Dhaka'])->save();
        $order->forceFill(['order_date' => '2026-10-30', 'discount' => 10, 'paid_amount' => 20])->saveQuietly();
        $order->items->first()->product->forceFill(['weight_kg' => 0.5])->save();

        $html = $this->actingAs($this->admin())->get(route('orders.print', $order->refresh()))->assertOk()->getContent();

        $this->assertStringContainsString('01712345678', $html);
        $this->assertStringContainsString('House 5, Road 2, Dhaka', $html);
        $this->assertStringContainsString('Date:', $html);
        $this->assertStringContainsString('>Weight<', $html);
        $this->assertStringContainsString('>Discount<', $html);
        $this->assertStringContainsString('>Paid<', $html);
    }

    public function test_the_pdf_leaves_out_what_is_empty_and_shows_what_is_filled(): void
    {
        $order = $this->bareOrder();
        $render = fn (Order $order): string => view('orders.pdf', [
            'order' => $order->load(['company', 'customer', 'items.product', 'reseller']),
            'company' => app(CompanySettingsService::class)->profile($order->company),
            'invoice' => app(CompanySettingsService::class)->invoice($order->company),
            'productImages' => [],
            'resellerLogoPath' => null,
        ])->render();

        $html = $render($order);
        $this->assertStringContainsString('Name Only', $html);
        $this->assertStringNotContainsString('>Weight<', $html);
        $this->assertStringNotContainsString('>Image<', $html);
        $this->assertStringNotContainsString('Discount', $html);
        $this->assertStringNotContainsString('VAT', $html);
        $this->assertStringNotContainsString('Paid', $html);
        $this->assertStringNotContainsString('Phone:', $html);
        $this->assertStringNotContainsString('Address:', $html);

        $order->customer->forceFill(['phone' => '01712345678', 'address' => 'House 5, Dhaka'])->save();
        $order->forceFill(['order_date' => '2026-10-30', 'discount' => 10, 'paid_amount' => 20])->saveQuietly();
        $order->items->first()->product->forceFill(['weight_kg' => 0.5])->save();

        $html = $render($order->refresh());
        $this->assertStringContainsString('01712345678', $html);
        $this->assertStringContainsString('House 5, Dhaka', $html);
        $this->assertStringContainsString('Discount', $html);
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringContainsString('Weight', $html);
    }
}
