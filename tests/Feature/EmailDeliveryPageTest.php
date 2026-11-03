<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailDelivery;
use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Email\EmailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Email delivery (owner request 2026-11-02): the usage and cost of whichever mail service the site
 * sends through, found from its SMTP host. Who may open it, which service is shown, whose emails are counted,
 * the cost settings, and the note for a service that does not report back.
 */
class EmailDeliveryPageTest extends TestCase
{
    use RefreshDatabase;

    private const SES_HOST = 'email-smtp.ap-southeast-1.amazonaws.com';

    private Company $company;

    private StorefrontSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceScheme('https');
        $this->company = Company::query()->create(['name' => 'Delivery Co', 'slug' => 'delivery-co', 'invoice_prefix' => 'DLV', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->setting = StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => ['mail_host' => 'smtp.mx.cloudflare.net', 'mail_port' => 465, 'mail_username' => 'api_token', 'mail_password' => 'x', 'mail_encryption' => 'ssl', 'mail_from_address' => 'info@shop.test'],
        ]);
        Auth::login(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
    }

    public function test_the_page_opens_for_settings_managers_and_not_for_staff_without_settings_access(): void
    {
        $this->assertTrue(EmailDelivery::canAccess());
        $this->actingAs(Auth::user())
            ->withSession(['current_company_id' => $this->company->getKey(), 'current_company_selection_explicit' => true])
            ->get('/admin/settings/email-delivery')
            ->assertOk()
            ->assertSee('Email delivery');

        Auth::login(User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));
        $this->assertFalse(EmailDelivery::canAccess());
    }

    public function test_the_service_is_found_from_the_smtp_host_and_shown_with_its_host_and_from_address(): void
    {
        Livewire::test(EmailDelivery::class)
            ->assertSee('Cloudflare Email Sending')
            ->assertSee('smtp.mx.cloudflare.net:465')
            ->assertSee('info@shop.test')
            ->assertSee('Recognised services')
            ->assertSee('Brevo');

        $this->setHost(self::SES_HOST);
        Livewire::test(EmailDelivery::class)->assertSee('Amazon SES')->assertSee('Open Amazon SES');

        $this->setHost('mail.unknown-host.test');
        Livewire::test(EmailDelivery::class)->assertSee('Other SMTP service')->assertSee('mail.unknown-host.test');

        $this->setHost('');
        Livewire::test(EmailDelivery::class)->assertSee('The server\'s own mailer')->assertSee('No SMTP account is saved for this site');
    }

    public function test_only_this_companys_emails_through_this_services_host_are_counted(): void
    {
        $this->logEmail('sent', times: 2);
        $this->logEmail('failed');
        $this->logEmail('sent', via: 'smtp:'.self::SES_HOST);

        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        $this->logEmail('sent', company: $other);

        $page = Livewire::test(EmailDelivery::class)
            ->assertSee('Email usage')
            ->assertSee('Order emails')
            ->assertSee('Not set');

        $totals = $page->instance()->usage()['totals'];
        $this->assertSame(2, $totals['sent'], 'Another company\'s email and another host are not counted.');
        $this->assertSame(1, $totals['failed']);
    }

    public function test_a_service_that_does_not_report_back_says_so(): void
    {
        $this->logEmail('sent', times: 2);

        Livewire::test(EmailDelivery::class)
            ->assertSee('Cloudflare Email Sending does not report back what became of an email')
            ->assertSee('This service does not report it.')
            ->assertSee('Not available')
            ->assertDontSee('Delivery tracking is off');
    }

    public function test_amazon_ses_with_tracking_off_asks_for_tracking_and_with_it_on_counts_delivered(): void
    {
        $this->setHost(self::SES_HOST);
        $this->logEmail('delivered', times: 2, via: 'smtp:'.self::SES_HOST);

        Livewire::test(EmailDelivery::class)
            ->assertSee('Delivery tracking is off')
            ->assertSee('Turn tracking on to see this.');

        $this->setting->forceFill(['notification_credentials' => array_merge((array) $this->setting->notification_credentials, [
            'ses_tracking_enabled' => true, 'ses_config_set' => 'zz-set',
        ])])->save();

        $page = Livewire::test(EmailDelivery::class)->assertDontSee('Delivery tracking is off')->assertSee('reached the recipient');
        $this->assertTrue($page->instance()->trackingOn());
        $this->assertSame(2, $page->instance()->usage()['totals']['delivered']);
    }

    public function test_the_cost_settings_are_saved_checked_and_cleared(): void
    {
        $this->logEmail('sent', times: 2);
        $this->setting->forceFill(['notification_credentials' => array_merge((array) $this->setting->notification_credentials, [
            'ses_free_limit' => 200, 'ses_price_per_1000' => '0.1',
        ])])->save();

        $page = Livewire::test(EmailDelivery::class)
            ->assertSet('freeLimit', '200')
            ->assertSet('pricePer1000', '0.1', 'The values typed on the Amazon SES page are still read.')
            ->set('freeLimit', '3000')
            ->set('pricePer1000', '0.35')
            ->call('saveCostSettings')
            ->assertHasNoErrors()
            ->assertNotified('Cost settings saved');

        $credentials = $this->setting->fresh()->notification_credentials;
        $this->assertSame(3000, $credentials['email_free_limit']);
        $this->assertSame('0.35', $credentials['email_price_per_1000']);
        $this->assertArrayNotHasKey('ses_free_limit', $credentials, 'The old keys are replaced by the new ones.');
        $this->assertSame('x', $credentials['mail_password'], 'The email account is untouched.');

        $this->assertSame(2998, $page->instance()->usage()['free']['left']);
        $page->assertSee('On track')->assertSee('emails left');

        $page->set('freeLimit', 'many')->set('pricePer1000', '-2')->call('saveCostSettings')
            ->assertHasErrors(['freeLimit', 'pricePer1000']);
        $this->assertSame(3000, $this->setting->fresh()->notification_credentials['email_free_limit'], 'A refused value changes nothing.');

        $page->set('freeLimit', '')->set('pricePer1000', '')->call('saveCostSettings')->assertHasNoErrors();
        $credentials = $this->setting->fresh()->notification_credentials;
        $this->assertArrayNotHasKey('email_free_limit', $credentials);
        $this->assertArrayNotHasKey('email_price_per_1000', $credentials);
    }

    public function test_the_month_can_be_switched_to_last_month_and_no_further(): void
    {
        $page = Livewire::test(EmailDelivery::class)->assertSet('monthsBack', 0)->call('showMonth', 1)->assertSet('monthsBack', 1);
        $page->call('showMonth', 7)->assertSet('monthsBack', 1)->call('showMonth', -3)->assertSet('monthsBack', 0);
    }

    public function test_the_site_of_the_company_can_be_picked_and_a_company_without_a_site_is_told_so(): void
    {
        $nextjs = StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'notification_credentials' => ['mail_host' => 'smtp-relay.brevo.com', 'mail_port' => 587, 'mail_username' => 'u', 'mail_password' => 'p'],
        ]);

        Livewire::test(EmailDelivery::class)
            ->set('data.site_setting_id', $nextjs->getKey())
            ->assertSee('Brevo')
            ->assertSet('settingId', $nextjs->getKey());

        $empty = Company::query()->create(['name' => 'No Site Co', 'slug' => 'no-site-co', 'invoice_prefix' => 'NSC', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        app(CompanyContext::class)->set($empty);

        Livewire::test(EmailDelivery::class)->assertSee('This company has no site yet.');
    }

    public function test_the_page_names_which_provider_each_host_belongs_to(): void
    {
        $names = [
            'email-smtp.eu-west-1.amazonaws.com' => 'Amazon SES',
            'smtp.mx.cloudflare.net' => 'Cloudflare Email Sending',
            'smtp-relay.brevo.com' => 'Brevo',
            'smtp-relay.sendinblue.com' => 'Brevo',
            'in-v3.mailjet.com' => 'Mailjet',
            'smtp.resend.com' => 'Resend',
            'smtp.gmail.com' => 'Gmail',
            'smtp.zeptomail.in' => 'Zoho ZeptoMail',
            'smtp.sendgrid.net' => 'SendGrid',
            'smtp.mailgun.org' => 'Mailgun',
            'smtp.postmarkapp.com' => 'Postmark',
            'SMTP.GMAIL.COM' => 'Gmail',
            'smtp.gmail.com.evil.test' => 'Other SMTP service',
        ];

        foreach ($names as $host => $name) {
            $this->setHost($host);
            $this->assertSame($name, EmailProvider::forSetting($this->setting->fresh())->name, $host);
        }

        $this->setHost(self::SES_HOST);
        $provider = EmailProvider::forSetting($this->setting->fresh());
        $this->assertTrue($provider->isAmazonSes());
        $this->assertTrue($provider->reportsBack);
        $this->assertSame('smtp:'.self::SES_HOST, $provider->via, 'The same text the Email Log writes for this host.');

        $this->setHost('smtp.mx.cloudflare.net');
        $this->assertFalse(EmailProvider::forSetting($this->setting->fresh())->reportsBack);
    }

    public function test_the_usage_styles_live_in_the_admin_theme(): void
    {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        foreach (['.zz-use-ring-fill', '.zz-use-sentbar', '.zz-use-delbar', '.zz-use-stack', '.dark .zz-use', '.zz-use-n[data-label]::before'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }

        $view = (string) file_get_contents(resource_path('views/filament/pages/email-delivery-usage.blade.php'));
        $this->assertStringContainsString('role="table"', $view);
        $this->assertStringContainsString('aria-label', $view);
    }

    private function setHost(string $host): void
    {
        $credentials = (array) $this->setting->notification_credentials;
        $credentials['mail_host'] = $host;
        $this->setting->forceFill(['notification_credentials' => $credentials])->save();
    }

    private function logEmail(string $status, int $times = 1, string $via = 'smtp:smtp.mx.cloudflare.net', ?Company $company = null): void
    {
        for ($i = 0; $i < $times; $i++) {
            DB::table('email_logs')->insert([
                'company_id' => ($company ?? $this->company)->getKey(),
                'kind' => 'order',
                'recipient' => 'buyer@example.test',
                'via' => $via,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
