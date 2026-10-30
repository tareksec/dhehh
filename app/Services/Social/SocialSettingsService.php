<?php

namespace App\Services\Social;

use App\Models\Company;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-company Social settings, kept in the existing `companies.settings` json
 * column under the `social` key, the same shape as AiSettingsService and
 * EmbeddedSignupSettingsService (19_SOCIAL_CONTENT_PLANNER_PLAN.md §4, §7.3).
 *
 * The Page access token is admin-entered, encrypted at rest, never logged and
 * never returned to the browser. It is a separate token from the Messenger
 * token the Inbox holds and the Ads token: those were issued for other jobs.
 */
class SocialSettingsService
{
    public const WORKER_HEARTBEAT_CACHE_KEY = 'social:queue-heartbeat';

    /** The heartbeat job runs every 5 minutes; this is how long a sighting counts as "worker alive". */
    public const WORKER_HEARTBEAT_TTL_MINUTES = 15;

    public const DEFAULTS = [
        'enabled' => false,
        'page_id' => '',
        'instagram_account_id' => '',
    ];

    /** @return array{enabled: bool, page_id: string, instagram_account_id: string, page_access_token: ?string, last_success_at: ?string} */
    public function all(Company $company): array
    {
        $stored = (array) data_get($company->settings, 'social', []);
        $meta = (array) ($stored['meta'] ?? []);

        return [
            'enabled' => (bool) ($stored['enabled'] ?? self::DEFAULTS['enabled']),
            'page_id' => (string) ($meta['page_id'] ?? self::DEFAULTS['page_id']),
            'instagram_account_id' => (string) ($meta['instagram_account_id'] ?? self::DEFAULTS['instagram_account_id']),
            'page_access_token' => $this->decrypt($meta['page_access_token'] ?? null),
            'last_success_at' => isset($stored['last_success_at']) ? (string) $stored['last_success_at'] : null,
        ];
    }

    /** What the settings page may show: never the token itself. */
    public function forDisplay(Company $company): array
    {
        $settings = $this->all($company);
        $settings['has_page_access_token'] = filled($settings['page_access_token']);
        unset($settings['page_access_token']);

        return $settings;
    }

    /**
     * A blank token keeps the one already stored (the form never receives it back).
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Company $company, array $data): void
    {
        $settings = (array) $company->settings;
        $stored = (array) ($settings['social'] ?? []);
        $meta = (array) ($stored['meta'] ?? []);

        $meta['page_id'] = trim((string) ($data['page_id'] ?? ''));
        $meta['instagram_account_id'] = trim((string) ($data['instagram_account_id'] ?? ''));

        if (filled($data['page_access_token'] ?? null)) {
            $meta['page_access_token'] = Crypt::encryptString(trim((string) $data['page_access_token']));
        }

        $stored['meta'] = $meta;
        $stored['enabled'] = (bool) ($data['enabled'] ?? false);
        $settings['social'] = $stored;

        $company->forceFill(['settings' => $settings])->save();
    }

    public function isEnabled(Company $company): bool
    {
        return $this->all($company)['enabled'];
    }

    public function hasToken(Company $company): bool
    {
        return filled($this->all($company)['page_access_token']);
    }

    /** Facebook needs the Page id and a token; Instagram also needs the Instagram account id. */
    public function isConfiguredFor(Company $company, string $platform): bool
    {
        $settings = $this->all($company);

        if (blank($settings['page_access_token'])) {
            return false;
        }

        return match ($platform) {
            'facebook' => filled($settings['page_id']),
            'instagram' => filled($settings['instagram_account_id']),
            default => false,
        };
    }

    /** Remembers when a call to a platform last worked, for the settings page. */
    public function markSuccess(Company $company): void
    {
        $fresh = Company::query()->find($company->getKey());

        if (! $fresh) {
            return;
        }

        $settings = (array) $fresh->settings;
        $settings['social'] = array_merge((array) ($settings['social'] ?? []), ['last_success_at' => now()->toIso8601String()]);

        // Quietly: this is a bookkeeping write, not a change someone should be told about.
        $fresh->forceFill(['settings' => $settings])->saveQuietly();
    }

    public function lastSuccessAt(Company $company): ?Carbon
    {
        $value = $this->all($company)['last_success_at'];

        return filled($value) ? Carbon::parse($value) : null;
    }

    /** Called by the heartbeat job from the `social` worker. */
    public function recordWorkerHeartbeat(): void
    {
        Cache::put(self::WORKER_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addMinutes(self::WORKER_HEARTBEAT_TTL_MINUTES));
    }

    /**
     * Whether the second (`social`) queue worker has been seen lately. Publishing must not be switched on
     * without it: on the default worker a long publish could be handed out twice (plan §4, §10).
     */
    public function workerSeenRecently(): bool
    {
        return Cache::has(self::WORKER_HEARTBEAT_CACHE_KEY);
    }

    protected function decrypt(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
