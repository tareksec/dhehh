<?php

namespace Tests\Feature;

use App\Filament\Pages\SmsTemplates;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\Investment\SettlementService;
use App\Services\OrderStatusWorkflowService;
use App\Services\SmsTemplateService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The SMS Templates page and what hangs off it (owner request 2026-10-17):
 * order status messages, sign-in / password-reset codes and the investors'
 * project status messages. Order and project messages send nothing until the
 * owner writes them; code messages fall back to the built-in wording.
 */
class SmsTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['sms.example.test/*' => Http::response('OK', 200)]);

        $this->company = $this->makeCompany('Template Co', 'template-co');
        app(CompanyContext::class)->set($this->company);
        $this->staff = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->staff);
    }

    // ---------------------------------------------------------------- order status

    public function test_a_stage_with_wording_texts_the_customer_and_a_stage_without_stays_silent(): void
    {
        $this->write([SmsTemplateService::orderKey(Order::STAGE_SHIPPING) => 'Hi :name, order :order is :status. Tracking :tracking. Due :due. - :company']);
        [$order, $customer] = $this->orderWithBooking();
        $workflow = app(OrderStatusWorkflowService::class);

        $order = $workflow->transition($order, Order::STAGE_CONFIRMED);
        $order = $workflow->transition($order, Order::STAGE_PROCESSING);
        Http::assertNothingSent();

        $workflow->transition($order, Order::STAGE_SHIPPING);

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('01712345678', $sent[0]['phone']);
        $this->assertSame("Hi Template Customer, order {$order->order_number} is Shipping. Tracking TRACK-77. Due 200. - Template Co", $sent[0]['msg']);

        // The parcel moving on inside Shipping is not news to the customer.
        $order->fresh()->update(['delivery_status' => CourierBooking::STATUS_PARTIAL_DELIVERED]);
        $this->assertCount(1, $this->sent());
    }

    public function test_nothing_is_sent_while_no_order_wording_is_written(): void
    {
        [$order] = $this->orderWithBooking();

        app(OrderStatusWorkflowService::class)->transition($order, Order::STAGE_CONFIRMED);

        Http::assertNothingSent();
    }

    public function test_reseller_orders_past_imports_and_opted_out_numbers_are_not_texted(): void
    {
        $this->write([SmsTemplateService::orderKey(Order::STAGE_CONFIRMED) => 'Order :order confirmed']);
        $reseller = Customer::query()->create(['name' => 'A Reseller', 'phone' => '01799999999']);

        $resellerOrder = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Buyer', 'phone' => '01711111111'])->id, 'reseller_customer_id' => $reseller->id, 'status' => Order::STATUS_DRAFT]);
        $import = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Old', 'phone' => '01722222222'])->id, 'source' => Order::SOURCE_NUPORT_IMPORT, 'status' => Order::STATUS_DRAFT]);
        $optedOut = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Quiet', 'phone' => '01733333333', 'opted_out_at' => now()])->id, 'status' => Order::STATUS_DRAFT]);
        $noPhone = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'No Phone'])->id, 'status' => Order::STATUS_DRAFT]);

        foreach ([$resellerOrder, $import, $optedOut, $noPhone] as $order) {
            $order->update(['status' => Order::STATUS_CONFIRMED]);
        }

        Http::assertNothingSent();

        $plain = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Plain', 'phone' => '01744444444'])->id, 'status' => Order::STATUS_DRAFT]);
        $plain->update(['status' => Order::STATUS_CONFIRMED]);

        $this->assertSame(['01744444444'], array_column($this->sent(), 'phone'));
    }

    public function test_a_failing_gateway_never_blocks_the_status_change(): void
    {
        Http::fake(['sms.example.test/*' => Http::response('boom', 500)]);
        $this->write([SmsTemplateService::orderKey(Order::STAGE_CONFIRMED) => 'Order :order confirmed']);
        $order = Order::query()->create(['customer_id' => Customer::query()->create(['name' => 'Plain', 'phone' => '01744444444'])->id, 'status' => Order::STATUS_DRAFT]);

        $order->update(['status' => Order::STATUS_CONFIRMED]);

        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);
    }

    // ---------------------------------------------------------------- codes

    public function test_customer_codes_use_the_owners_wording_or_the_built_in_text(): void
    {
        $setting = StorefrontSetting::bladeFor($this->company->id);
        $accounts = app(CustomerAccountService::class);

        $this->assertTrue($accounts->sendRegistrationCode($this->company, $setting, '01755555555'));
        $this->assertMatchesRegularExpression('/^Your Template Co OTP is \d{6}\. Use it to verify your account\. It expires in 10 minutes\.$/', $this->sent()[0]['msg']);

        $this->write([SmsTemplateService::OTP_CUSTOMER_SIGNUP => ':company code :code (:minutes min)']);
        $this->travel(2)->minutes();

        $this->assertTrue($accounts->sendRegistrationCode($this->company, $setting, '01755555555'));
        $this->assertMatchesRegularExpression('/^Template Co code \d{6} \(10 min\)$/', $this->sent()[1]['msg']);

        $customer = Customer::query()->create(['name' => 'Resetter', 'phone' => '01766666666']);
        $this->assertTrue($accounts->sendPasswordResetCode($this->company, $setting, $customer));
        $this->assertMatchesRegularExpression('/^Your Template Co OTP is \d{6}\. Use it to reset your password\. It expires in 15 minutes\.$/', $this->sent()[2]['msg']);

        $this->write([SmsTemplateService::RESET_CUSTOMER_PASSWORD => 'Reset :code']);
        $this->assertTrue($accounts->sendPasswordResetCode($this->company, $setting, $customer));
        $this->assertMatchesRegularExpression('/^Reset \d{6}$/', $this->sent()[3]['msg']);
    }

    public function test_the_sent_code_is_the_code_that_works(): void
    {
        $setting = StorefrontSetting::bladeFor($this->company->id);
        $accounts = app(CustomerAccountService::class);
        $this->write([SmsTemplateService::OTP_CUSTOMER_SIGNUP => 'Your code: :code']);

        $accounts->sendRegistrationCode($this->company, $setting, '01755555555');
        preg_match('/(\d{6})/', $this->sent()[0]['msg'], $match);

        $this->assertTrue($accounts->verifyRegistrationCode($this->company, '01755555555', $match[1]));
    }

    // ---------------------------------------------------------------- investor projects

    public function test_investors_hear_about_each_status_their_project_reaches(): void
    {
        $this->write([
            SmsTemplateService::PROJECT_RUNNING => ':name: project :project started with :amount of yours. :company',
            SmsTemplateService::PROJECT_CLOSED => ':name: project :project completed.',
            SmsTemplateService::PROJECT_SETTLED => ':name: profit :profit, payout :payout.',
        ]);
        $project = InvestmentProject::query()->create([
            'company_id' => $this->company->id, 'name' => 'Manual Project', 'duration_type' => 'custom_days', 'trade_cycle_days' => 60,
            'start_date' => now()->toDateString(), 'investor_share_percent' => 40, 'channel_partner_share_percent' => 10, 'company_share_percent' => 50, 'status' => 'open',
        ]);

        foreach ([['Alpha', '01711000010', 60000], ['Beta', '01711000011', 40000]] as [$name, $phone, $amount]) {
            $investor = Investor::query()->create(['company_id' => $this->company->id, 'name' => $name, 'phone' => $phone]);
            Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => $amount, 'payment_method' => 'bank', 'invested_at' => now()->toDateString(), 'received_by' => $this->staff->id]);
        }

        $project->update(['status' => 'running']);
        $this->assertEqualsCanonicalizing([
            'Alpha: project '.$project->project_code.' started with 60000 of yours. Template Co',
            'Beta: project '.$project->project_code.' started with 40000 of yours. Template Co',
        ], array_column($this->sent(), 'msg'));

        $project->update(['status' => 'closed']);
        $this->assertCount(4, $this->sent());

        $project->costItems()->create(['category' => 'landed_cost', 'label' => 'Machine', 'amount' => 100000]);
        $settlement = app(SettlementService::class)->calculateAndSettle($project->fresh(), 200000, $this->staff->id, acknowledgeUnprovenCosts: true);
        $this->assertCount(4, $this->sent(), 'a draft settlement is not announced');

        app(SettlementService::class)->confirmSettlement($settlement, $this->staff->id);

        $settled = array_slice(array_column($this->sent(), 'msg'), 4);
        $this->assertCount(2, $settled);
        $alpha = $settlement->payouts()->where('investor_id', Investor::query()->where('name', 'Alpha')->value('id'))->firstOrFail();
        $this->assertContains('Alpha: profit '.rtrim(rtrim(number_format((float) $alpha->profit_share_amount, 2, '.', ''), '0'), '.').', payout '.rtrim(rtrim(number_format((float) $alpha->total_payout, 2, '.', ''), '0'), '.').'.', $settled);
    }

    public function test_a_project_status_without_wording_sends_nothing(): void
    {
        $project = InvestmentProject::query()->create([
            'company_id' => $this->company->id, 'name' => 'Quiet Project', 'duration_type' => 'custom_days', 'trade_cycle_days' => 60,
            'start_date' => now()->toDateString(), 'investor_share_percent' => 40, 'channel_partner_share_percent' => 10, 'company_share_percent' => 50, 'status' => 'open',
        ]);
        $investor = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Alpha', 'phone' => '01711000010']);
        Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => 1000, 'payment_method' => 'bank', 'invested_at' => now()->toDateString(), 'received_by' => $this->staff->id]);

        $project->update(['status' => 'running']);
        $project->update(['status' => 'closed']);

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- the page

    public function test_staff_write_and_clear_templates_on_the_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $key = SmsTemplateService::orderKey(Order::STAGE_DELIVERED);

        Livewire::test(SmsTemplates::class)
            ->assertSee(__('Order status'))
            ->assertSee(__('Investor login code'))
            ->assertSee(__('Profit settled'))
            ->assertSet('data.sms_order_delivered', null)
            ->set('data.sms_order_delivered', '  Delivered :order  ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Delivered :order', app(SmsTemplateService::class)->body($this->company->id, $key));

        Livewire::test(SmsTemplates::class)
            ->assertSet('data.sms_order_delivered', 'Delivered :order')
            ->set('data.sms_order_delivered', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(app(SmsTemplateService::class)->body($this->company->id, $key));
        $this->assertDatabaseMissing('sms_templates', ['company_id' => $this->company->id, 'key' => $key]);
    }

    public function test_a_code_message_without_the_code_is_refused(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SmsTemplates::class)
            ->set('data.sms_otp_investor_login', 'Welcome to the portal')
            ->call('save')
            ->assertHasErrors(['data.sms_otp_investor_login']);

        $this->assertNull(app(SmsTemplateService::class)->body($this->company->id, SmsTemplateService::OTP_INVESTOR_LOGIN));
    }

    public function test_every_company_has_its_own_templates(): void
    {
        $other = $this->makeCompany('Other Co', 'other-co');
        $service = app(SmsTemplateService::class);

        $service->save($this->company->id, [SmsTemplateService::PROJECT_RUNNING => 'Ours']);
        $service->save($other->id, [SmsTemplateService::PROJECT_RUNNING => 'Theirs']);

        $this->assertSame('Ours', $service->body($this->company->id, SmsTemplateService::PROJECT_RUNNING));
        $this->assertSame('Theirs', $service->body($other->id, SmsTemplateService::PROJECT_RUNNING));
        $this->assertSame(['investor_project.running' => 'Ours'], $service->bodies($this->company->id));
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, string> $bodies */
    private function write(array $bodies): void
    {
        app(SmsTemplateService::class)->save($this->company->id, $bodies);
    }

    private function makeCompany(string $name, string $slug): Company
    {
        $company = Company::query()->create([
            'name' => $name, 'slug' => $slug, 'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT',
            'timezone' => 'UTC', 'is_active' => true,
        ]);

        StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => [
                'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}',
                'sms_api_key' => 'key',
                'sms_sender_id' => 'TPL',
            ],
        ]);

        return $company;
    }

    /** @return array{0: Order, 1: Customer} an order of 200 (2 x 100) with a booked parcel */
    private function orderWithBooking(): array
    {
        $customer = Customer::query()->create(['name' => 'Template Customer', 'phone' => '01712345678']);
        $product = Product::query()->create(['name' => 'Template Product', 'sku' => 'TPL-001', 'price' => 100, 'sale_price' => 100, 'stock' => 0]);
        StockMovement::query()->create(['product_id' => $product->id, 'type' => 'opening', 'quantity' => 5]);
        $order = Order::query()->create(['customer_id' => $customer->id, 'paid_amount' => 0, 'status' => Order::STATUS_DRAFT]);
        OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]);
        $provider = CourierProvider::query()->create([
            'company_id' => $order->company_id, 'name' => 'Template Courier', 'slug' => 'template-courier', 'driver' => CourierProvider::DRIVER_MANUAL,
            'credentials' => [], 'settings' => [], 'is_active' => true,
        ]);
        CourierBooking::query()->create([
            'company_id' => $order->company_id, 'courier_provider_id' => $provider->id, 'order_id' => $order->id,
            'tracking_id' => 'TRACK-77', 'recipient_name' => $customer->name, 'status' => CourierBooking::STATUS_BOOKED,
        ]);

        return [$order, $customer];
    }

    /** @return list<array{phone: string, msg: string}> */
    private function sent(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_contains($request->url(), 'sms.example.test'))
            ->map(function (Request $request): array {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return ['phone' => preg_replace('/^88(?=01)/', '', (string) $query['to']), 'msg' => (string) $query['msg']];
            })
            ->values()
            ->all();
    }
}
