<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\Auth\ForgotPassword;
use App\Models\Company;
use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

/**
 * "Forgot password?" on the reseller portal: a reset code by SMS, then a new
 * password -- the same code rules as the storefront account reset.
 */
class ResellerPortalPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected string $host = 'https://reseller.reset-co.example.test';

    protected Company $company;

    protected StorefrontSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Reset Co', 'slug' => 'reset-co', 'domain' => 'reset-co.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'RST', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        $this->setting = StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'theme_color' => '#0F766E',
            'is_published' => true, 'new_customer_delivery_advance_enabled' => false,
            'notification_credentials' => [
                'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}',
                'sms_api_key' => 'key', 'sms_sender_id' => 'RESET',
            ],
        ]);
        Http::fake(['sms.example.test/*' => Http::response('ok')]);
    }

    public function test_the_login_page_links_to_the_reset_page_which_renders_on_the_portal_host(): void
    {
        $this->get($this->host.'/portal/login')
            ->assertOk()
            ->assertSee('/portal/password-reset/request', false);

        $this->get($this->host.'/portal/password-reset/request')
            ->assertOk()
            ->assertSee('Reset your password')
            ->assertSee('Send reset code');
    }

    public function test_a_code_is_sent_by_sms_to_a_reseller_and_the_form_moves_to_the_code_step(): void
    {
        $reseller = $this->makeReseller('01722222222', 'approved');
        $this->onPortal();

        Livewire::test(ForgotPassword::class)
            ->fillForm(['phone' => '01722222222'])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertSet('phone', '01722222222')
            ->assertNotified('If a reseller account uses that phone number, a reset code has been sent by SMS.')
            ->assertSee('Reset code');

        $reseller->refresh();
        $this->assertNotNull($reseller->password_reset_code);
        $this->assertTrue($reseller->password_reset_expires_at->isFuture());
        Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), 'password reset code'));
    }

    public function test_no_code_is_sent_to_a_phone_that_is_not_a_reseller_but_the_reply_is_the_same(): void
    {
        $shopper = $this->makeReseller('01733333333', 'none');
        $rejected = $this->makeReseller('01744444444', 'rejected');
        $this->onPortal();

        foreach (['01733333333', '01744444444', '01799999999'] as $phone) {
            cache()->flush(); // the page allows two code requests a minute
            Livewire::test(ForgotPassword::class)
                ->fillForm(['phone' => $phone])
                ->call('request')
                ->assertSet('phone', $phone)
                ->assertNotified('If a reseller account uses that phone number, a reset code has been sent by SMS.');
        }

        $this->assertNull($shopper->fresh()->password_reset_code);
        $this->assertNull($rejected->fresh()->password_reset_code);
        Http::assertNothingSent();
    }

    public function test_a_reseller_of_another_company_gets_no_code(): void
    {
        $other = Company::query()->create([
            'name' => 'Other Co', 'slug' => 'other-co', 'domain' => 'other-co.example.test',
            'domain_verified' => true, 'invoice_prefix' => 'OTH', 'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka', 'is_active' => true, 'reseller_module_enabled' => true,
        ]);
        $foreign = $this->makeReseller('01755555555', 'approved', $other);
        $this->onPortal();

        Livewire::test(ForgotPassword::class)
            ->fillForm(['phone' => '01755555555'])
            ->call('request');

        $this->assertNull($foreign->fresh()->password_reset_code);
        Http::assertNothingSent();
    }

    public function test_without_sms_settings_the_page_says_reset_is_not_available(): void
    {
        $this->setting->update(['notification_credentials' => []]);
        $reseller = $this->makeReseller('01722222222', 'approved');
        $this->onPortal();

        Livewire::test(ForgotPassword::class)
            ->fillForm(['phone' => '01722222222'])
            ->call('request')
            ->assertSet('phone', null)
            ->assertNotified('Password reset by SMS is not available right now. Please contact the store to get back into your account.');

        $this->assertNull($reseller->fresh()->password_reset_code);
    }

    public function test_the_right_code_sets_the_new_password_and_signs_the_reseller_in(): void
    {
        $reseller = $this->makeReseller('01722222222', 'approved');
        $this->onPortal();

        $page = Livewire::test(ForgotPassword::class)
            ->fillForm(['phone' => '01722222222'])
            ->call('request');
        $this->knownCode($reseller);

        $page->fillForm(['code' => '123456', 'password' => 'N3w-Passw0rd!x', 'passwordConfirmation' => 'N3w-Passw0rd!x'])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $reseller->refresh();
        $this->assertTrue(Hash::check('N3w-Passw0rd!x', $reseller->password));
        $this->assertNull($reseller->password_reset_code);
        $this->assertAuthenticatedAs($reseller, 'customer');
    }

    public function test_a_wrong_or_expired_code_is_rejected_and_the_password_stays(): void
    {
        $reseller = $this->makeReseller('01722222222', 'approved');
        $this->onPortal();

        $page = Livewire::test(ForgotPassword::class)
            ->fillForm(['phone' => '01722222222'])
            ->call('request');
        $this->knownCode($reseller);

        $page->fillForm(['code' => '000000', 'password' => 'N3w-Passw0rd!x', 'passwordConfirmation' => 'N3w-Passw0rd!x'])
            ->call('request')
            ->assertHasFormErrors(['code']);

        $reseller->forceFill(['password_reset_expires_at' => now()->subMinute()])->save();
        $page->fillForm(['code' => '123456', 'password' => 'N3w-Passw0rd!x', 'passwordConfirmation' => 'N3w-Passw0rd!x'])
            ->call('request')
            ->assertHasFormErrors(['code']);

        $this->assertTrue(Hash::check('Passw0rd!xyz', $reseller->fresh()->password));
        $this->assertGuest('customer');
    }

    public function test_send_a_new_code_goes_back_to_the_phone_step(): void
    {
        $this->makeReseller('01722222222', 'approved');
        $this->onPortal();

        Livewire::test(ForgotPassword::class)
            ->fillForm(['phone' => '01722222222'])
            ->call('request')
            ->assertSet('phone', '01722222222')
            ->call('startOver')
            ->assertSet('phone', null)
            ->assertSee('Send reset code');
    }

    public function test_the_whole_reset_works_through_the_real_portal_livewire_endpoint(): void
    {
        $reseller = $this->makeReseller('01722222222', 'approved');

        $page = $this->get($this->host.'/portal/password-reset/request')->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $response = $this->livewireCall(html_entity_decode($matches[1], ENT_QUOTES), ['data.phone' => '01722222222'])->assertOk();

        $this->assertNotNull($reseller->fresh()->password_reset_code, 'No code was sent: the company was not resolved on the update request.');

        $this->knownCode($reseller);
        $snapshot = $response->json('components.0.snapshot');
        // Livewire resets its "middleware already applied" memory at the end
        // of each real request (every PHP request is fresh in production); in
        // one test process that has to be done by hand.
        Livewire::flushState();
        $this->livewireCall($snapshot, [
            'data.code' => '123456', 'data.password' => 'N3w-Passw0rd!x', 'data.passwordConfirmation' => 'N3w-Passw0rd!x',
        ])->assertOk();

        $this->assertTrue(Hash::check('N3w-Passw0rd!x', $reseller->fresh()->password));
    }

    /** @param  array<string, mixed>  $updates */
    protected function livewireCall(string $snapshot, array $updates)
    {
        return $this->withSession(['_token' => 'tok'])
            ->withHeaders(['X-Livewire' => 'true', 'X-CSRF-TOKEN' => 'tok'])
            ->postJson($this->host.EndpointResolver::updatePath(), [
                '_token' => 'tok',
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => $updates,
                    'calls' => [['path' => '', 'method' => 'request', 'params' => []]],
                ]],
            ]);
    }

    protected function knownCode(Customer $customer): void
    {
        $customer->forceFill([
            'password_reset_code' => Hash::make('123456'),
            'password_reset_expires_at' => now()->addMinutes(15),
        ])->save();
    }

    protected function onPortal(): void
    {
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
    }

    protected function makeReseller(string $phone, string $status, ?Company $company = null): Customer
    {
        $company ??= $this->company;
        $customer = new Customer([
            'name' => 'Reseller '.$phone, 'phone' => $phone, 'reseller_status' => $status,
            'business_name' => 'Shop', 'is_active' => true,
        ]);
        $customer->company_id = $company->getKey();
        $customer->forceFill(['password' => Hash::make('Passw0rd!xyz')])->save();

        return $customer;
    }
}
