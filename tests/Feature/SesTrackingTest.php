<?php

namespace Tests\Feature;

use App\Mail\StorefrontLoginOtp;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\StorefrontSetting;
use App\Services\EmailLogService;
use App\Services\Meta\MetaGraphService;
use App\Services\Ses\SesApiException;
use App\Services\Ses\SesEventService;
use App\Services\Ses\SesTrackingService;
use App\Services\StorefrontNotificationService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Tests\Feature\Concerns\FakesAws;
use Tests\TestCase;

/**
 * Amazon SES event tracking (owner request 2026-10-23): switching it on and off
 * from the dashboard, naming the configuration set on outgoing mail, and
 * turning what Amazon reports (through an SNS webhook) into Email Log rows.
 * Amazon is replaced by FakesAws, and SNS signatures are made with a
 * certificate generated here.
 */
class SesTrackingTest extends TestCase
{
    use FakesAws;
    use RefreshDatabase;

    private const CERT_URL = 'https://sns.ap-southeast-1.amazonaws.com/SimpleNotificationService-test.pem';

    private const TOPIC = 'arn:aws:sns:ap-southeast-1:123456789012:zamzam-erp-ses-1-1';

    private Company $company;

    private StorefrontSetting $setting;

    /** @var \OpenSSLAsymmetricKey */
    private $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceScheme('https');
        Cache::flush();

