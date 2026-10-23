<?php

namespace Tests\Feature;

use App\Filament\Pages\OrderWhatsAppCard;
use App\Jobs\SendOrderWhatsAppCardJob;
use App\Models\Company;
use App\Models\ConversationChannel;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\OrderStatusWorkflowService;
use App\Services\OrderSummaryCardImageService;
use App\Services\OrderWhatsAppCardService;
use App\Services\OrderWhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The summary card sent to the customer on WhatsApp by itself (owner request
 * 2026-10-22): when an order is created and at every new stage, through an
 * official WhatsApp channel and an approved image-header template only.
 */
class OrderWhatsAppCardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private ConversationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Card Co', 'slug' => 'card-co', 'invoice_prefix' => 'CRD', 'currency' => 'BDT',
            'timezone' => 'UTC', 'is_active' => true, 'phone' => '01700000000',
        ]);
        app(CompanyContext::class)->set($this->company);
        Auth::login(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $this->channel = $this->channel('cloud_api');
    }

    public function test_nothing_is_queued_until_the_owner_switches_it_on(): void
    {
        Queue::fake();

        $this->order();

        Queue::assertNothingPushed();
    }

    public function test_a_new_order_queues_the_card_and_each_stage_change_queues_another(): void
    {
        $this->enable();
        Queue::fake();

        $order = $this->order();

        Queue::assertPushed(SendOrderWhatsAppCardJob::class, fn (SendOrderWhatsAppCardJob $job): bool => $job->orderId === $order->id
            && $job->event === 'placed' && $job->delay !== null);

        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED);

        Queue::assertPushed(SendOrderWhatsAppCardJob::class, fn (SendOrderWhatsAppCardJob $job): bool => $job->event === Order::STAGE_CONFIRMED
            && $job->transitionId !== null);
    }

    public function test_the_card_goes_out_as_a_picture_with_the_template_and_order_details(): void
    {
        $this->requireRenderer();
        $this->enable();
        Queue::fake();
        $order = $this->order();
        $this->fakeMeta();

        $this->assertTrue(app(OrderWhatsAppCardService::class)->send($order->id, 'placed'));

        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
        $upload = $requests->first(fn (Request $request): bool => str_ends_with($request->url(), '/PHONE-ID/media'));
        $message = $requests->first(fn (Request $request): bool => str_ends_with($request->url(), '/PHONE-ID/messages'));

        $this->assertNotNull($upload);
        $this->assertSame('Bearer token-123', $message->header('Authorization')[0]);
        $payload = $message->data();
        $this->assertSame('8801712345678', $payload['to']);
        $this->assertSame('template', $payload['type']);
        $this->assertSame('order_card', $payload['template']['name']);
        $this->assertSame('bn', $payload['template']['language']['code']);
        $this->assertSame('MEDIA-1', $payload['template']['components'][0]['parameters'][0]['image']['id']);
        $body = array_column($payload['template']['components'][1]['parameters'], 'text');
        $this->assertSame('Card Customer', $body[0]);
        $this->assertSame($order->order_number, $body[1]);
        $this->assertCount(5, $body);
        $this->assertNotNull($this->channel->fresh()->last_outbound_at);

        // A retried job never sends the same news twice.
        $this->assertFalse(app(OrderWhatsAppCardService::class)->send($order->id, 'placed'));
    }

    public function test_a_stage_change_sends_that_stage_as_the_status(): void
    {
        $this->requireRenderer();
        $this->enable();
        Queue::fake();
        $order = $this->order();
        $order = app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED);

        $transition = $order->statusTransitions()->latest('id')->first();
        $this->fakeMeta();

        $this->assertTrue(app(OrderWhatsAppCardService::class)->send($order->id, Order::STAGE_CONFIRMED, $transition->id));

        $message = Http::recorded()->map(fn (array $pair): Request => $pair[0])
            ->first(fn (Request $request): bool => str_ends_with($request->url(), '/PHONE-ID/messages'));
        $this->assertSame('Confirmed', $message->data()['template']['components'][1]['parameters'][2]['text']);
    }

    public function test_a_web_channel_resellers_imports_and_opted_out_customers_never_get_the_card(): void
    {
        $this->enable();
        Queue::fake();

        $reseller = Customer::query()->create(['name' => 'A Reseller', 'phone' => '01799999999']);
        Order::query()->create(['customer_id' => $this->customer()->id, 'reseller_customer_id' => $reseller->id, 'status' => Order::STATUS_DRAFT]);
        Order::query()->create(['customer_id' => $this->customer()->id, 'source' => Order::SOURCE_NUPORT_IMPORT, 'status' => Order::STATUS_DRAFT]);
        Queue::assertNothingPushed();

        // Opted out: queued is fine, but the send itself refuses.
        $quiet = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Quiet', 'phone' => '01733333333', 'opted_out_at' => now()])->id, 'status' => Order::STATUS_DRAFT]);
        $this->assertFalse(app(OrderWhatsAppCardService::class)->send($quiet->id, 'placed'));

        // A QR-linked WhatsApp Web channel is never allowed to send automatically.
        $web = $this->channel('web', 'WEB-PHONE');
        app(OrderWhatsAppSettings::class)->save($this->company, ['enabled' => true, 'channel_id' => $web->id, 'template' => 'order_card', 'language' => 'bn']);
        Queue::fake();
        $this->order();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_a_failing_send_never_blocks_the_order_and_is_shown_on_the_channel(): void
    {
        $this->requireRenderer();
        $this->enable();
        $this->fakeMeta(Http::response(['error' => ['message' => 'Recipient not on WhatsApp', 'code' => 131026]], 400));
        Queue::fake();
        $order = $this->order();

        $this->assertFalse(app(OrderWhatsAppCardService::class)->send($order->id, 'placed'));
        $this->assertNotNull($this->channel->fresh()->last_error);
    }

    public function test_the_settings_page_saves_the_channel_and_template(): void
    {
        $this->assertTrue(OrderWhatsAppCard::canAccess());

        Livewire::test(OrderWhatsAppCard::class)
            ->fillForm(['enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse(app(OrderWhatsAppSettings::class)->get($this->company)['enabled']);

        // Switching it on without a channel and template is refused.
        Livewire::test(OrderWhatsAppCard::class)
            ->fillForm(['enabled' => true])
            ->call('save')
            ->assertHasErrors();
    }

    public function test_the_card_image_is_drawn_with_the_order_details(): void
    {
        $this->requireRenderer();
        $order = $this->order();

        $png = app(OrderSummaryCardImageService::class)->render($order);

        $this->assertNotNull($png);
        [$width, $height] = getimagesizefromstring($png);
        $this->assertSame(1080, $width);
        $this->assertGreaterThan(900, $height);
    }

    /** Meta answers the picture upload and the template message (a custom answer for the message can be given). */
    private function fakeMeta(mixed $messageResponse = null): void
    {
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA-1']),
            'graph.facebook.com/*/messages' => $messageResponse ?? Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);
    }

    private function requireRenderer(): void
    {
        if (! app(OrderSummaryCardImageService::class)->canRender()) {
            $this->markTestSkipped('This machine cannot draw the card picture: '.app(OrderSummaryCardImageService::class)->problem());
        }
    }

    private function enable(): void
    {
        app(OrderWhatsAppSettings::class)->save($this->company, [
            'enabled' => true, 'channel_id' => $this->channel->id, 'template' => 'order_card', 'language' => 'bn',
        ]);
    }

    private function channel(string $type, string $externalId = 'PHONE-ID'): ConversationChannel
    {
        return ConversationChannel::query()->create([
            'provider' => 'whatsapp', 'connection_type' => $type, 'external_id' => $externalId, 'waba_id' => 'WABA-1',
            'display_name' => 'Shop WhatsApp', 'access_token' => 'token-123', 'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::query()->create(['name' => 'Card Customer', 'phone' => '01712345678', 'address' => 'Mirpur, Dhaka']);
    }

    private function order(): Order
    {
        $order = Order::query()->create(['customer_id' => $this->customer()->id, 'paid_amount' => 0, 'status' => Order::STATUS_DRAFT]);
        $product = Product::query()->create(['name' => 'Card Product', 'sku' => 'CRD-'.uniqid(), 'price' => 100, 'sale_price' => 100, 'stock' => 0]);
        StockMovement::query()->create(['product_id' => $product->id, 'type' => 'opening', 'quantity' => 20]);
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]);

        return $order->refresh();
    }
}
