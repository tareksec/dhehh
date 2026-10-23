<?php

namespace App\Services\Ses;

use App\Models\StorefrontSetting;
use Aws\Exception\AwsException;
use Illuminate\Support\Arr;

/**
 * Switches Amazon SES event tracking on and off from the dashboard (owner
 * request 2026-10-23), so a message that bounces, draws a complaint or is
 * delivered shows up in the Email Log. On: an SNS topic that SES may publish to,
 * an SES configuration set whose events go to that topic, and an https
 * subscription that posts them to the ERP's own webhook (SesWebhookController).
 * The site's emails then carry the configuration set's name in the
 * X-SES-CONFIGURATION-SET header (StorefrontNotificationService). Off: the
 * topic and the set are deleted again. Names carry the company and site, so
 * several companies can share one AWS account.
 */
class SesTrackingService
{
    public const EVENT_DESTINATION = 'zamzam-erp-sns';

    /** The SES events worth keeping: the outcome of every message. */
    public const EVENT_TYPES = ['DELIVERY', 'BOUNCE', 'COMPLAINT', 'REJECT'];

    public function __construct(protected AwsClientFactory $clients) {}

    /** The secret part of the webhook address; derived, so nothing needs storing. */
    public static function webhookToken(StorefrontSetting $setting): string
    {
        return hash_hmac('sha256', 'ses-events|'.$setting->getKey(), (string) config('app.key'));
    }

    public static function webhookUrl(StorefrontSetting $setting): string
    {
        return route('webhooks.ses', ['setting' => $setting->getKey(), 'token' => self::webhookToken($setting)]);
    }

    public static function isEnabled(StorefrontSetting $setting): bool
    {
        return (bool) data_get($setting->notification_credentials, 'ses_tracking_enabled')
            && filled(data_get($setting->notification_credentials, 'ses_config_set'));
    }

    /** The configuration set to name on outgoing mail, or null when tracking is off. */
    public static function configSetFor(StorefrontSetting $setting): ?string
    {
        return self::isEnabled($setting) ? (string) data_get($setting->notification_credentials, 'ses_config_set') : null;
    }

    /**
     * Creates everything and subscribes the webhook. SNS then posts a confirmation to the webhook, which
     * confirms it; until that arrives the subscription is pending (see subscriptionConfirmed()).
     */
    public function enable(StorefrontSetting $setting): void
    {
        $credentials = SesApiService::credentialsFor($setting)
            ?? throw new SesApiException(__('Save the AWS access key ID, secret access key and region first.'));
        $url = self::webhookUrl($setting);

        if (! str_starts_with($url, 'https://')) {
            throw new SesApiException(__('Amazon needs a public https address to send the reports to, and this site is on :url. Open the dashboard from its real https domain and try again.', ['url' => $url]));
        }

        $suffix = $setting->company_id.'-'.$setting->getKey();
        $configSet = 'zamzam-erp-'.$suffix;

        try {
            $account = (string) $this->clients->sts($credentials)->getCallerIdentity()->get('Account');
            $sns = $this->clients->sns($credentials);
            $ses = $this->clients->ses($credentials);

            $topicArn = (string) $sns->createTopic(['Name' => 'zamzam-erp-ses-'.$suffix])->get('TopicArn');

            $sns->setTopicAttributes([
                'TopicArn' => $topicArn,
                'AttributeName' => 'Policy',
                'AttributeValue' => json_encode($this->topicPolicy($topicArn, $account, $credentials['region']), JSON_UNESCAPED_SLASHES),
            ]);

            $this->ignoreExisting(fn () => $ses->createConfigurationSet(['ConfigurationSetName' => $configSet]));

            $destination = [
                'Enabled' => true,
                'MatchingEventTypes' => self::EVENT_TYPES,
                'SnsDestination' => ['TopicArn' => $topicArn],
            ];

            try {
                $ses->createConfigurationSetEventDestination([
                    'ConfigurationSetName' => $configSet,
                    'EventDestinationName' => self::EVENT_DESTINATION,
                    'EventDestination' => $destination,
                ]);
            } catch (AwsException $exception) {
                if (! in_array($exception->getAwsErrorCode(), ['AlreadyExistsException', 'AlreadyExists'], true)) {
                    throw $exception;
                }

                $ses->updateConfigurationSetEventDestination([
                    'ConfigurationSetName' => $configSet,
                    'EventDestinationName' => self::EVENT_DESTINATION,
                    'EventDestination' => $destination,
                ]);
            }

            $sns->subscribe([
                'TopicArn' => $topicArn,
                'Protocol' => 'https',
                'Endpoint' => $url,
                'ReturnSubscriptionArn' => true,
            ]);
        } catch (AwsException $exception) {
            throw SesApiException::fromAws($exception);
        } catch (SesApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw SesApiException::fromAws($exception);
        }

        $this->store($setting, [
            'ses_config_set' => $configSet,
            'ses_topic_arn' => $topicArn,
            'ses_tracking_enabled' => true,
            'ses_subscription_confirmed_at' => null,
        ]);
    }

