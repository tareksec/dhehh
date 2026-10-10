<?php

namespace App\Services\WhatsAppWeb;

use App\Models\Company;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-company connection to the self-hosted whatsapp-bridge service, stored
 * in `companies.settings` ('whatsapp_web' key) like EmbeddedSignupSettingsService.
 * The bridge API key is encrypted at rest and admin-entered - it must match
 * the bridge's BRIDGE_API_KEY environment value.
 */
class WhatsAppWebSettingsService
{
    public function all(Company $company): array
    {
        $stored = (array) data_get($company->settings, 'whatsapp_web', []);

        return [
            'bridge_url' => (string) ($stored['bridge_url'] ?? ''),
            'api_key' => $this->decrypt($stored['api_key'] ?? null),
        ];
    }

    public function save(Company $company, array $data): void
    {
        $settings = (array) $company->settings;

        $settings['whatsapp_web'] = [
            'bridge_url' => rtrim(trim((string) ($data['bridge_url'] ?? '')), '/'),
            'api_key' => filled($data['api_key'] ?? null)
                ? Crypt::encryptString(trim((string) $data['api_key']))
                : (data_get($company->settings, 'whatsapp_web.api_key') ?? null), // keep existing when left blank
        ];

        $company->forceFill(['settings' => $settings])->save();
    }

    public function isConfigured(Company $company): bool
    {
        $settings = $this->all($company);

        return filled($settings['bridge_url']) && filled($settings['api_key']);
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
