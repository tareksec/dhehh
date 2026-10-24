<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvestorOtpCode;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\InvestorPortalAccountService;
use App\Support\OtpLifetime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Owner decision 2026-10-24: a one-time code (OTP) works for three minutes at most, whichever code it is.
 * The IMS join code and the investor portal's e-mailed code are covered in Ims\OnboardingTest and
 * Ims\EmailPasswordResetTest.
 */
class OtpLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private StorefrontSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Otp Co', 'slug' => 'otp-co', 'invoice_prefix' => 'OTP', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true,
        ]);
        $this->setting = StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => ['sms_api_url' => 'http://sms.example.test/send?to={phone}&msg={message}'],
        ]);
        app(CompanyContext::class)->set($this->company);
        Cache::flush();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_no_code_lifetime_goes_past_three_minutes(): void
    {
        $this->assertSame(3, OtpLifetime::MAX_MINUTES);

        foreach ([
            CustomerAccountService::REGISTRATION_CODE_MINUTES,
            CustomerAccountService::LOGIN_CODE_MINUTES,
            CustomerAccountService::RESET_CODE_MINUTES,
            InvestorOtpCode::VALID_MINUTES,
            InvestorPortalAccountService::CODE_VALID_MINUTES,
            InvestorPortalAccountService::EMAIL_CODE_VALID_MINUTES,
        ] as $minutes) {
            $this->assertLessThanOrEqual(OtpLifetime::MAX_MINUTES, $minutes);
        }

        // An emailed reset LINK is not a code: staff send it on purpose and it keeps its own life.
        $this->assertSame(60, CustomerAccountService::RESET_LINK_MINUTES);
    }

    public function test_a_login_code_stops_working_after_three_minutes(): void
    {
        $service = app(CustomerAccountService::class);
        $customer = $this->customer('01711111111');

        $this->assertTrue($service->sendLoginOtp($this->company, $this->setting, $customer, '01711111111'));
        $customer->refresh();
        $this->assertTrue($customer->login_otp_expires_at->between(now()->addMinutes(2), now()->addMinutes(3)->addSecond()));
        $code = $this->sentCode();

        Carbon::setTestNow(now()->addMinutes(3)->addSeconds(5));

        $this->assertFalse($service->verifyLoginOtp($customer->fresh(), $code));
    }

    public function test_a_login_code_still_works_inside_the_three_minutes(): void
    {
        $service = app(CustomerAccountService::class);
        $customer = $this->customer('01711111111');
        $service->sendLoginOtp($this->company, $this->setting, $customer, '01711111111');
        $code = $this->sentCode();

        Carbon::setTestNow(now()->addMinutes(2)->addSeconds(30));

        $this->assertTrue($service->verifyLoginOtp($customer->fresh(), $code));
    }

    public function test_a_password_reset_code_stops_working_after_three_minutes(): void
    {
        $service = app(CustomerAccountService::class);
        $customer = $this->customer('01722222222');

        $this->assertTrue($service->sendPasswordResetCode($this->company, $this->setting, $customer));
        $customer->refresh();
        $this->assertTrue($customer->password_reset_expires_at->between(now()->addMinutes(2), now()->addMinutes(3)->addSecond()));
        $code = $this->sentCode();

        $this->assertTrue($service->resetCodeIsValid($customer->fresh(), $code));

        Carbon::setTestNow(now()->addMinutes(3)->addSeconds(5));

        $this->assertFalse($service->resetCodeIsValid($customer->fresh(), $code));
    }

    public function test_a_sign_up_code_stops_working_after_three_minutes(): void
    {
        $service = app(CustomerAccountService::class);

        $this->assertTrue($service->sendRegistrationCode($this->company, $this->setting, '01733333333'));
        $code = $this->sentCode();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertTrue($service->verifyRegistrationCode($this->company, '01733333333', $code));

        // Used up, so ask again and let the three minutes pass.
        Carbon::setTestNow();
        Cache::flush();
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        $service->sendRegistrationCode($this->company, $this->setting, '01733333333');
        $code = $this->sentCode();

        Carbon::setTestNow(now()->addMinutes(3)->addSeconds(5));
        $this->assertFalse($service->verifyRegistrationCode($this->company, '01733333333', $code));
    }

    private function customer(string $phone): Customer
    {
        $customer = new Customer(['name' => 'Otp Buyer', 'phone' => $phone, 'is_active' => true]);
        $customer->company_id = $this->company->getKey();
        $customer->forceFill(['password' => Hash::make('Passw0rd!xyz')])->save();

        return $customer;
    }

    /** The six-digit code in the last SMS sent. */
    private function sentCode(): string
    {
        $url = '';
        Http::assertSent(function (Request $request) use (&$url): bool {
            $url = urldecode($request->url());

            return true;
        });
        $this->assertSame(1, preg_match('/OTP is (\d{6})/', $url, $matches), $url);

        return $matches[1];
    }
}
