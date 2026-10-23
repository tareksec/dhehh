<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Services\StorefrontNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The per-site SMTP account behind Settings → Integrations → Email Setup, as used with Amazon SES:
 * the sender address, one company's account never leaking into the next company's mail, and the
 * test email.
 */
class CompanyEmailSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sender_is_the_from_address_else_an_email_shaped_username_and_never_an_ses_access_key(): void
    {
        $service = app(StorefrontNotificationService::class);

        $this->assertSame('orders@shop.test', $service->mailFromAddress($this->site(['mail_from_address' => 'orders@shop.test', 'mail_username' => 'AKIAEXAMPLEKEY'])));
        $this->assertSame('office@shop.test', $service->mailFromAddress($this->site(['mail_username' => 'office@shop.test'])));
        // Amazon SES SMTP user names are access-key IDs, not email addresses.
        $this->assertNull($service->mailFromAddress($this->site(['mail_username' => 'AKIAEXAMPLEKEY'])));
        $this->assertNull($service->mailFromAddress($this->site([])));
    }

    public function test_each_company_gets_its_own_smtp_account_and_sender_even_in_one_long_running_process(): void
    {
        $first = $this->site([
            'mail_host' => 'email-smtp.ap-southeast-1.amazonaws.com', 'mail_port' => 587, 'mail_username' => 'AKIAFIRST', 'mail_password' => 'a',
            'mail_from_address' => 'hello@first.test', 'mail_from_name' => 'First Co',
        ]);
        $second = $this->site([
            'mail_host' => 'email-smtp.ap-south-1.amazonaws.com', 'mail_port' => 465, 'mail_username' => 'AKIASECOND', 'mail_password' => 'b',
            'mail_from_address' => 'hello@second.test', 'mail_from_name' => 'Second Co',
        ]);

        $one = $this->companyMailer($first);
        $this->assertSame('email-smtp.ap-southeast-1.amazonaws.com', $one->getSymfonyTransport()->getStream()->getHost());
        $this->assertSame(['address' => 'hello@first.test', 'name' => 'First Co'], $this->alwaysFrom($one));

        // A queue worker keeps the mail manager alive between jobs; the next company must not reuse the first mailer.
        $two = $this->companyMailer($second);
        $this->assertSame('email-smtp.ap-south-1.amazonaws.com', $two->getSymfonyTransport()->getStream()->getHost());
        $this->assertSame(465, $two->getSymfonyTransport()->getStream()->getPort());
        $this->assertSame(['address' => 'hello@second.test', 'name' => 'Second Co'], $this->alwaysFrom($two));
    }

    public function test_the_test_email_is_refused_until_smtp_is_saved(): void
    {
        $error = app(StorefrontNotificationService::class)->sendTestEmail($this->site([]), 'me@shop.test');

        $this->assertSame(__('SMTP host and username are not saved yet.'), $error);
    }

    public function test_the_test_email_goes_through_the_saved_smtp_account_and_returns_null_when_accepted(): void
    {
        Mail::fake();

        $error = app(StorefrontNotificationService::class)->sendTestEmail(
            $this->site(['mail_host' => 'email-smtp.ap-southeast-1.amazonaws.com', 'mail_username' => 'AKIAEXAMPLEKEY', 'mail_from_address' => 'hello@shop.test']),
            'me@shop.test',
        );

        $this->assertNull($error);
    }

    public function test_the_test_email_returns_the_servers_error_instead_of_throwing(): void
    {
        $error = app(StorefrontNotificationService::class)->sendTestEmail(
            $this->site(['mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_username' => 'AKIAEXAMPLEKEY', 'mail_password' => 'x', 'mail_from_address' => 'hello@shop.test']),
            'me@shop.test',
        );

        $this->assertIsString($error);
        $this->assertNotSame('', $error);
    }

    /** @param  array<string, mixed>  $credentials */
    protected function site(array $credentials): StorefrontSetting
    {
        $company = Company::query()->create([
            'name' => 'Mail Co', 'slug' => 'mail-co-'.uniqid(), 'invoice_prefix' => 'ML'.random_int(100, 999),
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);

        return StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => $credentials,
        ]);
    }

    protected function companyMailer(StorefrontSetting $setting): Mailer
    {
        $method = new ReflectionMethod(StorefrontNotificationService::class, 'companyMailer');

        return $method->invoke(app(StorefrontNotificationService::class), $setting);
    }

    /** @return array{address: string, name: string} */
    protected function alwaysFrom(Mailer $mailer): array
    {
        return (new ReflectionProperty(Mailer::class, 'from'))->getValue($mailer);
    }
}
