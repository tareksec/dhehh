<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Services\Ses\SesApiException;
use App\Services\Ses\SesApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\FakesAws;
use Tests\TestCase;

/**
 * Controlling Amazon SES through its API (owner request 2026-10-23). Amazon is
 * replaced by a queue of answers (FakesAws), so these tests check what we ask
 * for and how the answer is read.
 */
class SesApiServiceTest extends TestCase
{
    use FakesAws;
    use RefreshDatabase;

    private StorefrontSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::query()->create(['name' => 'Ses Co', 'slug' => 'ses-co', 'invoice_prefix' => 'SES', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        $this->setting = StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => ['ses_api_key_id' => 'AKIAEXAMPLE', 'ses_api_secret' => 'secret', 'ses_api_region' => 'ap-southeast-1'],
        ]);
    }

    public function test_the_region_comes_from_the_saved_value_else_from_the_smtp_host(): void
    {
        $this->assertSame('ap-southeast-1', SesApiService::regionFor($this->setting));

        $this->setting->forceFill(['notification_credentials' => ['mail_host' => 'email-smtp.eu-west-2.amazonaws.com']])->save();
        $this->assertSame('eu-west-2', SesApiService::regionFor($this->setting));
        $this->assertNull(SesApiService::credentialsFor($this->setting), 'No key saved, so no credentials.');

        $this->setting->forceFill(['notification_credentials' => ['mail_host' => 'smtp.example.test']])->save();
        $this->assertNull(SesApiService::regionFor($this->setting));
    }

    public function test_the_account_state_is_read(): void
    {
        $this->fakeAws([['GetAccount', $this->awsResult([
            'ProductionAccessEnabled' => false,
            'SendingEnabled' => true,
            'EnforcementStatus' => 'HEALTHY',
            'SendQuota' => ['Max24HourSend' => 200.0, 'MaxSendRate' => 1.0, 'SentLast24Hours' => 12.0],
            'SuppressionAttributes' => ['SuppressedReasons' => ['BOUNCE', 'COMPLAINT']],
        ])]]);

        $account = app(SesApiService::class)->account($this->setting);

        $this->assertTrue($account['sandbox']);
        $this->assertTrue($account['sending_enabled']);
        $this->assertSame('HEALTHY', $account['enforcement']);
        $this->assertSame(200.0, $account['max_24h']);
        $this->assertSame(12.0, $account['sent_24h']);
        $this->assertSame(['BOUNCE', 'COMPLAINT'], $account['suppressed_reasons']);
        $this->assertAllAwsCallsMade();
    }

    public function test_a_domain_comes_with_the_dns_records_to_publish(): void
    {
        $this->fakeAws([
            ['ListEmailIdentities', $this->awsResult(['EmailIdentities' => [
                ['IdentityType' => 'DOMAIN', 'IdentityName' => 'shop.example', 'SendingEnabled' => false],
                ['IdentityType' => 'EMAIL_ADDRESS', 'IdentityName' => 'me@shop.example', 'SendingEnabled' => true],
            ]])],
            ['GetEmailIdentity', $this->awsResult(['VerificationStatus' => 'PENDING', 'DkimAttributes' => ['Status' => 'PENDING', 'Tokens' => ['tok1', 'tok2', 'tok3']]])],
            ['GetEmailIdentity', $this->awsResult(['VerificationStatus' => 'SUCCESS', 'DkimAttributes' => ['Status' => 'NOT_STARTED', 'Tokens' => []]])],
        ]);

        $identities = app(SesApiService::class)->identities($this->setting);

        $this->assertCount(2, $identities);
        $this->assertSame('shop.example', $identities[0]['name']);
        $this->assertSame('PENDING', $identities[0]['verification']);
        $this->assertSame(
            ['type' => 'CNAME', 'name' => 'tok1._domainkey.shop.example', 'value' => 'tok1.dkim.amazonses.com'],
            $identities[0]['dns_records'][0],
        );
        $this->assertCount(3, $identities[0]['dns_records']);
        $this->assertSame('SUCCESS', $identities[1]['verification']);
        $this->assertSame([], $identities[1]['dns_records'], 'An address needs no DNS records.');
    }

    public function test_adding_an_identity_cleans_the_name_and_returns_its_records(): void
    {
        $this->fakeAws([
            ['CreateEmailIdentity', $this->awsResult(['IdentityType' => 'DOMAIN', 'DkimAttributes' => ['Tokens' => ['a', 'b', 'c']]])],
            ['GetEmailIdentity', $this->awsResult(['VerificationStatus' => 'PENDING', 'DkimAttributes' => ['Status' => 'PENDING', 'Tokens' => ['a', 'b', 'c']]])],
        ]);

        $row = app(SesApiService::class)->addIdentity($this->setting, '  HTTPS://Shop.Example/ ');

        $this->assertSame('shop.example', $row['name']);
        $this->assertSame('shop.example', $this->awsCalls[0]['args']['EmailIdentity']);
        $this->assertSame('a._domainkey.shop.example', $row['dns_records'][0]['name']);
    }

    public function test_the_suppression_list_is_read_and_changed(): void
    {
        $this->fakeAws([
            ['ListSuppressedDestinations', $this->awsResult(['SuppressedDestinationSummaries' => [
                ['EmailAddress' => 'bad@example.test', 'Reason' => 'BOUNCE', 'LastUpdateTime' => new \DateTimeImmutable('2026-10-20 10:30:00')],
            ]])],
            ['DeleteSuppressedDestination', $this->awsResult()],
            ['PutSuppressedDestination', $this->awsResult()],
        ]);
        $service = app(SesApiService::class);

        $rows = $service->suppressed($this->setting);
        $service->removeSuppressed($this->setting, ' bad@example.test ');
        $service->addSuppressed($this->setting, 'spam@example.test', 'COMPLAINT');

        $this->assertSame([['email' => 'bad@example.test', 'reason' => 'BOUNCE', 'since' => '2026-10-20 10:30']], $rows);
        $this->assertSame('bad@example.test', $this->awsCalls[1]['args']['EmailAddress']);
        $this->assertSame('COMPLAINT', $this->awsCalls[2]['args']['Reason']);
        $this->assertAllAwsCallsMade();
    }

    public function test_amazons_refusals_become_plain_messages(): void
    {
        $this->fakeAws([['GetAccount', $this->awsError('AccessDeniedException', 'User is not authorized to perform: ses:GetAccount')]]);

        try {
            app(SesApiService::class)->account($this->setting);
            $this->fail('A refusal must throw.');
        } catch (SesApiException $exception) {
            $this->assertStringContainsString('not allowed to do that', $exception->getMessage());
            $this->assertStringContainsString('ses:GetAccount', $exception->getMessage());
        }

        $this->fakeAws([['GetAccount', $this->awsError('SignatureDoesNotMatch', 'The request signature we calculated does not match')]]);
        $this->expectException(SesApiException::class);
        $this->expectExceptionMessage('refused the secret access key');
        app(SesApiService::class)->account($this->setting);
    }

    public function test_without_a_saved_key_nothing_is_asked_of_amazon(): void
    {
        $this->setting->forceFill(['notification_credentials' => ['mail_host' => 'email-smtp.ap-southeast-1.amazonaws.com']])->save();

        $this->expectException(SesApiException::class);
        $this->expectExceptionMessage('Save the AWS access key ID');
        app(SesApiService::class)->account($this->setting);
    }

    public function test_an_existing_identity_gets_a_clear_message(): void
    {
        $this->fakeAws([['CreateEmailIdentity', $this->awsError('AlreadyExistsException', 'exists')]]);

        $this->expectException(SesApiException::class);
        $this->expectExceptionMessage('already in the account');
        app(SesApiService::class)->addIdentity($this->setting, 'shop.example');
    }
}
