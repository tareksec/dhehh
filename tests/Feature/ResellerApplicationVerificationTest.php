<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\Auth\Apply;
use App\Models\Company;
use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision (2026-10-12): applying with a phone number that already
 * belongs to a customer needs an SMS code that proves the number is the
 * applicant's before the old customer record is used (and its password set).
 * A brand-new number needs no code.
 */
class ResellerApplicationVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $noSmsCompany;

    protected Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        // Every company is created before the portal panel is made current
        // (creating one afterwards needs panel routes the observer cannot build).
        $this->company = $this->makeCompany('apply-co.example.test', 'APP', smsConfigured: true);
        $this->noSmsCompany = $this->makeCompany('nosms-co.example.test', 'NOS', smsConfigured: false);
        $this->otherCompany = $this->makeCompany('other-co.example.test', 'OTH', smsConfigured: true);
        $this->asPortal($this->company);
    }

    public function test_a_new_number_needs_no_code(): void
    {
        $this->apply('01911112222')
            ->assertHasNoFormErrors()
            ->assertSet('verifyingPhone', null);

        $customer = Customer::query()->where('phone', '01911112222')->sole();
        $this->assertSame('pending', $customer->reseller_status);
        $this->assertTrue(Hash::check('a-strong-password', $customer->password));
        Http::assertNothingSent();
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_an_existing_customers_number_is_not_touched_until_the_code_is_entered(): void
    {
        $existing = $this->storefrontCustomer('01711000001', 'Rahim (storefront)');

        $page = $this->apply('01711000001');

        $page->assertSet('verifyingPhone', '01711000001');
        $this->assertCodeSmsSent();
        $existing = $existing->fresh();
        $this->assertSame('Rahim (storefront)', $existing->name);
        $this->assertSame('none', $existing->reseller_status);
        $this->assertNull($existing->password);
        $this->assertGuest('customer');

        // The password never waits in the session in plain text.
        $pending = session()->get('reseller-apply-pending');
        $this->assertArrayNotHasKey('password', $pending);
        $this->assertTrue(Hash::check('a-strong-password', $pending['password_hash']));

        $page->fillForm(['code' => $this->lastCode()])
            ->call('register')
            ->assertHasNoFormErrors();

        $existing = $existing->fresh();
        $this->assertSame(1, Customer::query()->where('phone', '01711000001')->count());
        $this->assertSame('pending', $existing->reseller_status);
        $this->assertSame('Karim Traders', $existing->business_name);
        $this->assertTrue(Hash::check('a-strong-password', $existing->password));
        $this->assertAuthenticatedAs($existing, 'customer');
        $this->assertNull(session()->get('reseller-apply-pending'));
    }

    public function test_a_wrong_code_changes_nothing_and_five_wrong_codes_burn_the_code(): void
    {
        $existing = $this->storefrontCustomer('01711000001', 'Rahim (storefront)');
        $page = $this->apply('01711000001');
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $page->fillForm(['code' => $wrong])->call('register')->assertHasErrors(['data.code']);
        $this->assertSame('Rahim (storefront)', $existing->fresh()->name);
        $this->assertGuest('customer');

        foreach (range(1, 4) as $ignored) {
            $page->fillForm(['code' => $wrong])->call('register');
        }

        // Five tries used up: even the right code no longer works.
        $page->fillForm(['code' => $code])->call('register')->assertHasErrors(['data.code']);
        $this->assertNull($existing->fresh()->password);
        $this->assertSame('none', $existing->fresh()->reseller_status);
    }

    public function test_knowing_an_approved_resellers_number_does_not_let_anyone_replace_their_password(): void
    {
        $approved = $this->storefrontCustomer('01822222222', 'Approved Reseller');
        $approved->forceFill([
            'reseller_status' => 'approved', 'business_name' => 'Approved Traders', 'password' => Hash::make('original-password'),
        ])->save();

        $page = $this->apply('01822222222', ['name' => 'Attacker', 'business_name' => 'Attacker Shop', 'password' => 'attackers-password', 'passwordConfirmation' => 'attackers-password']);

        $page->assertSet('verifyingPhone', '01822222222');
        $approved = $approved->fresh();
        $this->assertTrue(Hash::check('original-password', $approved->password));
        $this->assertSame('Approved Reseller', $approved->name);
        $this->assertSame('Approved Traders', $approved->business_name);
        $this->assertGuest('customer');

        // Only the real owner of the number, who receives the code, can go on --
        // and re-applying never downgrades an approved reseller.
        $page->fillForm(['code' => $this->lastCode()])->call('register');
        $approved = $approved->fresh();
        $this->assertSame('approved', $approved->reseller_status);
        $this->assertTrue(Hash::check('attackers-password', $approved->password));
    }

    public function test_other_spellings_of_an_existing_number_also_need_the_code_and_never_duplicate_it(): void
    {
        $existing = $this->storefrontCustomer('01711000001', 'Rahim (storefront)');

        $page = $this->apply('+880 1711-000001');

        $page->assertSet('verifyingPhone', '01711000001');
        $page->fillForm(['code' => $this->lastCode()])->call('register');
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame('pending', $existing->fresh()->reseller_status);
    }

    public function test_without_an_sms_gateway_an_existing_number_cannot_be_taken_over(): void
    {
        $this->company = $this->noSmsCompany;
        $this->asPortal($this->company);
        $existing = $this->storefrontCustomer('01711000001', 'Rahim (storefront)');

        $this->apply('01711000001')
            ->assertHasErrors(['data.phone'])
            ->assertSet('verifyingPhone', null);

        Http::assertNothingSent();
        $this->assertSame('none', $existing->fresh()->reseller_status);
        $this->assertNull($existing->fresh()->password);
        $this->assertGuest('customer');

        // A brand-new number still works without any SMS gateway.
        $this->apply('01933334444')->assertHasNoFormErrors();
        $this->assertSame('pending', Customer::query()->where('phone', '01933334444')->value('reseller_status'));
    }

    public function test_the_code_can_be_sent_again_only_once_a_minute_and_the_number_can_be_changed(): void
    {
        $this->storefrontCustomer('01711000001', 'Rahim (storefront)');
        $page = $this->apply('01711000001');

        $page->call('resendCode');
        Http::assertSentCount(1); // the first code is still fresh: no second SMS

        $this->travel(2)->minutes();
        $page->call('resendCode');
        Http::assertSentCount(2);

        $page->call('startOver')->assertSet('verifyingPhone', null);
        $this->assertNull(session()->get('reseller-apply-pending'));
    }

    public function test_a_number_that_belongs_to_another_company_is_a_new_number_here(): void
    {
        $other = $this->otherCompany;
        app(CompanyContext::class)->set($other);
        $theirs = $this->storefrontCustomer('01711000001', 'Other company customer', $other);
        $this->asPortal($this->company);

        $this->apply('01711000001')->assertSet('verifyingPhone', null);

        Http::assertNothingSent();
        $this->assertSame('none', Customer::withoutGlobalScopes()->find($theirs->getKey())->reseller_status);
        $this->assertSame(2, Customer::withoutGlobalScopes()->where('phone', '01711000001')->count());
        $this->assertSame($this->company->getKey(), Customer::withoutGlobalScopes()->where('phone', '01711000001')->where('reseller_status', 'pending')->value('company_id'));
    }

    public function test_the_plain_storefront_form_never_takes_over_an_existing_number(): void
    {
        $approved = $this->storefrontCustomer('01822222222', 'Approved Reseller');
        $approved->forceFill(['reseller_status' => 'approved', 'business_name' => 'Approved Traders'])->save();
        $plain = $this->storefrontCustomer('01833333333', 'Plain Customer');

        foreach (['01822222222', '+8801822222222', '01833333333'] as $phone) {
            $this->post('http://apply-co.example.test/reseller', [
                'name' => 'Someone Else', 'phone' => $phone, 'business_name' => 'Hijack Ltd', 'note' => 'x',
            ])->assertSessionHasErrors('phone');
        }

        $this->assertSame('Approved Reseller', $approved->fresh()->name);
        $this->assertSame('Approved Traders', $approved->fresh()->business_name);
        $this->assertSame('approved', $approved->fresh()->reseller_status);
        $this->assertSame('none', $plain->fresh()->reseller_status);
        $this->assertSame(2, Customer::query()->count());

        // A brand-new number is still accepted as a pending application.
        $this->post('http://apply-co.example.test/reseller', [
            'name' => 'New Person', 'phone' => '01944445555', 'business_name' => 'New Shop',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('pending', Customer::query()->where('phone', '01944445555')->value('reseller_status'));
    }

    /** @param  array<string, string>  $overrides */
    protected function apply(string $phone, array $overrides = [])
    {
        return Livewire::test(Apply::class)
            ->fillForm($overrides + [
                'name' => 'Karim Uddin',
                'phone' => $phone,
                'business_name' => 'Karim Traders',
                'note' => 'Sells in Mirpur',
                'password' => 'a-strong-password',
                'passwordConfirmation' => 'a-strong-password',
            ])
            ->call('register');
    }

    protected function lastCode(): string
    {
        $recorded = Http::recorded();
        $request = $recorded->last()[0];
        $this->assertInstanceOf(Request::class, $request);
        preg_match('/OTP is (\d{6})/', urldecode($request->url()), $matches);
        $this->assertNotEmpty($matches, 'an SMS with a code was sent');

        return $matches[1];
    }

    protected function assertCodeSmsSent(): void
    {
        Http::assertSentCount(1);
        $this->lastCode();
    }

    protected function storefrontCustomer(string $phone, string $name, ?Company $company = null): Customer
    {
        $customer = new Customer(['name' => $name, 'phone' => $phone, 'is_active' => true, 'opening_balance' => 0]);
        $customer->company_id = ($company ?? $this->company)->getKey();
        $customer->save();

        return $customer;
    }

    protected function asPortal(Company $company): void
    {
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
    }

    protected function makeCompany(string $domain, string $prefix, bool $smsConfigured): Company
    {
        $company = Company::query()->create([
            'name' => 'Company '.$prefix, 'slug' => str($domain)->slug()->toString(), 'domain' => $domain,
            'domain_verified' => true, 'invoice_prefix' => $prefix, 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'notification_credentials' => $smsConfigured ? ['sms_api_url' => 'http://sms.example.test/send?to={phone}&msg={message}'] : null,
        ]);

        return $company->fresh();
    }
}
