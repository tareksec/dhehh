<?php

namespace App\Services\ImageGeneration;

use App\Models\Company;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * CRUD over the Image Generation provider-profile **list** stored at
 * `companies.settings->ai_tools->image_generation`, edited on the AI Tools →
 * Image Providers page. The product requirement is "an admin configures
 * several providers, the user picks one at generation time", so this key
 * holds an array of profiles. This service is the only place that touches
 * the raw JSON shape.
 *
 * Each profile: {
 *   id, label, api_format, base_url, model, default_size, is_default,
 *   cost_per_image (admin's approx. spend per image, for the usage dashboard),
 *   api_key (encrypted at rest)
 * }
 *
 * `api_format` decides the adapter (see ImageProviderResolver); `base_url`
 * left blank uses that format's own default endpoint.
 */
class ImageProviderSettingsService
{
    public const SETTINGS_PATH = 'ai_tools.image_generation';

    /** @var array<string, string> */
    public const API_FORMATS = [
        'openai' => 'OpenAI Images (gpt-image-1 / DALL·E)',
        'google' => 'Google Gemini / Imagen',
        'stability' => 'Stability AI (text-to-image)',
        'openrouter' => 'OpenRouter (unified image API)',
        'custom' => 'Custom (OpenAI-images-compatible endpoint)',
    ];

    public const DEFAULT_SIZE = '1024x1024';

    /**
     * Every profile with its API key decrypted — for the resolver / job.
     * Never hand this shape to a browser.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(Company $company): array
    {
        return $this->rawProfiles($company)
            ->map(fn (array $profile): array => [
                ...$this->normalizeForRead($profile),
                'api_key' => $this->decrypt($profile['api_key'] ?? null),
            ])
            ->all();
    }

    /**
     * Profiles for display / form state — no plaintext keys, a `has_api_key`
     * flag instead.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(Company $company): array
    {
        return $this->rawProfiles($company)
            ->map(fn (array $profile): array => [
                ...$this->normalizeForRead($profile),
                'has_api_key' => filled($profile['api_key'] ?? null),
            ])
            ->all();
    }

    /**
     * The profile the user gets when they do not pick one explicitly: the
     * one flagged default, else the first configured, else null.
     *
     * @return array<string, mixed>|null
     */
    public function default(Company $company): ?array
    {
        $profiles = $this->all($company);

        return collect($profiles)->firstWhere('is_default', true)
            ?? $profiles[0]
            ?? null;
    }

    /**
     * One profile by id, key decrypted. Null when it no longer exists (a
     * queued job whose profile was deleted before it ran).
     *
     * @return array<string, mixed>|null
     */
    public function find(Company $company, ?string $id): ?array
    {
        if (blank($id)) {
            return null;
        }

        return collect($this->all($company))->firstWhere('id', $id);
    }

    /**
     * A profile is usable only once it has a model and an API key. A custom
     * self-hosted endpoint may legitimately run without a key.
     *
     * @param  array<string, mixed>  $profile
     */
    public function isConfigured(array $profile): bool
    {
        return filled($profile['model'] ?? null)
            && (filled($profile['api_key'] ?? null) || ($profile['api_format'] ?? null) === 'custom');
    }

    public function hasAnyConfigured(Company $company): bool
    {
        return collect($this->all($company))->contains(fn (array $profile): bool => $this->isConfigured($profile));
    }

    /**
     * Replace the whole profile list. New keys are encrypted; a blank key on
     * an existing profile keeps the stored one. Exactly one profile ends up
     * flagged default (the first flagged, or the first profile).
     *
     * @param  array<int, array<string, mixed>>  $profiles
     */
    public function save(Company $company, array $profiles): void
    {
        $existing = $this->rawProfiles($company)->keyBy('id');

        $normalized = collect($profiles)
            ->filter(fn ($profile): bool => is_array($profile))
            ->map(function (array $profile) use ($existing): array {
                $id = filled($profile['id'] ?? null) && $existing->has($profile['id'])
                    ? (string) $profile['id']
                    : (string) Str::uuid();

                $submittedKey = trim((string) ($profile['api_key'] ?? ''));
                $storedKey = (string) data_get($existing->get($id), 'api_key', '');

                return [
                    'id' => $id,
                    'label' => trim((string) ($profile['label'] ?? '')) ?: 'Untitled provider',
                    'api_format' => array_key_exists($profile['api_format'] ?? '', self::API_FORMATS)
                        ? $profile['api_format']
                        : 'openai',
                    'base_url' => trim((string) ($profile['base_url'] ?? '')),
                    'model' => trim((string) ($profile['model'] ?? '')),
                    'default_size' => trim((string) ($profile['default_size'] ?? '')) ?: self::DEFAULT_SIZE,
                    'cost_per_image' => max(0, round((float) ($profile['cost_per_image'] ?? 0), 4)),
                    'is_default' => (bool) ($profile['is_default'] ?? false),
                    'api_key' => $submittedKey !== '' ? Crypt::encryptString($submittedKey) : $storedKey,
                ];
            })
            ->pipe(fn (Collection $items): Collection => $this->enforceSingleDefault($items))
            ->values()
            ->all();

        $settings = (array) $company->settings;
        data_set($settings, self::SETTINGS_PATH, $normalized);
        $company->forceFill(['settings' => $settings])->save();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $profiles
     * @return Collection<int, array<string, mixed>>
     */
    protected function enforceSingleDefault(Collection $profiles): Collection
    {
        if ($profiles->isEmpty()) {
            return $profiles;
        }

        $defaultIndex = $profiles->search(fn (array $profile): bool => $profile['is_default'] === true);
        $defaultIndex = $defaultIndex === false ? 0 : $defaultIndex;

        return $profiles->values()->map(fn (array $profile, int $index): array => [
            ...$profile,
            'is_default' => $index === $defaultIndex,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function rawProfiles(Company $company): Collection
    {
        $stored = data_get($company->settings, self::SETTINGS_PATH, []);

        return collect(is_array($stored) ? $stored : [])
            ->filter(fn ($profile): bool => is_array($profile))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    protected function normalizeForRead(array $profile): array
    {
        return [
            'id' => (string) ($profile['id'] ?? Str::uuid()),
            'label' => (string) ($profile['label'] ?? 'Untitled provider'),
            'api_format' => array_key_exists($profile['api_format'] ?? '', self::API_FORMATS)
                ? $profile['api_format']
                : 'openai',
            'base_url' => (string) ($profile['base_url'] ?? ''),
            'model' => (string) ($profile['model'] ?? ''),
            'default_size' => (string) ($profile['default_size'] ?? '') ?: self::DEFAULT_SIZE,
            'cost_per_image' => round((float) ($profile['cost_per_image'] ?? 0), 4),
            'is_default' => (bool) ($profile['is_default'] ?? false),
        ];
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
