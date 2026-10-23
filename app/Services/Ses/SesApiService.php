<?php

namespace App\Services\Ses;

use App\Models\StorefrontSetting;
use Aws\Exception\AwsException;
use Illuminate\Support\Arr;

/**
 * Controls Amazon SES from the dashboard through its API (owner request
 * 2026-10-23): the account's state (sandbox or production, sending quota), the
 * domains and addresses that may send, and the suppression list. The AWS key is
 * saved per site in the encrypted `notification_credentials`, apart from the
 * SMTP account the emails go through: an SMTP password cannot be turned back
 * into an API key. Every method throws SesApiException with a message the owner
 * can act on.
 */
class SesApiService
{
    /** An Amazon SES SMTP endpoint; the first group is the AWS region. */
    public const SMTP_HOST_PATTERN = '/^email-smtp\.([a-z0-9-]+)\.amazonaws\.com$/i';

    public function __construct(protected AwsClientFactory $clients) {}

    /**
     * The AWS region of the site: the one saved with the API key, else the one in the SMTP host.
     */
    public static function regionFor(StorefrontSetting $setting): ?string
    {
        $region = trim((string) data_get($setting->notification_credentials, 'ses_api_region'));

        if ($region === '' && preg_match(self::SMTP_HOST_PATTERN, (string) data_get($setting->notification_credentials, 'mail_host'), $match) === 1) {
            $region = $match[1];
        }

        return $region === '' ? null : strtolower($region);
    }

    /** @return array{key: string, secret: string, region: string}|null null until key, secret and region are all known */
    public static function credentialsFor(StorefrontSetting $setting): ?array
    {
        $key = trim((string) data_get($setting->notification_credentials, 'ses_api_key_id'));
        $secret = trim((string) data_get($setting->notification_credentials, 'ses_api_secret'));
        $region = self::regionFor($setting);

        return $key !== '' && $secret !== '' && $region !== null
            ? ['key' => $key, 'secret' => $secret, 'region' => $region]
            : null;
    }

    public function configured(StorefrontSetting $setting): bool
    {
        return self::credentialsFor($setting) !== null;
    }

    /**
     * @return array{sandbox: bool, sending_enabled: bool, enforcement: string, max_24h: float, sent_24h: float, max_rate: float, suppressed_reasons: array<int, string>}
     */
    public function account(StorefrontSetting $setting): array
    {
        $account = $this->call($setting, fn () => $this->clients->ses($this->credentials($setting))->getAccount()->toArray());

        return [
            'sandbox' => ! (bool) ($account['ProductionAccessEnabled'] ?? false),
            'sending_enabled' => (bool) ($account['SendingEnabled'] ?? false),
            'enforcement' => (string) ($account['EnforcementStatus'] ?? ''),
            'max_24h' => (float) data_get($account, 'SendQuota.Max24HourSend', 0),
            'sent_24h' => (float) data_get($account, 'SendQuota.SentLast24Hours', 0),
            'max_rate' => (float) data_get($account, 'SendQuota.MaxSendRate', 0),
            'suppressed_reasons' => array_values((array) data_get($account, 'SuppressionAttributes.SuppressedReasons', [])),
        ];
    }

    /**
     * The domains and addresses of the account that may send, with their verification and DKIM state.
     *
     * @return array<int, array{name: string, type: string, sending_enabled: bool, verification: string, dkim: string, dns_records: array<int, array{type: string, name: string, value: string}>}>
     */
    public function identities(StorefrontSetting $setting): array
    {
        return $this->call($setting, function () use ($setting): array {
            $client = $this->clients->ses($this->credentials($setting));
            $rows = [];
            $token = null;

            do {
                $page = $client->listEmailIdentities(array_filter(['PageSize' => 100, 'NextToken' => $token]))->toArray();

                foreach ((array) ($page['EmailIdentities'] ?? []) as $identity) {
                    $rows[] = $this->identityRow($client->getEmailIdentity(['EmailIdentity' => $identity['IdentityName']])->toArray(), $identity);
                }

                $token = $page['NextToken'] ?? null;
            } while ($token && count($rows) < 100);

            return $rows;
        });
    }

    /**
     * Adds a domain (or an email address) to the account. A domain comes back with the DKIM records to add in its
     * DNS; an address makes SES email the owner of the address a verification link.
     *
     * @return array{name: string, type: string, sending_enabled: bool, verification: string, dkim: string, dns_records: array<int, array{type: string, name: string, value: string}>}
     */
    public function addIdentity(StorefrontSetting $setting, string $name): array
    {
        $name = $this->normalizeIdentity($name);

        return $this->call($setting, function () use ($setting, $name): array {
            $client = $this->clients->ses($this->credentials($setting));
            $created = $client->createEmailIdentity(['EmailIdentity' => $name])->toArray();

            return $this->identityRow(
                $client->getEmailIdentity(['EmailIdentity' => $name])->toArray() + $created,
                ['IdentityName' => $name, 'IdentityType' => $created['IdentityType'] ?? null],
            );
        });
    }

