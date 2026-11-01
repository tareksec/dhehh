<?php

namespace Tests\Feature;

use App\Filament\Pages\Integrations;
use App\Filament\Resources\SmsLogs\Pages\ListSmsLogs;
use App\Filament\Resources\SmsLogs\SmsLogResource;
use App\Jobs\SendOrderPlacedSmsJob;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SmsLog;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\OrderStatusSmsService;
use App\Services\SmsLogService;
use App\Services\SmsTemplateService;
use App\Services\StorefrontNotificationService;
use App\Support\BulkSmsBdPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The SMS log and the "Send test SMS" button (owner request 2026-10-23): an
 * order was placed, no SMS came, and nothing said why. Every order SMS now
 * leaves a row (queued, sent, refused by the gateway with the gateway's own
 * reason, or skipped with the reason).
 */
class SmsLogTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    /** What the fake gateway answers; a test changes it with reply(). */
    private string $gatewayBody = 'OK';

    private int $gatewayStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['sms.example.test/*' => fn () => Http::response($this->gatewayBody, $this->gatewayStatus)]);

        $this->company = Company::query()->create([
            'name' => 'Log Co', 'slug' => 'log-co', 'invoice_prefix' => 'LOG', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true,
        ]);
        $this->gateway('8809617643387');
        app(CompanyContext::class)->set($this->company);
        Auth::login(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
    }

    public function test_an_order_with_no_wording_written_says_so_in_the_log(): void
    {
        Queue::fake();

        $order = $this->order();

        Queue::assertNothingPushed();
        $row = SmsLog::query()->sole();
        $this->assertSame(SmsLog::KIND_ORDER_PLACED, $row->kind);
        $this->assertSame($order->id, $row->order_id);
        $this->assertSame(SmsLog::STATUS_SKIPPED, $row->status);
        $this->assertSame('no_wording', $row->reason);
        $this->assertSame('01712345678', $row->phone);
    }

    public function test_a_queued_order_sms_is_logged_waiting_and_then_sent(): void
    {
        $this->write([SmsTemplateService::ORDER_PLACED => 'Order :order received. - :company']);
        Queue::fake();

        $order = $this->order();

        Queue::assertPushed(SendOrderPlacedSmsJob::class);
        $this->assertSame(SmsLog::STATUS_QUEUED, SmsLog::query()->sole()->status);

        // Two minutes later nothing has left: the queue worker is not running.
        $this->assertSame(0, app(SmsLogService::class)->stuckCount());
        $this->travel(SmsLogService::STUCK_AFTER_MINUTES + 1)->minutes();
        $this->assertSame(1, app(SmsLogService::class)->stuckCount((int) $this->company->getKey()));

        $this->assertTrue(app(OrderStatusSmsService::class)->sendPlaced($order->id));

        $row = SmsLog::query()->sole();
        $this->assertSame(SmsLog::STATUS_SENT, $row->status);
        $this->assertNull($row->reason);
        $this->assertSame("Order {$order->order_number} received. - Log Co", $row->message);
        $this->assertSame(0, app(SmsLogService::class)->stuckCount());
    }

    public function test_a_gateway_refusal_is_logged_with_the_gateways_own_reason(): void
    {
        $this->reply('{"response_code":1012,"error_message":"Masking SMS must be sent in Bengali"}');
        $this->write([SmsTemplateService::ORDER_PLACED => 'Order :order received']);
        Queue::fake();
        $order = $this->order();

        $this->assertFalse(app(OrderStatusSmsService::class)->sendPlaced($order->id));

        $row = SmsLog::query()->sole();
        $this->assertSame(SmsLog::STATUS_FAILED, $row->status);
        $this->assertSame('gateway_refused', $row->reason);
        $this->assertSame(1012, $row->gateway_code);
        $this->assertSame('Masking SMS must be sent in Bengali', $row->detail);
    }

    public function test_an_unreachable_gateway_and_a_missing_gateway_are_told_apart(): void
    {
        $this->reply('boom', 500);
        $this->write([SmsTemplateService::ORDER_PLACED => 'Order :order received']);
        Queue::fake();
        $order = $this->order();

        app(OrderStatusSmsService::class)->sendPlaced($order->id);
        $row = SmsLog::query()->sole();
        $this->assertSame('gateway_unreachable', $row->reason);
        $this->assertSame('http_500', $row->detail);

        $this->clearGateway();
        $second = $this->order();
        app(OrderStatusSmsService::class)->sendPlaced($second->id);

        $this->assertSame('no_gateway', SmsLog::query()->where('order_id', $second->id)->latest('id')->value('reason'));
    }

    public function test_a_customer_without_a_phone_or_on_the_opt_out_list_is_logged_as_skipped(): void
    {
        $this->write([SmsTemplateService::ORDER_PLACED => 'Order :order received']);
        Queue::fake();

        $noPhone = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'No Phone'])->id, 'status' => Order::STATUS_DRAFT]);
        $quiet = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Quiet', 'phone' => '01733333333', 'opted_out_at' => now()])->id, 'status' => Order::STATUS_DRAFT]);

        app(OrderStatusSmsService::class)->sendPlaced($noPhone->id);
        app(OrderStatusSmsService::class)->sendPlaced($quiet->id);

        $this->assertSame('no_phone', SmsLog::query()->where('order_id', $noPhone->id)->latest('id')->value('reason'));
        $this->assertSame('opted_out', SmsLog::query()->where('order_id', $quiet->id)->latest('id')->value('reason'));
        Http::assertNothingSent();
    }

    public function test_a_status_change_without_wording_is_logged_and_one_with_wording_is_sent(): void
    {
        $this->write([SmsTemplateService::orderKey(Order::STAGE_CONFIRMED) => 'Order :order confirmed']);
        $order = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Plain', 'phone' => '01744444444'])->id, 'status' => Order::STATUS_DRAFT]);

        $order->update(['status' => Order::STATUS_CONFIRMED]);
        $order->update(['status' => Order::STATUS_PROCESSING]);

        $rows = SmsLog::query()->where('kind', SmsLog::KIND_ORDER_STATUS)->orderBy('id')->get();
        $this->assertSame(['sent', 'skipped'], $rows->pluck('status')->all());
        $this->assertSame('no_wording', $rows[1]->reason);
        $this->assertSame('Processing', $rows[1]->detail);
    }

    public function test_a_logging_failure_never_stops_the_sms(): void
    {
        $this->write([SmsTemplateService::ORDER_PLACED => 'Order :order received']);
        Queue::fake();
        $order = $this->order();

        Schema::drop('sms_logs');

        $this->assertTrue(app(OrderStatusSmsService::class)->sendPlaced($order->id));
    }

    public function test_the_log_page_lists_the_reason_and_warns_when_the_queue_is_stuck(): void
    {
        $this->write([SmsTemplateService::ORDER_PLACED => 'Order :order received']);
        Queue::fake();
        $this->order();
        $this->travel(SmsLogService::STUCK_AFTER_MINUTES + 1)->minutes();

        Livewire::test(ListSmsLogs::class)
            ->assertSee('queue worker does not seem to be running')
            ->assertSee('Waiting for the queue worker to send it.');
    }

    public function test_other_companies_never_see_this_companys_log(): void
    {
        $this->write([SmsTemplateService::ORDER_PLACED => 'Order :order received']);
        Queue::fake();
        $this->order();
        $this->assertSame(1, SmsLog::query()->count());

        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        app(CompanyContext::class)->set($other);

        $this->assertSame(0, SmsLog::query()->count());
    }

    public function test_the_test_sms_button_sends_a_real_sms_and_shows_the_gateways_answer(): void
    {
        Livewire::test(Integrations::class)
            ->call('testSms', ['phone' => '01712345678'])
            ->assertNotified(__('The gateway accepted the test SMS'));

        $row = SmsLog::query()->sole();
        $this->assertSame(SmsLog::KIND_TEST, $row->kind);
        $this->assertSame(SmsLog::STATUS_SENT, $row->status);
        Http::assertSentCount(1);
    }

    public function test_the_test_sms_button_shows_why_the_gateway_refused(): void
    {
        $this->reply('{"response_code":1007}');

        Livewire::test(Integrations::class)
            ->call('testSms', ['phone' => '01712345678'])
            ->assertNotified(__('The gateway did not send the test SMS'));

        $row = SmsLog::query()->sole();
        $this->assertSame(SmsLog::STATUS_FAILED, $row->status);
        $this->assertSame(1007, $row->gateway_code);
        $this->assertSame('Balance Insufficient', $row->detail);
    }

    public function test_the_test_sms_for_a_masking_sender_id_is_written_in_bangla(): void
    {
        $this->gateway('BRANDNAME');

        Livewire::test(Integrations::class)->call('testSms', ['phone' => '01712345678']);

        $this->assertTrue(BulkSmsBdPolicy::hasBangla((string) SmsLog::query()->sole()->message));
    }

    public function test_the_test_sms_button_says_so_when_no_gateway_is_saved(): void
    {
        $this->clearGateway();

        Livewire::test(Integrations::class)
            ->call('testSms', ['phone' => '01712345678'])
            ->assertNotified(__('The SMS gateway is not saved yet'));

        Http::assertNothingSent();
    }

    public function test_the_test_sms_button_says_so_when_sms_is_switched_off(): void
    {
        $this->switchSmsOff();

        Livewire::test(Integrations::class)
            ->call('testSms', ['phone' => '01712345678'])
            ->assertNotified(__('SMS is switched off for this site'));

        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::query()->count());
    }

    public function test_an_order_sms_is_logged_as_sms_off_not_as_a_missing_gateway_when_the_owner_switched_sms_off(): void
    {
        $this->write([SmsTemplateService::ORDER_PLACED => 'Order :order received']);
        $this->switchSmsOff();
        Queue::fake();
        $order = $this->order();

        $this->assertFalse(app(OrderStatusSmsService::class)->sendPlaced($order->id));

        $row = SmsLog::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame(SmsLog::STATUS_SKIPPED, $row->status);
        $this->assertSame('sms_off', $row->reason);
        $this->assertArrayHasKey('sms_off', SmsLogService::reasonLabels());
        Http::assertNothingSent();

        // Switched on again, the same kind of message goes out.
        $this->gateway('8809617643387');
        $second = $this->order();
        $this->assertTrue(app(OrderStatusSmsService::class)->sendPlaced($second->id));
    }

    public function test_a_user_id_not_found_reply_tells_the_owner_to_recopy_the_api_key(): void
    {
        $this->reply('{"response_code":1011,"error_message":"User Id not found"}');
        $this->write([SmsTemplateService::ORDER_PLACED => 'Order :order received']);
        Queue::fake();
        $order = $this->order();

        app(OrderStatusSmsService::class)->sendPlaced($order->id);

        $row = SmsLog::query()->sole();
        $this->assertSame(1011, $row->gateway_code);
        $why = SmsLogResource::why($row);
        $this->assertStringContainsString('User Id not found', $why);
        $this->assertStringContainsString('Copy the API key again', $why);
    }

    public function test_an_empty_api_key_is_caught_before_the_gateway_is_called(): void
    {
        $this->write([SmsTemplateService::ORDER_PLACED => 'Order :order received']);
        Queue::fake();
        $this->gateway('8809617643387', apiKey: '   ');
        $order = $this->order();

        app(OrderStatusSmsService::class)->sendPlaced($order->id);

        $row = SmsLog::query()->sole();
        $this->assertSame(SmsLog::STATUS_FAILED, $row->status);
        $this->assertSame('no_api_key', $row->reason);
        Http::assertNothingSent();
    }

    public function test_spaces_around_the_credentials_never_reach_the_gateway(): void
    {
        $this->gateway(' 8809617643387 ', apiKey: '  abc123  ');

        app(StorefrontNotificationService::class)->sendSms(StorefrontSetting::bladeFor($this->company->id), '01712345678', 'Hello');

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['key'] === 'abc123' && $query['from'] === '8809617643387';
        });
    }

    private function write(array $bodies): void
    {
        app(SmsTemplateService::class)->save($this->company->id, $bodies);
    }

    private function reply(string $body, int $status = 200): void
    {
        $this->gatewayBody = $body;
        $this->gatewayStatus = $status;
    }

    private function clearGateway(): void
    {
        StorefrontSetting::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->get()
            ->each(fn (StorefrontSetting $setting) => $setting->update(['notification_credentials' => []]));
    }

    /** The owner's SMS switch (Integrations → SMS Gateway) turned off on every site of the company; the gateway stays saved. */
    private function switchSmsOff(): void
    {
        StorefrontSetting::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->get()
            ->each(fn (StorefrontSetting $setting) => $setting->update(['notification_credentials' => [...$setting->notification_credentials, 'sms_enabled' => false]]));
    }

    private function gateway(string $senderId, string $apiKey = 'key'): void
    {
        StorefrontSetting::query()->withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $this->company->id, 'site_type' => StorefrontSetting::SITE_BLADE],
            ['notification_credentials' => [
                'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}',
                'sms_api_key' => $apiKey,
                'sms_sender_id' => $senderId,
            ]],
        );
    }

    private function order(): Order
    {
        return Order::query()->create([
            'customer_id' => Customer::query()->create(['name' => 'Log Customer', 'phone' => '01712345678'])->id,
            'status' => Order::STATUS_DRAFT,
        ]);
    }
}