        $this->company = Company::query()->create(['name' => 'Track Co', 'slug' => 'track-co', 'invoice_prefix' => 'TRK', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        $this->setting = StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => [
                'ses_api_key_id' => 'AKIAEXAMPLE', 'ses_api_secret' => 'secret', 'ses_api_region' => 'ap-southeast-1',
                'mail_host' => 'email-smtp.ap-southeast-1.amazonaws.com', 'mail_username' => 'AKIASMTP', 'mail_password' => 'x',
                'mail_from_address' => 'hello@shop.test', 'mail_encryption' => '',
            ],
        ]);
    }

    // --- switching it on and off ---------------------------------------------------------------------------

    public function test_turning_tracking_on_builds_the_topic_the_set_and_the_subscription(): void
    {
        $this->fakeAws([
            ['GetCallerIdentity', $this->awsResult(['Account' => '123456789012'])],
            ['CreateTopic', $this->awsResult(['TopicArn' => self::TOPIC])],
            ['SetTopicAttributes', $this->awsResult()],
            ['CreateConfigurationSet', $this->awsResult()],
            ['CreateConfigurationSetEventDestination', $this->awsResult()],
            ['Subscribe', $this->awsResult(['SubscriptionArn' => 'pending confirmation'])],
        ]);

        app(SesTrackingService::class)->enable($this->setting);

        $this->assertAllAwsCallsMade();
        $set = 'zamzam-erp-'.$this->company->getKey().'-'.$this->setting->getKey();
        $this->assertSame($set, $this->awsCalls[3]['args']['ConfigurationSetName']);

        $policy = json_decode($this->awsCalls[2]['args']['AttributeValue'], true);
        $this->assertSame('ses.amazonaws.com', $policy['Statement'][0]['Principal']['Service']);
        $this->assertSame('123456789012', $policy['Statement'][0]['Condition']['StringEquals']['AWS:SourceAccount']);
        $this->assertSame(self::TOPIC, $policy['Statement'][0]['Resource']);

        $destination = $this->awsCalls[4]['args']['EventDestination'];
        $this->assertSame(['DELIVERY', 'BOUNCE', 'COMPLAINT', 'REJECT'], $destination['MatchingEventTypes']);
        $this->assertSame(self::TOPIC, $destination['SnsDestination']['TopicArn']);

        $this->assertSame('https', $this->awsCalls[5]['args']['Protocol']);
        $this->assertSame(SesTrackingService::webhookUrl($this->setting), $this->awsCalls[5]['args']['Endpoint']);

        $fresh = $this->setting->fresh();
        $this->assertTrue(SesTrackingService::isEnabled($fresh));
        $this->assertSame($set, SesTrackingService::configSetFor($fresh));
        $this->assertSame(self::TOPIC, data_get($fresh->notification_credentials, 'ses_topic_arn'));
        $this->assertSame('', data_get($fresh->notification_credentials, 'mail_encryption'), 'Saving the tracking keys must not touch the SMTP settings (an empty encryption means None).');
        $this->assertSame('AKIAEXAMPLE', data_get($fresh->notification_credentials, 'ses_api_key_id'));
    }

    public function test_turning_it_on_again_reuses_what_already_exists(): void
    {
        $this->fakeAws([
            ['GetCallerIdentity', $this->awsResult(['Account' => '123456789012'])],
            ['CreateTopic', $this->awsResult(['TopicArn' => self::TOPIC])],
            ['SetTopicAttributes', $this->awsResult()],
            ['CreateConfigurationSet', $this->awsError('AlreadyExistsException', 'exists')],
            ['CreateConfigurationSetEventDestination', $this->awsError('AlreadyExistsException', 'exists')],
            ['UpdateConfigurationSetEventDestination', $this->awsResult()],
            ['Subscribe', $this->awsResult(['SubscriptionArn' => 'pending confirmation'])],
        ]);

        app(SesTrackingService::class)->enable($this->setting);

        $this->assertAllAwsCallsMade();
        $this->assertTrue(SesTrackingService::isEnabled($this->setting->fresh()));
    }

    public function test_tracking_needs_a_public_https_address(): void
    {
        URL::forceScheme('http');
        $this->fakeAws([]);

        $this->expectException(SesApiException::class);
        $this->expectExceptionMessage('public https address');
        app(SesTrackingService::class)->enable($this->setting);
    }

    public function test_a_refused_set_up_leaves_tracking_off(): void
    {
        $this->fakeAws([
            ['GetCallerIdentity', $this->awsResult(['Account' => '123456789012'])],
            ['CreateTopic', $this->awsError('AuthorizationError', 'not authorized to perform: sns:CreateTopic')],
        ]);

        try {
            app(SesTrackingService::class)->enable($this->setting);
            $this->fail('A refusal must throw.');
        } catch (SesApiException $exception) {
            $this->assertStringContainsString('sns:CreateTopic', $exception->getMessage());
        }

        $this->assertFalse(SesTrackingService::isEnabled($this->setting->fresh()));
    }

    public function test_turning_tracking_off_deletes_what_was_built_and_forgets_it(): void
    {
        $this->enabled();
        $this->fakeAws([
            ['DeleteConfigurationSet', $this->awsResult()],
            ['DeleteTopic', $this->awsError('NotFoundException', 'gone already')],
        ]);

        app(SesTrackingService::class)->disable($this->setting);

        $this->assertAllAwsCallsMade();
        $fresh = $this->setting->fresh();
        $this->assertFalse(SesTrackingService::isEnabled($fresh));
        $this->assertNull(data_get($fresh->notification_credentials, 'ses_topic_arn'));
        $this->assertNull(data_get($fresh->notification_credentials, 'ses_config_set'));
    }

    // --- outgoing mail -------------------------------------------------------------------------------------

    public function test_mail_through_ses_names_the_configuration_set_only_while_tracking_is_on(): void
    {
        $service = new class(app(MetaGraphService::class)) extends StorefrontNotificationService
        {
            protected function companyMailer(StorefrontSetting $setting): Mailer
            {
                return Mail::mailer('array');
            }
        };

        $service->sendMailable($this->setting, 'buyer@example.test', new StorefrontLoginOtp('Track Co', '123456'));
        $this->enabled();
        $service->sendMailable($this->setting->fresh(), 'buyer@example.test', new StorefrontLoginOtp('Track Co', '123456'));

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(2, $messages);
        $this->assertFalse($messages[0]->getOriginalMessage()->getHeaders()->has('X-SES-CONFIGURATION-SET'));
        $this->assertSame(
            (string) data_get($this->setting->fresh()->notification_credentials, 'ses_config_set'),
            $messages[1]->getOriginalMessage()->getHeaders()->get('X-SES-CONFIGURATION-SET')->getBodyAsString(),
        );
    }

    public function test_the_ses_message_id_is_read_from_the_smtp_answer(): void
    {
        $symfony = new SymfonySentMessage(new RawMessage('x'), new Envelope(new Address('a@b.test'), [new Address('c@d.test')]));
        $symfony->appendDebug("< 250 Ok 0100019a-1111-2222-3333-444455556666-000000\r\n");
        $reader = new class(app(MetaGraphService::class)) extends StorefrontNotificationService
        {
            public function read(mixed $sent): ?string
            {
                return $this->sesMessageId($sent);
            }
        };

        $this->assertSame('0100019a-1111-2222-3333-444455556666-000000', $reader->read(new SentMessage($symfony)));
        $this->assertNull($reader->read(null));
        $this->assertNull($reader->read(new SentMessage(new SymfonySentMessage(new RawMessage('x'), new Envelope(new Address('a@b.test'), [new Address('c@d.test')])))));
    }

    // --- what Amazon reports -------------------------------------------------------------------------------

    public function test_a_hard_bounce_turns_the_sent_row_into_bounced_with_the_reason(): void
    {
        $this->logSent('buyer@example.test', 'msg-1');

        $touched = app(SesEventService::class)->handle($this->setting, $this->bounce('msg-1', 'buyer@example.test', 'Permanent', 'General', 'smtp; 550 5.1.1 user unknown'));

        $this->assertSame(1, $touched);
        $log = EmailLog::query()->sole();
        $this->assertSame(EmailLog::STATUS_BOUNCED, $log->status);
        $this->assertSame(EmailLogService::REASON_BOUNCE_PERMANENT, $log->reason);
        $this->assertStringContainsString('550 5.1.1 user unknown', (string) $log->detail);
    }

    public function test_a_soft_bounce_a_suppressed_address_and_a_complaint_each_get_their_own_reason(): void
    {
        $this->logSent('soft@example.test', 'msg-soft');
        $this->logSent('supp@example.test', 'msg-supp');
        $this->logSent('spam@example.test', 'msg-spam');
        $events = app(SesEventService::class);

        $events->handle($this->setting, $this->bounce('msg-soft', 'soft@example.test', 'Transient', 'MailboxFull'));
        $events->handle($this->setting, $this->bounce('msg-supp', 'supp@example.test', 'Permanent', 'Suppressed'));
        $events->handle($this->setting, ['eventType' => 'Complaint', 'mail' => ['messageId' => 'msg-spam'], 'complaint' => ['complainedRecipients' => [['emailAddress' => 'spam@example.test']], 'complaintFeedbackType' => 'abuse']]);

        $byRecipient = EmailLog::query()->get()->keyBy('recipient');
        $this->assertSame(EmailLogService::REASON_BOUNCE_TRANSIENT, $byRecipient['soft@example.test']->reason);
        $this->assertSame(EmailLogService::REASON_BOUNCE_SUPPRESSED, $byRecipient['supp@example.test']->reason);
        $this->assertSame(EmailLog::STATUS_COMPLAINED, $byRecipient['spam@example.test']->status);
        $this->assertSame(EmailLogService::REASON_COMPLAINT, $byRecipient['spam@example.test']->reason);
    }

    public function test_delivery_marks_the_row_delivered_but_never_undoes_a_bounce(): void
    {
        $this->logSent('ok@example.test', 'msg-ok');
        $this->logSent('bad@example.test', 'msg-bad');
        $events = app(SesEventService::class);

        $events->handle($this->setting, ['eventType' => 'Delivery', 'mail' => ['messageId' => 'msg-ok'], 'delivery' => ['recipients' => ['ok@example.test']]]);
        $events->handle($this->setting, $this->bounce('msg-bad', 'bad@example.test', 'Permanent', 'General'));
        $events->handle($this->setting, ['eventType' => 'Delivery', 'mail' => ['messageId' => 'msg-bad'], 'delivery' => ['recipients' => ['bad@example.test']]]);

        $byRecipient = EmailLog::query()->get()->keyBy('recipient');
        $this->assertSame(EmailLog::STATUS_DELIVERED, $byRecipient['ok@example.test']->status);
        $this->assertSame(EmailLog::STATUS_BOUNCED, $byRecipient['bad@example.test']->status);
    }

    public function test_a_row_without_a_message_id_is_found_by_recipient_and_gets_the_id(): void
    {
        $this->logSent('late@example.test', null);

        app(SesEventService::class)->handle($this->setting, $this->bounce('msg-late', 'LATE@example.test', 'Permanent', 'General'));

        $log = EmailLog::query()->sole();
        $this->assertSame(EmailLog::STATUS_BOUNCED, $log->status);
        $this->assertSame('msg-late', $log->message_id);
    }

    public function test_an_event_for_a_message_the_log_never_saw_becomes_a_row_of_its_own(): void
    {
        app(SesEventService::class)->handle($this->setting, $this->bounce('msg-x', 'stranger@example.test', 'Permanent', 'General'));

        $log = EmailLog::query()->sole();
        $this->assertSame($this->company->getKey(), $log->company_id);
        $this->assertSame(EmailLog::KIND_OTHER, $log->kind);
        $this->assertSame(EmailLog::STATUS_BOUNCED, $log->status);
        $this->assertSame('ses:event', $log->via);
    }

    public function test_an_event_never_touches_another_companys_rows(): void
    {
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        EmailLog::withoutGlobalScopes()->create(['company_id' => $other->getKey(), 'kind' => 'other', 'recipient' => 'buyer@example.test', 'status' => 'sent', 'message_id' => 'msg-1']);

        app(SesEventService::class)->handle($this->setting, $this->bounce('msg-1', 'buyer@example.test', 'Permanent', 'General'));

        $this->assertSame('sent', EmailLog::withoutGlobalScopes()->where('company_id', $other->getKey())->sole()->status);
        $this->assertSame(1, EmailLog::withoutGlobalScopes()->where('company_id', $this->company->getKey())->count());
    }

    // --- the webhook ---------------------------------------------------------------------------------------

    public function test_a_signed_report_from_sns_updates_the_log(): void
    {
        $this->enabled();
        $this->logSent('buyer@example.test', 'msg-1');
        $this->certificateServed();

        $this->postSns($this->sns('Notification', ['Message' => json_encode($this->bounce('msg-1', 'buyer@example.test', 'Permanent', 'General')), 'Subject' => 'Amazon SES Email Event Notification']))
            ->assertOk()
            ->assertJson(['ok' => true, 'recorded' => 1]);

        $this->assertSame(EmailLog::STATUS_BOUNCED, EmailLog::query()->sole()->status);
    }

    public function test_the_older_sha1_signature_version_is_accepted_too(): void
    {
        $this->enabled();
        $this->logSent('buyer@example.test', 'msg-1');
        $this->certificateServed();

        $this->postSns($this->sns('Notification', ['Message' => json_encode(['eventType' => 'Delivery', 'mail' => ['messageId' => 'msg-1'], 'delivery' => ['recipients' => ['buyer@example.test']]])], 1))
            ->assertOk();

        $this->assertSame(EmailLog::STATUS_DELIVERED, EmailLog::query()->sole()->status);
    }

    public function test_a_message_with_a_wrong_signature_or_certificate_is_refused(): void
    {
        $this->enabled();
        $this->logSent('buyer@example.test', 'msg-1');
        $this->certificateServed();
        $message = $this->sns('Notification', ['Message' => json_encode($this->bounce('msg-1', 'buyer@example.test', 'Permanent', 'General'))]);

        $tampered = $message;
        $tampered['Message'] = json_encode($this->bounce('msg-1', 'someone-else@example.test', 'Permanent', 'General'));
        $this->postSns($tampered)->assertForbidden();

        $elsewhere = $message;
        $elsewhere['SigningCertURL'] = 'https://evil.example.test/cert.pem';
        $this->postSns($elsewhere)->assertForbidden();

        $http = $message;
        $http['SigningCertURL'] = 'http://sns.ap-southeast-1.amazonaws.com/cert.pem';
        $this->postSns($http)->assertForbidden();

        $this->assertSame(EmailLog::STATUS_SENT, EmailLog::query()->sole()->status);
    }

    public function test_a_guessed_address_a_foreign_topic_and_garbage_are_refused(): void
    {
        $this->enabled();
        $this->certificateServed();
        $message = $this->sns('Notification', ['Message' => '{}']);

        $this->call('POST', route('webhooks.ses', ['setting' => $this->setting->getKey(), 'token' => 'wrong']), [], [], [], [], json_encode($message))->assertNotFound();
        $this->call('POST', route('webhooks.ses', ['setting' => 99999, 'token' => SesTrackingService::webhookToken($this->setting)]), [], [], [], [], json_encode($message))->assertNotFound();

        $foreign = $this->sns('Notification', ['Message' => '{}', 'TopicArn' => 'arn:aws:sns:ap-southeast-1:999999999999:someone-elses-topic']);
        $this->postSns($foreign)->assertForbidden();

        $this->call('POST', SesTrackingService::webhookUrl($this->setting), [], [], [], [], 'not json')->assertStatus(400);
    }

    public function test_a_subscription_confirmation_is_followed_only_to_an_sns_address(): void
    {
        $this->enabled();
        $this->certificateServed();
        Http::fake(['https://sns.ap-southeast-1.amazonaws.com/?Action=ConfirmSubscription*' => Http::response('<ok/>', 200)] + [self::CERT_URL => Http::response($this->certificatePem(), 200)]);

        $confirm = $this->sns('SubscriptionConfirmation', ['Token' => 'tok', 'SubscribeURL' => 'https://sns.ap-southeast-1.amazonaws.com/?Action=ConfirmSubscription&Token=tok', 'Message' => 'You have chosen to subscribe']);
        $this->postSns($confirm)->assertOk()->assertJson(['confirmed' => true]);

        $this->assertNotEmpty(data_get($this->setting->fresh()->notification_credentials, 'ses_subscription_confirmed_at'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://sns.ap-southeast-1.amazonaws.com/?Action=ConfirmSubscription'));

        $evil = $this->sns('SubscriptionConfirmation', ['Token' => 'tok', 'SubscribeURL' => 'https://evil.example.test/?Action=ConfirmSubscription', 'Message' => 'x']);
        $this->postSns($evil)->assertStatus(400);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.test'));
    }

    // --- helpers -------------------------------------------------------------------------------------------

    private function enabled(): void
    {
        $this->setting->forceFill(['notification_credentials' => array_merge((array) $this->setting->notification_credentials, [
            'ses_tracking_enabled' => true,
            'ses_config_set' => 'zamzam-erp-'.$this->company->getKey().'-'.$this->setting->getKey(),
            'ses_topic_arn' => self::TOPIC,
        ])])->save();
    }

    private function logSent(string $recipient, ?string $messageId): void
    {
        EmailLog::withoutGlobalScopes()->create([
            'company_id' => $this->company->getKey(), 'kind' => EmailLog::KIND_LOGIN_CODE, 'recipient' => $recipient,
            'via' => 'smtp:email-smtp.ap-southeast-1.amazonaws.com', 'status' => EmailLog::STATUS_SENT, 'message_id' => $messageId,
        ]);
    }

    /** @return array<string, mixed> */
    private function bounce(string $messageId, string $recipient, string $type, string $subType, ?string $diagnostic = null): array
    {
        return [
            'eventType' => 'Bounce',
            'mail' => ['messageId' => $messageId, 'destination' => [$recipient]],
            'bounce' => ['bounceType' => $type, 'bounceSubType' => $subType, 'bouncedRecipients' => [['emailAddress' => $recipient, 'diagnosticCode' => $diagnostic]]],
        ];
    }

    /**
     * An SNS message signed with this test's own key (the certificate is served from the Amazon-looking address).
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function sns(string $type, array $fields, int $version = 2): array
    {
        $message = $fields + [
            'Type' => $type,
            'MessageId' => 'sns-'.bin2hex(random_bytes(4)),
            'TopicArn' => self::TOPIC,
            'Timestamp' => '2026-10-23T10:00:00.000Z',
        ];
        $message['Type'] = $type;

        $order = $type === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $toSign = '';

        foreach ($order as $key) {
            if (isset($message[$key])) {
                $toSign .= $key."\n".$message[$key]."\n";
            }
        }

        openssl_sign($toSign, $signature, $this->privateKey(), $version === 2 ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1);

        return $message + [
            'SignatureVersion' => (string) $version,
            'Signature' => base64_encode($signature),
            'SigningCertURL' => self::CERT_URL,
        ];
    }

    /** @param  array<string, mixed>  $message */
    private function postSns(array $message): TestResponse
    {
        return $this->call('POST', SesTrackingService::webhookUrl($this->setting), [], [], [], ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'], json_encode($message));
    }

    private function certificateServed(): void
    {
        Http::fake([self::CERT_URL => Http::response($this->certificatePem(), 200)]);
    }

    private function privateKey()
    {
        return $this->privateKey ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    private function certificatePem(): string
    {
        $key = $this->privateKey();
        $csr = openssl_csr_new(['commonName' => 'sns.ap-southeast-1.amazonaws.com'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($certificate, $pem);

        return $pem;
    }
}