    /** Deletes the topic and the configuration set (and so the subscription and event destination). */
    public function disable(StorefrontSetting $setting): void
    {
        $credentials = SesApiService::credentialsFor($setting);
        $configSet = (string) data_get($setting->notification_credentials, 'ses_config_set');
        $topicArn = (string) data_get($setting->notification_credentials, 'ses_topic_arn');

        // Tracking is switched off first: outgoing mail stops naming a set that is about to disappear.
        $this->store($setting, ['ses_tracking_enabled' => false]);

        if ($credentials !== null) {
            try {
                if ($configSet !== '') {
                    $this->ignoreMissing(fn () => $this->clients->ses($credentials)->deleteConfigurationSet(['ConfigurationSetName' => $configSet]));
                }

                if ($topicArn !== '') {
                    $this->ignoreMissing(fn () => $this->clients->sns($credentials)->deleteTopic(['TopicArn' => $topicArn]));
                }
            } catch (AwsException $exception) {
                throw SesApiException::fromAws($exception);
            }
        }

        $this->store($setting, ['ses_config_set' => null, 'ses_topic_arn' => null, 'ses_subscription_confirmed_at' => null]);
    }

    /** True once SNS has confirmed the webhook's subscription (asked of AWS, so it is the live answer). */
    public function subscriptionConfirmed(StorefrontSetting $setting): bool
    {
        $credentials = SesApiService::credentialsFor($setting);
        $topicArn = (string) data_get($setting->notification_credentials, 'ses_topic_arn');

        if ($credentials === null || $topicArn === '') {
            return false;
        }

        try {
            $subscriptions = (array) $this->clients->sns($credentials)->listSubscriptionsByTopic(['TopicArn' => $topicArn])->get('Subscriptions');
        } catch (AwsException $exception) {
            throw SesApiException::fromAws($exception);
        }

        return collect($subscriptions)->contains(fn (array $subscription): bool => ($subscription['Endpoint'] ?? null) === self::webhookUrl($setting)
            && str_starts_with((string) ($subscription['SubscriptionArn'] ?? ''), 'arn:'));
    }

    /** Remembers (from the webhook) that SNS confirmed the subscription. */
    public function markConfirmed(StorefrontSetting $setting): void
    {
        $this->store($setting, ['ses_subscription_confirmed_at' => now()->toIso8601String()]);
    }

    /** @param  array<string, mixed>  $values */
    protected function store(StorefrontSetting $setting, array $values): void
    {
        $fresh = StorefrontSetting::withoutGlobalScopes()->find($setting->getKey()) ?? $setting;
        $credentials = array_merge((array) $fresh->notification_credentials, $values);
        $credentials = Arr::where($credentials, fn ($value) => $value !== null);

        $fresh->forceFill(['notification_credentials' => $credentials])->save();
        $setting->setRawAttributes($fresh->getAttributes(), true);
    }

    /** @return array<string, mixed> the policy that lets Amazon SES (this account only) publish to the topic */
    protected function topicPolicy(string $topicArn, string $account, string $region): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [[
                'Sid' => 'AllowSesToPublish',
                'Effect' => 'Allow',
                'Principal' => ['Service' => 'ses.amazonaws.com'],
                'Action' => 'SNS:Publish',
                'Resource' => $topicArn,
                'Condition' => [
                    'StringEquals' => ['AWS:SourceAccount' => $account],
                    'StringLike' => ['AWS:SourceArn' => "arn:aws:ses:{$region}:{$account}:*"],
                ],
            ]],
        ];
    }

    protected function ignoreExisting(callable $callback): void
    {
        try {
            $callback();
        } catch (AwsException $exception) {
            if (! in_array($exception->getAwsErrorCode(), ['AlreadyExistsException', 'AlreadyExists'], true)) {
                throw $exception;
            }
        }
    }

    protected function ignoreMissing(callable $callback): void
    {
        try {
            $callback();
        } catch (AwsException $exception) {
            if (! in_array($exception->getAwsErrorCode(), ['NotFoundException', 'NotFound'], true)) {
                throw $exception;
            }
        }
    }
}
