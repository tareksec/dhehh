<?php

namespace Tests\Feature;

use App\Filament\Pages\AmazonSes;
use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\Ses\SesTrackingService;
use App\Services\StorefrontNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Feature\Concerns\FakesAws;
use Tests\TestCase;

/**
 * Settings → Amazon SES (owner request 2026-10-23): who may open it, saving the
 * AWS key (the secret is never sent back to the browser), reading the account,
 * and the actions that change Amazon SES.
 */
class AmazonSesPageTest extends TestCase
{
    use FakesAws;
    use RefreshDatabase;

    private Company $company;

    private StorefrontSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceScheme('https');
        $this->company = Company::query()->create(['name' => 'Page Co', 'slug' => 'page-co', 'invoice_prefix' => 'PGE', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        $this->setting = StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => ['mail_host' => 'email-smtp.ap-southeast-1.amazonaws.com', 'mail_username' => 'AKIASMTP', 'mail_password' => 'x', 'mail_encryption' => '', 'mail_from_address' => 'hello@shop.test'],
        ]);
        Auth::login(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
    }

    public function test_the_page_opens_for_a_super_admin_and_not_for_staff_without_settings_access(): void
    {
        $this->assertTrue(AmazonSes::canAccess());
        $this->actingAs(Auth::user())
            ->withSession(['current_company_id' => $this->company->getKey(), 'current_company_selection_explicit' => true])
            ->get('/admin/settings/amazon-ses')
            ->assertOk()
            ->assertSee('Amazon SES');

        Auth::login(User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));
        $this->assertFalse(AmazonSes::canAccess());
    }

    public function test_saving_the_key_keeps_the_secret_server_side_and_the_smtp_settings_untouched(): void
    {
        $this->fakeAws([]);

        Livewire::test(AmazonSes::class)
            ->fillForm(['ses_api_key_id' => ' AKIADASH ', 'ses_api_secret' => 'top-secret-value', 'ses_api_region' => 'AP-Southeast-1'])
            ->call('saveCredentials')
            ->assertHasNoFormErrors();

        $credentials = $this->setting->fresh()->notification_credentials;
        $this->assertSame('AKIADASH', $credentials['ses_api_key_id']);
        $this->assertSame('top-secret-value', $credentials['ses_api_secret']);
        $this->assertSame('ap-southeast-1', $credentials['ses_api_region']);
        $this->assertSame('', $credentials['mail_encryption'], 'An empty SMTP encryption means None and must stay.');
        $this->assertSame('x', $credentials['mail_password']);

        // Opening the page again never fills the secret back into the browser, and an empty box keeps it.
        $page = Livewire::test(AmazonSes::class);
        $this->assertNull($page->get('data.ses_api_secret'));
        $page->assertDontSee('top-secret-value')
            ->fillForm(['ses_api_key_id' => 'AKIADASH2', 'ses_api_secret' => '', 'ses_api_region' => ''])
            ->call('saveCredentials');

        $credentials = $this->setting->fresh()->notification_credentials;
        $this->assertSame('top-secret-value', $credentials['ses_api_secret']);
        $this->assertSame('AKIADASH2', $credentials['ses_api_key_id']);
        $this->assertArrayNotHasKey('ses_api_region', $credentials);
    }

    public function test_the_overview_reads_the_account_the_domains_and_the_suppression_list(): void
    {
        $this->saveKey();
        $this->fakeAws([
            ['GetAccount', $this->awsResult(['ProductionAccessEnabled' => false, 'SendingEnabled' => true, 'EnforcementStatus' => 'HEALTHY', 'SendQuota' => ['Max24HourSend' => 200.0, 'MaxSendRate' => 1.0, 'SentLast24Hours' => 5.0]])],
            ['ListEmailIdentities', $this->awsResult(['EmailIdentities' => [['IdentityType' => 'DOMAIN', 'IdentityName' => 'shop.test', 'SendingEnabled' => false]]])],
            ['GetEmailIdentity', $this->awsResult(['VerificationStatus' => 'PENDING', 'DkimAttributes' => ['Status' => 'PENDING', 'Tokens' => ['t1', 't2', 't3']]])],
            ['ListSuppressedDestinations', $this->awsResult(['SuppressedDestinationSummaries' => [['EmailAddress' => 'bad@example.test', 'Reason' => 'BOUNCE', 'LastUpdateTime' => new \DateTimeImmutable('2026-10-20 10:30:00')]]])],
        ]);

        Livewire::test(AmazonSes::class)
            ->call('refresh')
            ->assertSee('Sandbox')
            ->assertSee('shop.test')
            ->assertSee('t1._domainkey.shop.test')
            ->assertSee('t1.dkim.amazonses.com')
            ->assertSee('bad@example.test')
            ->assertSee('is not verified in Amazon SES yet');
    }

    public function test_one_missing_permission_does_not_hide_the_rest(): void
    {
        $this->saveKey();
        $this->fakeAws([
            ['GetAccount', $this->awsResult(['ProductionAccessEnabled' => true, 'SendingEnabled' => true, 'EnforcementStatus' => 'HEALTHY', 'SendQuota' => ['Max24HourSend' => 50000.0, 'MaxSendRate' => 14.0, 'SentLast24Hours' => 0.0]])],
            ['ListEmailIdentities', $this->awsError('AccessDeniedException', 'not authorized to perform: ses:ListEmailIdentities')],
            ['ListSuppressedDestinations', $this->awsResult(['SuppressedDestinationSummaries' => []])],
        ]);

        Livewire::test(AmazonSes::class)
            ->call('refresh')
            ->assertSee('Production')
            ->assertSee('ses:ListEmailIdentities')
            ->assertSee('No address is blocked');
    }

    public function test_a_key_amazon_does_not_accept_is_reported_once(): void
    {
        $this->saveKey();
        $this->fakeAws([
            ['GetAccount', $this->awsError('InvalidClientTokenId', 'bad')],
            ['ListEmailIdentities', $this->awsError('InvalidClientTokenId', 'bad')],
            ['ListSuppressedDestinations', $this->awsError('InvalidClientTokenId', 'bad')],
        ]);

        $html = Livewire::test(AmazonSes::class)->call('refresh')->html();

        $this->assertSame(1, substr_count($html, 'does not recognise the access key ID'));
        $this->assertStringNotContainsString('Domains and addresses that may send', $html);
    }

    public function test_adding_a_domain_and_removing_one_go_to_amazon(): void
    {
        $this->saveKey();

        $this->fakeAws([
            ['CreateEmailIdentity', $this->awsResult(['IdentityType' => 'DOMAIN', 'DkimAttributes' => ['Tokens' => ['a', 'b', 'c']]])],
            ['GetEmailIdentity', $this->awsResult(['VerificationStatus' => 'PENDING', 'DkimAttributes' => ['Status' => 'PENDING', 'Tokens' => ['a', 'b', 'c']]])],
            ...$this->refreshSteps(),
            ['DeleteEmailIdentity', $this->awsResult()],
            ...$this->refreshSteps(),
        ]);

        Livewire::test(AmazonSes::class)
            ->callAction('addIdentity', ['identity' => 'Shop.Test'])
            ->assertNotified('Added to Amazon SES')
            ->callAction('deleteIdentity', arguments: ['identity' => 'shop.test'])
            ->assertNotified('Removed from Amazon SES');

        $this->assertSame('shop.test', $this->awsCalls[0]['args']['EmailIdentity']);
        $this->assertAllAwsCallsMade();
    }

    /** One AWS account, several companies: this company's domains first, the others' below and not removable by company staff. */
    public function test_domains_of_other_companies_on_the_same_account_are_told_apart_and_protected(): void
    {
        $this->saveKey();
        $this->setting->company->forceFill(['domain' => 'offer.shop.test'])->save();
        $identities = [
            ['IdentityType' => 'DOMAIN', 'IdentityName' => 'otherco.example', 'SendingEnabled' => true],
            ['IdentityType' => 'DOMAIN', 'IdentityName' => 'shop.test', 'SendingEnabled' => true],
            ['IdentityType' => 'EMAIL_ADDRESS', 'IdentityName' => 'hello@shop.test', 'SendingEnabled' => true],
        ];
        $verified = fn () => $this->awsResult(['VerificationStatus' => 'SUCCESS', 'DkimAttributes' => ['Status' => 'SUCCESS', 'Tokens' => []]]);
        $refresh = [
            ['GetAccount', $this->awsResult(['ProductionAccessEnabled' => true, 'SendingEnabled' => true, 'EnforcementStatus' => 'HEALTHY', 'SendQuota' => ['Max24HourSend' => 1.0, 'MaxSendRate' => 1.0, 'SentLast24Hours' => 0.0]])],
            ['ListEmailIdentities', $this->awsResult(['EmailIdentities' => $identities])],
            ['GetEmailIdentity', $verified()], ['GetEmailIdentity', $verified()], ['GetEmailIdentity', $verified()],
            ['ListSuppressedDestinations', $this->awsResult(['SuppressedDestinationSummaries' => []])],
        ];

        // A settings manager who is not a super admin.
        UserRole::query()->create(['name' => 'Ops', 'slug' => 'ops_manager', 'permissions' => ['settings.manage'], 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'ops_manager', 'is_active' => true]);
        $manager->companies()->attach($this->company, ['role' => 'ops_manager', 'is_default' => true]);
        Auth::login($manager);

        $this->fakeAws([...$refresh]);
        $page = Livewire::test(AmazonSes::class)->call('refresh');
        $rows = collect($page->get('overview')['identities'])->pluck('own', 'name')->all();

        $this->assertEquals(['shop.test' => true, 'hello@shop.test' => true, 'otherco.example' => false], $rows);
        $page->assertSee('used by other companies')->assertSee('otherco.example');
        $this->assertFalse($page->instance()->canRemoveOtherDomains());

        // Crafted request: the other company's domain is refused without any AWS call.
        $this->fakeAws([]);
        $page->callAction('deleteIdentity', arguments: ['identity' => 'otherco.example'])
            ->assertNotified('Could not remove it');
        $this->assertAllAwsCallsMade();

        // Its own domain goes through.
        $this->fakeAws([['DeleteEmailIdentity', $this->awsResult()], ...$refresh]);
        $page->callAction('deleteIdentity', arguments: ['identity' => 'shop.test'])->assertNotified('Removed from Amazon SES');
        $this->assertSame('shop.test', $this->awsCalls[0]['args']['EmailIdentity']);

        // A super admin may remove any.
        Auth::login(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $this->fakeAws([['DeleteEmailIdentity', $this->awsResult()], ...$refresh]);
        $admin = Livewire::test(AmazonSes::class);
        $this->assertTrue($admin->instance()->canRemoveOtherDomains());
        $admin->callAction('deleteIdentity', arguments: ['identity' => 'otherco.example'])->assertNotified('Removed from Amazon SES');
        $this->assertSame('otherco.example', $this->awsCalls[0]['args']['EmailIdentity']);
    }

    public function test_a_domain_name_that_is_not_one_is_not_sent_to_amazon(): void
    {
        $this->saveKey();
        $this->fakeAws([]);

        Livewire::test(AmazonSes::class)
            ->callAction('addIdentity', ['identity' => 'not a domain'])
            ->assertHasActionErrors(['identity']);

        $this->assertAllAwsCallsMade();
    }

    public function test_an_address_can_be_allowed_again_and_one_can_be_blocked(): void
    {
        $this->saveKey();
        $this->fakeAws([
            ['DeleteSuppressedDestination', $this->awsResult()],
            ...$this->refreshSteps(),
            ['PutSuppressedDestination', $this->awsResult()],
            ...$this->refreshSteps(),
        ]);

        Livewire::test(AmazonSes::class)
            ->callAction('removeSuppressed', arguments: ['email' => 'bad@example.test'])
            ->assertNotified('The address can receive email again')
            ->callAction('addSuppressed', ['email' => 'spam@example.test'])
            ->assertNotified('The address is blocked');

        $this->assertSame('bad@example.test', $this->awsCalls[0]['args']['EmailAddress']);
        $this->assertSame('PutSuppressedDestination', $this->awsCalls[4]['name']);
        $this->assertSame('spam@example.test', $this->awsCalls[4]['args']['EmailAddress']);
        $this->assertAllAwsCallsMade();
    }

    public function test_tracking_is_switched_on_and_off_from_the_page(): void
    {
        $this->saveKey();
        $topic = 'arn:aws:sns:ap-southeast-1:123456789012:zamzam-erp-ses-1-1';

        $this->fakeAws([
            ['GetCallerIdentity', $this->awsResult(['Account' => '123456789012'])],
            ['CreateTopic', $this->awsResult(['TopicArn' => $topic])],
            ['SetTopicAttributes', $this->awsResult()],
            ['CreateConfigurationSet', $this->awsResult()],
            ['CreateConfigurationSetEventDestination', $this->awsResult()],
            ['Subscribe', $this->awsResult(['SubscriptionArn' => 'pending confirmation'])],
            ...$this->refreshSteps(),
            ['ListSubscriptionsByTopic', $this->awsResult(['Subscriptions' => [['Endpoint' => SesTrackingService::webhookUrl($this->setting), 'SubscriptionArn' => 'PendingConfirmation']]])],
            ...$this->refreshSteps(),
            ['ListSubscriptionsByTopic', $this->awsResult(['Subscriptions' => [['Endpoint' => SesTrackingService::webhookUrl($this->setting), 'SubscriptionArn' => $topic.':abc']]])],
            ['DeleteConfigurationSet', $this->awsResult()],
            ['DeleteTopic', $this->awsResult()],
            ...$this->refreshSteps(),
        ]);

        Livewire::test(AmazonSes::class)
            ->callAction('enableTracking')
            ->assertNotified('Tracking is on')
            ->assertSee('On, waiting for Amazon to confirm')
            ->call('refresh')
            ->assertSee('On and connected')
            ->callAction('disableTracking')
            ->assertNotified('Tracking is off');

        $this->assertFalse(SesTrackingService::isEnabled($this->setting->fresh()));
        $this->assertAllAwsCallsMade();
    }

    public function test_a_failed_amazon_call_is_shown_not_thrown(): void
    {
        $this->saveKey();
        $this->fakeAws([['GetCallerIdentity', $this->awsError('InvalidClientTokenId', 'bad')]]);

        Livewire::test(AmazonSes::class)
            ->callAction('enableTracking')
            ->assertNotified('Could not turn tracking on');

        $this->assertFalse(SesTrackingService::isEnabled($this->setting->fresh()));
    }

    public function test_the_key_cannot_be_removed_while_tracking_is_on(): void
    {
        $this->saveKey(['ses_tracking_enabled' => true, 'ses_config_set' => 'x', 'ses_topic_arn' => 'arn:aws:sns:ap-southeast-1:1:x']);
        $this->fakeAws([]);

        Livewire::test(AmazonSes::class)->callAction('forgetKey')->assertNotified('Turn tracking off first');

        $this->assertSame('AKIADASH', $this->setting->fresh()->notification_credentials['ses_api_key_id']);
    }

    public function test_removing_the_key_keeps_the_email_settings(): void
    {
        $this->saveKey();

        Livewire::test(AmazonSes::class)->callAction('forgetKey')->assertNotified('AWS key removed');

        $credentials = $this->setting->fresh()->notification_credentials;
        $this->assertArrayNotHasKey('ses_api_key_id', $credentials);
        $this->assertArrayNotHasKey('ses_api_secret', $credentials);
        $this->assertSame('AKIASMTP', $credentials['mail_username']);
    }

    public function test_saving_storefront_settings_keeps_the_aws_key(): void
    {
        $this->saveKey();
        $keys = StorefrontNotificationService::SES_CREDENTIAL_KEYS;

        $this->assertContains('ses_api_secret', $keys);
        $this->assertContains('ses_topic_arn', $keys);
        $source = (string) file_get_contents(app_path('Filament/Resources/StorefrontSettings/Pages/EditStorefrontSetting.php'));
        $this->assertStringContainsString('SES_CREDENTIAL_KEYS', $source, 'The Storefront Settings save must carry the AWS key over.');
    }

    /** @param  array<string, mixed>  $extra */
    private function saveKey(array $extra = []): void
    {
        $this->setting->forceFill(['notification_credentials' => array_merge((array) $this->setting->notification_credentials, [
            'ses_api_key_id' => 'AKIADASH', 'ses_api_secret' => 'secret', 'ses_api_region' => 'ap-southeast-1',
        ], $extra)])->save();
    }

    /** The calls a refresh makes with an empty account. @return array<int, array{0: string, 1: mixed}> */
    private function refreshSteps(): array
    {
        return [
            ['GetAccount', $this->awsResult(['ProductionAccessEnabled' => true, 'SendingEnabled' => true, 'EnforcementStatus' => 'HEALTHY', 'SendQuota' => ['Max24HourSend' => 1.0, 'MaxSendRate' => 1.0, 'SentLast24Hours' => 0.0]])],
            ['ListEmailIdentities', $this->awsResult(['EmailIdentities' => []])],
            ['ListSuppressedDestinations', $this->awsResult(['SuppressedDestinationSummaries' => []])],
        ];
    }
}
