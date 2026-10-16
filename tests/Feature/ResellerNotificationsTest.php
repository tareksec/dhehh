<?php

namespace Tests\Feature;

use App\Filament\Pages\ResellerSettings;
use App\Models\Company;
use App\Models\CourierBooking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ResellerPayoutRequest;
use App\Models\StockMovement;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ResellerCommissionService;
use App\Services\ResellerNotificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SMS to a reseller when they are approved, when a profit becomes payable and
 * when a withdrawal is paid (owner request 2026-10-16). The wording is the
 * owner's: a message with no wording is never sent.
 */
class ResellerNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected StorefrontSetting $setting;

    protected Customer $reseller;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::fake([
            'sms.example.test/*' => Http::response('{"response_code":202}', 200),
            'bad.example.test/*' => Http::response('{"response_code":1007}', 200),
        ]);

        $this->company = $this->makeCompany('Notify Co', 'notify-co');
        $this->setting = $this->makeSetting($this->company, [
            'reseller_message_approved' => 'Hi :name, you are approved. Login: :portal Store: :store_link',
            'reseller_message_profit_payable' => 'Hi :name, order :order delivered, profit :profit, you can withdraw :balance',
            'reseller_message_payout_paid' => 'Hi :name, :amount sent, ref :reference',
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->reseller = $this->makeReseller($this->company, 'Anna Traders', 'pending');
        $this->product = $this->makeProduct($this->company);
    }

    public function test_approving_a_reseller_sends_the_approval_sms_with_their_details(): void
    {
        $this->reseller->ensureResellerSlug();
        $this->reseller->forceFill(['reseller_status' => 'approved'])->save();

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, 'sms.example.test')
                && str_contains($url, 'Hi Anna Traders, you are approved.')
                && str_contains($url, 'https://reseller.notify-co.example.test/portal')
                && str_contains($url, '/store/'.$this->reseller->reseller_slug);
        });
        Http::assertSentCount(1);
    }

    public function test_nothing_is_sent_while_a_message_has_no_wording_or_the_status_did_not_change(): void
    {
        $this->setting->forceFill(['reseller_message_approved' => null])->save();

        $this->reseller->forceFill(['reseller_status' => 'approved'])->save();
        Http::assertNothingSent();

        // Saved again while already approved: no change of status, no message.
        $this->setting->forceFill(['reseller_message_approved' => 'Hi :name'])->save();
        $this->reseller->forceFill(['business_name' => 'Anna Shop'])->save();
        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_without_an_sms_gateway_or_to_a_number_that_opted_out(): void
    {
        $this->setting->forceFill(['notification_credentials' => []])->save();
        $this->reseller->forceFill(['reseller_status' => 'approved'])->save();
        Http::assertNothingSent();

        $this->setting->forceFill(['notification_credentials' => $this->gateway()])->save();
        $optedOut = $this->makeReseller($this->company, 'Opted Out', 'pending');
        $optedOut->forceFill(['opted_out_at' => now()])->save();
        $optedOut->forceFill(['reseller_status' => 'approved'])->save();
        Http::assertNothingSent();
    }

    public function test_a_profit_that_becomes_payable_sends_one_sms_and_never_a_second_for_the_same_order(): void
    {
        $approved = $this->makeReseller($this->company, 'Approved Anna', 'approved');

        $order = $this->sell($approved, 'N-1', Order::STATUS_CONFIRMED, 3);
        // Confirmed but not delivered: the profit is still pending, so nothing is sent.
        Http::assertNothingSent();

        $order->forceFill(['delivery_status' => CourierBooking::STATUS_DELIVERED])->save();
        app(ResellerCommissionService::class)->sync($order->fresh());

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, 'order N-1 delivered') && str_contains($url, 'profit') && str_contains($url, '300');
        });
        Http::assertSentCount(1);

        // The same order synced again (or saved again) does not message twice.
        app(ResellerCommissionService::class)->sync($order->fresh());
        $order->forceFill(['customer_name' => 'Renamed buyer'])->save();
        Http::assertSentCount(1);
    }

    public function test_a_cancelled_order_sends_nothing(): void
    {
        $approved = $this->makeReseller($this->company, 'Approved Anna', 'approved');

        $order = $this->sell($approved, 'N-2', Order::STATUS_CANCELLED, 2);
        app(ResellerCommissionService::class)->sync($order->fresh());

        Http::assertNothingSent();
    }

    public function test_a_paid_withdrawal_sends_the_paid_sms_with_the_reference(): void
    {
        $approved = $this->makeReseller($this->company, 'Approved Anna', 'approved');

        $payout = ResellerPayoutRequest::query()->create([
            'company_id' => $this->company->getKey(), 'customer_id' => $approved->getKey(), 'amount' => 1500, 'method' => 'bkash',
            'account_details' => '01711111111', 'status' => ResellerPayoutRequest::STATUS_APPROVED,
        ]);
        Http::assertNothingSent();

        $payout->update(['status' => ResellerPayoutRequest::STATUS_PAID, 'payment_reference' => 'TX9988', 'paid_at' => now()]);

        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'ref TX9988')
            && str_contains(urldecode($request->url()), '1,500'));
        Http::assertSentCount(1);

        $payout->update(['admin_note' => 'checked']);
        Http::assertSentCount(1);
    }

    public function test_a_failing_gateway_never_breaks_the_save_and_a_later_try_is_still_allowed(): void
    {
        // This company's gateway refuses every SMS (no balance).
        $this->setting->forceFill(['notification_credentials' => ['sms_api_url' => 'http://bad.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}'] + $this->gateway()])->save();

        $this->reseller->forceFill(['reseller_status' => 'approved'])->save();

        $this->assertSame('approved', $this->reseller->fresh()->reseller_status);

        $payout = ResellerPayoutRequest::query()->create([
            'company_id' => $this->company->getKey(), 'customer_id' => $this->reseller->getKey(), 'amount' => 200, 'method' => 'bkash',
            'account_details' => '01711111111', 'status' => ResellerPayoutRequest::STATUS_APPROVED,
        ]);
        $payout->update(['status' => ResellerPayoutRequest::STATUS_PAID, 'payment_reference' => 'T1', 'paid_at' => now()]);
        $this->assertFalse(Cache::has('reseller-sms:payout_paid:payout-'.$payout->getKey()));

        // The gateway is topped up again: the same withdrawal can still be messaged.
        $this->setting->forceFill(['notification_credentials' => $this->gateway()])->save();
        $this->assertTrue(app(ResellerNotificationService::class)->send(
            (int) $this->company->getKey(), ResellerNotificationService::EVENT_PAYOUT_PAID, (int) $this->reseller->getKey(), $payout->getKey(), 'payout-'.$payout->getKey(),
        ));
    }

    public function test_one_companys_wording_is_never_used_for_another_companys_reseller(): void
    {
        $quiet = $this->makeCompany('Quiet Co', 'quiet-co');
        $this->makeSetting($quiet, []);
        app(CompanyContext::class)->set($quiet);
        $theirs = $this->makeReseller($quiet, 'Quiet Reseller', 'pending');

        $theirs->forceFill(['reseller_status' => 'approved'])->save();

        Http::assertNothingSent();
    }

    public function test_the_company_context_of_the_request_is_kept_after_a_message_is_sent(): void
    {
        $this->reseller->forceFill(['reseller_status' => 'approved'])->save();

        $this->assertSame($this->company->getKey(), app(CompanyContext::class)->id());
    }

    public function test_the_three_messages_are_saved_from_reseller_settings_and_can_be_loaded_with_suggested_wording(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);
        app(CompanyContext::class)->set($this->company);

        Livewire::test(ResellerSettings::class)
            ->fillForm([
                'reseller_message_approved' => '  Welcome :name  ',
                'reseller_message_profit_payable' => '',
                'reseller_message_payout_paid' => 'Paid :amount',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = $this->company->fresh()->storefrontSetting;
        $this->assertSame('Welcome :name', $setting->reseller_message_approved);
        $this->assertNull($setting->reseller_message_profit_payable);
        $this->assertSame('Paid :amount', $setting->reseller_message_payout_paid);

        // The suggested wording is only a draft in the form: nothing is saved until Save is pressed.
        Livewire::test(ResellerSettings::class)
            ->callAction('useSuggestedMessages')
            ->assertSet('data.reseller_message_profit_payable', ResellerNotificationService::suggestedWording(ResellerNotificationService::EVENT_PROFIT_PAYABLE));
        $this->assertNull($this->company->fresh()->storefrontSetting->reseller_message_profit_payable);
    }

    public function test_a_message_longer_than_the_limit_is_refused(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->getKey()]);
        app(CompanyContext::class)->set($this->company);

        Livewire::test(ResellerSettings::class)
            ->fillForm(['reseller_message_approved' => Str::repeat('a', 301)])
            ->call('save')
            ->assertHasFormErrors(['reseller_message_approved' => 'max']);
    }

    protected function gateway(): array
    {
        return [
            'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}',
            'sms_api_key' => 'key',
            'sms_sender_id' => 'RM',
        ];
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        return Company::query()->create([
            'name' => $name, 'slug' => $slug, 'domain' => $slug.'.example.test', 'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
    }

    protected function makeSetting(Company $company, array $messages): StorefrontSetting
    {
        return StorefrontSetting::query()->create([
            'company_id' => $company->getKey(), 'is_published' => true, 'new_customer_delivery_advance_enabled' => false,
            'notification_credentials' => $this->gateway(),
        ] + $messages);
    }

    protected function makeReseller(Company $company, string $name, string $status): Customer
    {
        app(CompanyContext::class)->set($company);

        return Customer::query()->create([
            'company_id' => $company->getKey(), 'name' => $name, 'phone' => '019'.random_int(10000000, 99999999),
            'reseller_status' => $status, 'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);
    }

    protected function makeProduct(Company $company): Product
    {
        app(CompanyContext::class)->set($company);

        $product = Product::query()->create([
            'company_id' => $company->getKey(), 'name' => 'Widget', 'sku' => Str::upper(Str::random(8)), 'price' => 200, 'sale_price' => 200,
            'cost_price' => 100, 'stock' => 0, 'reorder_level' => 1, 'unit' => 'pcs', 'vat_rate' => 0, 'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        StockMovement::query()->create(['product_id' => $product->getKey(), 'type' => 'opening', 'quantity' => 1000]);

        return $product;
    }

    /** An order credited to the reseller: sold at 200, the reseller's cost 100 a piece, so 100 profit a piece. */
    protected function sell(Customer $reseller, string $number, string $status, int $quantity): Order
    {
        app(CompanyContext::class)->set($this->company);
        $buyer = Customer::query()->firstOrCreate(
            ['company_id' => $this->company->getKey(), 'phone' => '01800000000'],
            ['name' => 'Buyer', 'address' => 'Some address', 'is_active' => true],
        );

        $order = Order::withoutEvents(fn () => Order::query()->create([
            'company_id' => $this->company->getKey(), 'order_number' => $number, 'customer_id' => $buyer->getKey(),
            'reseller_customer_id' => $reseller->getKey(), 'customer_name' => $buyer->name, 'order_date' => now()->toDateString(),
            'subtotal' => 200 * $quantity, 'discount' => 0, 'vat' => 0, 'shipping_fee' => 0, 'total_amount' => 200 * $quantity,
            'paid_amount' => 0, 'due_amount' => 200 * $quantity, 'status' => $status, 'source' => Order::SOURCE_STOREFRONT,
        ]));

        OrderItem::withoutEvents(fn () => OrderItem::query()->create([
            'company_id' => $this->company->getKey(), 'order_id' => $order->getKey(), 'product_id' => $this->product->getKey(),
            'quantity' => $quantity, 'unit_price' => 200, 'unit_cost' => 100, 'reseller_base_price' => 100, 'subtotal' => 200 * $quantity,
        ]));

        app(ResellerCommissionService::class)->sync($order->fresh());

        return $order->fresh();
    }
}