    public function deleteIdentity(StorefrontSetting $setting, string $name): void
    {
        $this->call($setting, fn () => $this->clients->ses($this->credentials($setting))->deleteEmailIdentity(['EmailIdentity' => $name]));
    }

    /**
     * Addresses SES will not send to because an earlier message bounced or drew a complaint.
     *
     * @return array<int, array{email: string, reason: string, since: string}>
     */
    public function suppressed(StorefrontSetting $setting): array
    {
        return $this->call($setting, function () use ($setting): array {
            $client = $this->clients->ses($this->credentials($setting));
            $rows = [];
            $token = null;

            do {
                $page = $client->listSuppressedDestinations(array_filter(['PageSize' => 100, 'NextToken' => $token]))->toArray();

                foreach ((array) ($page['SuppressedDestinationSummaries'] ?? []) as $item) {
                    $since = $item['LastUpdateTime'] ?? null;
                    $rows[] = [
                        'email' => (string) $item['EmailAddress'],
                        'reason' => (string) ($item['Reason'] ?? ''),
                        'since' => $since instanceof \DateTimeInterface ? $since->format('Y-m-d H:i') : (string) $since,
                    ];
                }

                $token = $page['NextToken'] ?? null;
            } while ($token && count($rows) < 500);

            return $rows;
        });
    }

    public function removeSuppressed(StorefrontSetting $setting, string $email): void
    {
        $this->call($setting, fn () => $this->clients->ses($this->credentials($setting))->deleteSuppressedDestination(['EmailAddress' => trim($email)]));
    }

    public function addSuppressed(StorefrontSetting $setting, string $email, string $reason = 'BOUNCE'): void
    {
        $this->call($setting, fn () => $this->clients->ses($this->credentials($setting))->putSuppressedDestination([
            'EmailAddress' => trim($email),
            'Reason' => $reason === 'COMPLAINT' ? 'COMPLAINT' : 'BOUNCE',
        ]));
    }

    /** A domain such as shop.example, or an address such as orders@shop.example, in lower case with no scheme or spaces. */
    public function normalizeIdentity(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('#^https?://#', '', $name) ?? $name;

        return rtrim($name, '/');
    }

    /** @return array{key: string, secret: string, region: string} */
    protected function credentials(StorefrontSetting $setting): array
    {
        return self::credentialsFor($setting)
            ?? throw new SesApiException(__('Save the AWS access key ID, secret access key and region first.'));
    }

    /**
     * Runs one AWS call, turning an SDK failure into a SesApiException.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function call(StorefrontSetting $setting, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (SesApiException $exception) {
            throw $exception;
        } catch (AwsException $exception) {
            if (in_array($exception->getAwsErrorCode(), ['AlreadyExistsException', 'AlreadyExists'], true)) {
                throw new SesApiException(__('This one is already in the account.'), 0, $exception);
            }

            if ($exception->getAwsErrorCode() === 'NotFoundException') {
                throw new SesApiException(__('AWS does not have that one (it may already have been removed).'), 0, $exception);
            }

            throw SesApiException::fromAws($exception);
        } catch (\Throwable $exception) {
            throw SesApiException::fromAws($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $detail  GetEmailIdentity (plus CreateEmailIdentity) answer
     * @param  array<string, mixed>  $listed  the ListEmailIdentities row
     * @return array{name: string, type: string, sending_enabled: bool, verification: string, dkim: string, dns_records: array<int, array{type: string, name: string, value: string}>}
     */
    protected function identityRow(array $detail, array $listed): array
    {
        $name = (string) ($listed['IdentityName'] ?? '');
        $type = (string) ($listed['IdentityType'] ?? $detail['IdentityType'] ?? '');
        $tokens = (array) data_get($detail, 'DkimAttributes.Tokens', []);

        return [
            'name' => $name,
            'type' => $type,
            'sending_enabled' => (bool) ($listed['SendingEnabled'] ?? $detail['VerifiedForSendingStatus'] ?? false),
            'verification' => (string) ($detail['VerificationStatus'] ?? $listed['VerificationStatus'] ?? ''),
            'dkim' => (string) data_get($detail, 'DkimAttributes.Status', ''),
            'dns_records' => $type === 'DOMAIN'
                ? array_map(fn (string $token): array => ['type' => 'CNAME', 'name' => "{$token}._domainkey.{$name}", 'value' => "{$token}.dkim.amazonses.com"], Arr::where($tokens, fn ($token) => is_string($token) && $token !== ''))
                : [],
        ];
    }
}
