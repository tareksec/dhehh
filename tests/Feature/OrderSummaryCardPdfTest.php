<?php

namespace Tests\Feature;

use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderSummaryCardImageService;
use App\Services\OrderSummaryCardPdfService;
use App\Services\OrderSummaryCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Imagick;
use Tests\TestCase;

/**
 * Owner request 2026-11-01: the order summary card as a PDF. A highlighted button sits where the courier tracking
 * code used to be and opens the courier's tracking page; the code itself is not on the card; the card is as tall as
 * its products need.
 */
class OrderSummaryCardPdfTest extends TestCase
{
    use RefreshDatabase;

    private const TRACKING_CODE = 'SF-SECRET-CODE-7788';

    private int $orders = 0;

    private function orderWithProducts(int $products, ?string $trackingTemplate = 'https://track.example.test/t/{tracking_id}'): Order
    {
        $this->orders++;
        $customer = Customer::query()->create(['name' => 'Card Buyer', 'phone' => '01712345678', 'address' => 'House 5, Road 2, Dhaka']);
        $order = Order::query()->create(['customer_id' => $customer->id, 'order_date' => '2026-10-30', 'status' => 'draft']);

        foreach (range(1, $products) as $number) {
            $product = Product::query()->create(['name' => "Card Product {$number}", 'sku' => "CARD-{$this->orders}-{$number}", 'price' => 100, 'sale_price' => 100, 'stock' => 50]);
            OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]);
        }

        $provider = CourierProvider::query()->firstOrCreate(['name' => 'Card Courier'], [
            'driver' => 'steadfast', 'is_active' => true,
            'settings' => array_filter(['tracking_url' => $trackingTemplate]),
        ]);
        CourierBooking::query()->create([
            'order_id' => $order->id, 'courier_provider_id' => $provider->id, 'status' => 'booked',
            'tracking_id' => self::TRACKING_CODE, 'recipient_name' => 'Card Buyer', 'cod_amount' => 100 * $products,
        ]);

        return $order->refresh();
    }

    private function blankPng(int $width, int $height): string
    {
        $image = new Imagick;
        $image->newImage($width, $height, 'white');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    public function test_the_pdf_card_has_a_tracking_button_and_no_tracking_code(): void
    {
        $order = $this->orderWithProducts(1);
        $cards = app(OrderSummaryCardService::class);

        // The ordinary card still shows the code ...
        $plain = collect($cards->build($order)['order_rows'])->firstWhere('key', 'tracking');
        $this->assertSame(self::TRACKING_CODE, $plain['value']);

        // ... the PDF card has a button in its place and the code is nowhere in the rows.
        $rows = collect($cards->buildForPdf($order)['order_rows']);
        $this->assertNull($rows->firstWhere('key', 'tracking'));
        $button = $rows->firstWhere('key', 'track_button');
        $this->assertSame('https://track.example.test/t/'.self::TRACKING_CODE, $button['value']);
        $this->assertSame(
            $rows->pluck('key')->search('track_button'),
            collect($cards->build($order)['order_rows'])->pluck('key')->search('tracking'),
            'The button sits where the tracking code sat.',
        );
        $this->assertStringNotContainsString(self::TRACKING_CODE, json_encode($rows->where('key', '!=', 'track_button')->values()));
    }

    public function test_without_a_tracking_page_the_pdf_card_shows_no_tracking_row_at_all(): void
    {
        $order = $this->orderWithProducts(1, trackingTemplate: null);

        $keys = collect(app(OrderSummaryCardService::class)->buildForPdf($order)['order_rows'])->pluck('key')->all();

        $this->assertNotContains('tracking', $keys);
        $this->assertNotContains('track_button', $keys);
    }

    public function test_a_cancelled_booking_gets_no_button(): void
    {
        $order = $this->orderWithProducts(1);
        $order->latestCourierBooking->forceFill(['status' => CourierBooking::STATUS_CANCELLED])->save();

        $this->assertNull(app(OrderSummaryCardService::class)->build($order->refresh())['tracking_url']);
    }

    public function test_the_pdf_is_one_page_the_size_of_the_card_with_a_link_over_the_button(): void
    {
        $pdf = app(OrderSummaryCardPdfService::class)->pdf(
            $this->blankPng(1080, 400),
            1080,
            400,
            [['x' => 56.0, 'y' => 100.0, 'w' => 400.0, 'h' => 62.0, 'url' => 'https://track.example.test/t/AB 12']],
            'INV-1',
        );

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        // 0.5 pt per pixel: a 1080 x 400 card is a 540 x 200 pt page.
        $this->assertStringContainsString('/MediaBox [0 0 540 200]', $pdf);
        // The button (x 56..456, y 100..162 from the top) is x 28..228, y 119..150 from the bottom.
        $this->assertStringContainsString('/Rect [28 119 228 150]', $pdf);
        $this->assertStringContainsString('/URI (https://track.example.test/t/AB%2012)', $pdf);
        $this->assertStringContainsString('/Title (Order summary INV-1)', $pdf);
        $this->assertSame(1, substr_count($pdf, '/Type /Page '));

        // Every cross-reference entry points at the start of its own object.
        preg_match('/startxref\n(\d+)\n/', $pdf, $start);
        $this->assertStringStartsWith('xref', substr($pdf, (int) $start[1], 4));
        preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $entries);
        $this->assertNotEmpty($entries[1]);
        foreach ($entries[1] as $index => $offset) {
            $this->assertStringStartsWith(($index + 1).' 0 obj', substr($pdf, (int) $offset, 12), 'Object '.($index + 1));
        }
    }

    public function test_a_card_with_more_products_is_taller_and_keeps_its_button(): void
    {
        $images = app(OrderSummaryCardImageService::class);

        if (! $images->canRender()) {
            $this->markTestSkipped('This server cannot draw the card: '.$images->problem());
        }

        $cards = app(OrderSummaryCardService::class);
        $one = $images->renderCardWithLinks($cards->buildForPdf($this->orderWithProducts(1)));
        $five = $images->renderCardWithLinks($cards->buildForPdf($this->orderWithProducts(5)));

        $this->assertSame(1080, $one['width']);
        $this->assertGreaterThan($one['height'], $five['height']);
        $this->assertCount(1, $one['links']);
        $this->assertCount(1, $five['links']);
        $this->assertSame('https://track.example.test/t/'.self::TRACKING_CODE, $five['links'][0]['url']);
        $this->assertLessThan($five['height'], $five['links'][0]['y'] + $five['links'][0]['h']);
    }

    public function test_the_card_pdf_opens_from_the_order_and_links_to_the_tracking_page(): void
    {
        $order = $this->orderWithProducts(2);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $response = $this->actingAs($admin)->get(route('orders.summary-card.pdf', $order));

        if (! app(OrderSummaryCardPdfService::class)->canRender()) {
            $response->assertStatus(503);

            return;
        }

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('/URI (https://track.example.test/t/'.self::TRACKING_CODE.')', $response->getContent());
    }
}
