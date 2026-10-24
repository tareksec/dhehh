<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;

class BackupSettingsService
{
    public const DRIVE_ENABLED = 'backup.google_drive.enabled';

    public const DRIVE_AUTO_UPLOAD = 'backup.google_drive.auto_upload';

    public const DRIVE_FOLDER_ID = 'backup.google_drive.folder_id';

    public const DRIVE_SERVICE_ACCOUNT_JSON = 'backup.google_drive.service_account_json';

    public const DRIVE_SERVICE_ACCOUNT_PATH = 'backup.google_drive.service_account_path';

    public function googleDriveEnabled(): bool
    {
        if (! $this->settingsTableExists()) {
            return (bool) config('backup.google_drive.enabled');
        }

        return AppSetting::boolValue(self::DRIVE_ENABLED, (bool) config('backup.google_drive.enabled'));
    }

    public function googleDriveAutoUpload(): bool
    {
        if (! $this->settingsTableExists()) {
            return (bool) config('backup.google_drive.auto_upload');
        }

        return AppSetting::boolValue(self::DRIVE_AUTO_UPLOAD, (bool) config('backup.google_drive.auto_upload'));
    }

    public function googleDriveFolderId(): ?string
    {
        if (! $this->settingsTableExists()) {
            return config('backup.google_drive.folder_id');
        }

        return AppSetting::getValue(self::DRIVE_FOLDER_ID, config('backup.google_drive.folder_id'));
    }

    public function serviceAccountJson(): ?string
    {
        if (! $this->settingsTableExists()) {
            return config('backup.google_drive.service_account_json');
        }

        return AppSetting::getValue(self::DRIVE_SERVICE_ACCOUNT_JSON, config('backup.google_drive.service_account_json'));
    }

    public function serviceAccountPath(): ?string
    {
        if (! $this->settingsTableExists()) {
            return config('backup.google_drive.service_account_path');
        }

        return AppSetting::getValue(self::DRIVE_SERVICE_ACCOUNT_PATH, config('backup.google_drive.service_account_path'));
    }

    public function hasServiceAccountJson(): bool
    {
        if (! $this->settingsTableExists()) {
            return filled(config('backup.google_drive.service_account_json'));
        }

        return filled(AppSetting::getValue(self::DRIVE_SERVICE_ACCOUNT_JSON));
    }

    public const R2_ENABLED = 'backup.r2.enabled';

    public const R2_AUTO_UPLOAD = 'backup.r2.auto_upload';

    public const R2_ACCOUNT = 'backup.r2.account';

    public const R2_BUCKET = 'backup.r2.bucket';

    public const R2_PREFIX = 'backup.r2.prefix';

    public const R2_ACCESS_KEY_ID = 'backup.r2.access_key_id';

    public const R2_SECRET_ACCESS_KEY = 'backup.r2.secret_access_key';

    public const R2_USD_BDT_RATE = 'backup.r2.usd_bdt_rate';

    public function r2Enabled(): bool
    {
        if (! $this->settingsTableExists()) {
            return (bool) config('backup.r2.enabled');
        }

        return AppSetting::boolValue(self::R2_ENABLED, (bool) config('backup.r2.enabled'));
    }

    public function r2AutoUpload(): bool
    {
        if (! $this->settingsTableExists()) {
            return (bool) config('backup.r2.auto_upload');
        }

        return AppSetting::boolValue(self::R2_AUTO_UPLOAD, (bool) config('backup.r2.auto_upload'));
    }

    /** The Cloudflare Account ID or the full endpoint, as typed. */
    public function r2Account(): ?string
    {
        return $this->r2Value(self::R2_ACCOUNT, 'account');
    }

    public function r2Bucket(): ?string
    {
        return $this->r2Value(self::R2_BUCKET, 'bucket');
    }

    /** The folder inside the bucket, without slashes at either end ('' = the bucket's top level). */
    public function r2Prefix(): string
    {
        return trim((string) $this->r2Value(self::R2_PREFIX, 'prefix'), '/ ');
    }

    public function r2AccessKeyId(): ?string
    {
        return $this->r2Value(self::R2_ACCESS_KEY_ID, 'access_key_id');
    }

