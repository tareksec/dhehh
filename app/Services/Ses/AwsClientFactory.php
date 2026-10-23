<?php

namespace App\Services\Ses;

use Aws\SesV2\SesV2Client;
use Aws\Sns\SnsClient;
use Aws\Sts\StsClient;

/**
 * Builds the AWS SDK clients the Amazon SES page uses. One place, so a test can
 * bind a subclass that hands out clients with a mock handler and no call ever
 * leaves the machine.
 */
class AwsClientFactory
{
    /** @param  array{key: string, secret: string, region: string}  $credentials */
    public function ses(array $credentials): SesV2Client
    {
        return new SesV2Client($this->config($credentials));
    }

    /** @param  array{key: string, secret: string, region: string}  $credentials */
    public function sns(array $credentials): SnsClient
    {
        return new SnsClient($this->config($credentials));
    }

    /** @param  array{key: string, secret: string, region: string}  $credentials */
    public function sts(array $credentials): StsClient
    {
        return new StsClient($this->config($credentials));
    }

    /**
     * @param  array{key: string, secret: string, region: string}  $credentials
     * @return array<string, mixed>
     */
    protected function config(array $credentials): array
    {
        return [
            'version' => 'latest',
            'region' => $credentials['region'],
            'credentials' => ['key' => $credentials['key'], 'secret' => $credentials['secret']],
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
            'retries' => 1,
        ];
    }
}