    public function r2SecretAccessKey(): ?string
    {
        return $this->r2Value(self::R2_SECRET_ACCESS_KEY, 'secret_access_key');
    }

    public function hasR2SecretAccessKey(): bool
    {
        return filled($this->r2SecretAccessKey());
    }

    /** US dollars → taka, only to show the estimate in taka as well; null = not set. */
    public function r2UsdBdtRate(): ?float
    {
        $rate = (float) $this->r2Value(self::R2_USD_BDT_RATE, 'usd_bdt_rate');

        return $rate > 0 ? $rate : null;
    }

    /**
     * The endpoint URL: the account id turned into https://<id>.r2.cloudflarestorage.com, or the
     * address the admin pasted (for the EU jurisdiction or another S3-compatible service).
     */
    public function r2Endpoint(): ?string
    {
        $account = trim((string) $this->r2Account());

        if ($account === '') {
            return null;
        }

        if (preg_match('/^[0-9a-f]{32}$/i', $account) === 1) {
            return 'https://'.strtolower($account).'.r2.cloudflarestorage.com';
        }

        // Cloudflare's bucket page shows the address with the bucket name on the end; only the
        // scheme and host belong to the endpoint (the bucket is a separate field).
        return preg_match('~^https?://[^\s/?]+~i', $account, $address) === 1 ? $address[0] : null;
    }

    /** @param  array{enabled?: bool, auto_upload?: bool, account?: ?string, bucket?: ?string, prefix?: ?string, access_key_id?: ?string, secret_access_key?: ?string, usd_bdt_rate?: mixed}  $data */
    public function saveR2(array $data): void
    {
        AppSetting::setValue(self::R2_ENABLED, ! empty($data['enabled']) ? '1' : '0');
        AppSetting::setValue(self::R2_AUTO_UPLOAD, ! empty($data['auto_upload']) ? '1' : '0');
        AppSetting::setValue(self::R2_ACCOUNT, trim((string) ($data['account'] ?? '')));
        AppSetting::setValue(self::R2_BUCKET, trim((string) ($data['bucket'] ?? '')));
        AppSetting::setValue(self::R2_PREFIX, trim((string) ($data['prefix'] ?? ''), '/ '));
        AppSetting::setValue(self::R2_ACCESS_KEY_ID, trim((string) ($data['access_key_id'] ?? '')));
        AppSetting::setValue(self::R2_USD_BDT_RATE, filled($data['usd_bdt_rate'] ?? null) ? (string) (float) $data['usd_bdt_rate'] : '');

        // Left blank = keep the one already stored.
        if (filled($data['secret_access_key'] ?? null)) {
            AppSetting::setValue(self::R2_SECRET_ACCESS_KEY, trim((string) $data['secret_access_key']), encrypted: true);
        }
    }

    /** A stored value, else the environment's; a saved blank counts as "not set". */
    protected function r2Value(string $settingKey, string $configKey): ?string
    {
        $fallback = config("backup.r2.{$configKey}");

        if (! $this->settingsTableExists()) {
            return filled($fallback) ? (string) $fallback : null;
        }

        $stored = AppSetting::getValue($settingKey);

        if (filled($stored)) {
            return (string) $stored;
        }

        return filled($fallback) ? (string) $fallback : null;
    }

    public function saveGoogleDrive(array $data): void
    {
        AppSetting::setValue(self::DRIVE_ENABLED, ! empty($data['enabled']) ? '1' : '0');
        AppSetting::setValue(self::DRIVE_AUTO_UPLOAD, ! empty($data['auto_upload']) ? '1' : '0');
        AppSetting::setValue(self::DRIVE_FOLDER_ID, trim((string) ($data['folder_id'] ?? '')));
        AppSetting::setValue(self::DRIVE_SERVICE_ACCOUNT_PATH, trim((string) ($data['service_account_path'] ?? '')));

        if (filled($data['service_account_json'] ?? null)) {
            AppSetting::setValue(self::DRIVE_SERVICE_ACCOUNT_JSON, trim((string) $data['service_account_json']), encrypted: true);
        }
    }

    protected function settingsTableExists(): bool
    {
        return Schema::hasTable('app_settings');
    }
}
