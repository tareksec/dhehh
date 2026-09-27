<?php

namespace App\Services\PromptEnhancement;

use App\Models\Company;

/**
 * Resolves the text the Prompt Enhancer feeds its model:
 *
 * - context guide  → per-company admin override (companies.settings->
 *   prompt_guides->{key}) first, else the hard-coded default in
 *   config/prompt_guides.php, else a generic fallback.
 * - provider style → config/prompt_guides.php provider_styles, keyed by the
 *   downstream image profile's api_format.
 * - brand style    → the company's own visual style note
 *   (companies.settings->image_brand_style->note), parallel to the text
 *   tools' brand_voice.
 *
 * Also owns writing the admin overrides + brand note back, so nothing else
 * touches that JSON shape directly.
 *
 * NOTE: context keys are "{tool}.{context}" and CONTAIN A DOT, so this class
 * never reaches them through config()/data_get() dot-notation — always a
 * literal array subscript.
 */
class PromptGuideRepository
{
    public const GUIDES_PATH = 'prompt_guides';

    public const BRAND_STYLE_PATH = 'image_brand_style';

    /**
     * Context keys this repository knows a default for. Used to build the
     * admin override form.
     *
     * @return array<int, string>
     */
    public function contextKeys(): array
    {
        return array_keys($this->configDefaults());
    }

    /** The config default for one context key, or ''. */
    public function configDefault(string $contextKey): string
    {
        return trim((string) ($this->configDefaults()[$contextKey] ?? ''));
    }

    public function guide(string $contextKey, ?Company $company = null): string
    {
        $override = $company ? ($this->storedOverrides($company)[$contextKey] ?? '') : '';

        if ($override !== '') {
            return $override;
        }

        $default = $this->configDefault($contextKey);

        return $default !== '' ? $default : $this->genericFallback();
    }

    /**
     * The style note for the model that will receive the prompt. Video
     * providers read `video_provider_styles`, not the image models' notes.
     */
    public function providerStyle(?string $apiFormat, string $medium = PromptEnhancementService::MEDIUM_IMAGE): ?string
    {
        if (blank($apiFormat)) {
            return null;
        }

        $styles = (array) config($medium === PromptEnhancementService::MEDIUM_VIDEO
            ? 'prompt_guides.video_provider_styles'
            : 'prompt_guides.provider_styles', []);
        $style = trim((string) ($styles[$apiFormat] ?? ''));

        return $style !== '' ? $style : null;
    }

    public function brandStyle(?Company $company): ?string
    {
        if (! $company) {
            return null;
        }

        $note = trim((string) data_get($company->settings, self::BRAND_STYLE_PATH.'.note', ''));

        return $note !== '' ? $note : null;
    }

    /**
     * Current admin override text per context key (blank where not
     * overridden) — for filling the settings form.
     *
     * @return array<string, string>
     */
    public function overrides(Company $company): array
    {
        $stored = $this->storedOverrides($company);

        return collect($this->contextKeys())
            ->mapWithKeys(fn (string $key): array => [$key => $stored[$key] ?? ''])
            ->all();
    }

    /**
     * Persist the admin overrides + brand style note. A blank guide clears
     * that context's override (falls back to the config default).
     *
     * @param  array<string, string|null>  $guides  keyed by context key
     */
    public function save(Company $company, array $guides, ?string $brandStyleNote, ?int $updatedBy = null): void
    {
        $settings = (array) $company->settings;

        $map = [];

        foreach ($this->contextKeys() as $key) {
            $text = trim((string) ($guides[$key] ?? ''));

            if ($text === '') {
                continue;
            }

            $map[$key] = [
                'guide' => $text,
                'updated_by' => $updatedBy,
                'updated_at' => now()->toIso8601String(),
            ];
        }

        if ($map === []) {
            unset($settings[self::GUIDES_PATH]);
        } else {
            $settings[self::GUIDES_PATH] = $map;
        }

        $brandStyleNote = trim((string) $brandStyleNote);

        if ($brandStyleNote === '') {
            unset($settings[self::BRAND_STYLE_PATH]);
        } else {
            $settings[self::BRAND_STYLE_PATH] = ['note' => $brandStyleNote];
        }

        $company->forceFill(['settings' => $settings])->save();
    }

    /**
     * @return array<string, string> contextKey => override guide text
     */
    protected function storedOverrides(Company $company): array
    {
        $stored = data_get($company->settings, self::GUIDES_PATH, []);

        if (! is_array($stored)) {
            return [];
        }

        $out = [];

        foreach ($stored as $key => $entry) {
            $text = is_array($entry) ? trim((string) ($entry['guide'] ?? '')) : trim((string) $entry);

            if ($text !== '') {
                $out[(string) $key] = $text;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    protected function configDefaults(): array
    {
        return (array) config('prompt_guides.contexts', []);
    }

    protected function genericFallback(): string
    {
        return 'Rewrite the user\'s image prompt to be clearer and more specific about subject, setting, lighting, '
            .'composition, and style, without changing what they asked for. Keep it to two or three sentences. '
            .'Do not add text, logos, or watermarks unless the user asked for them.';
    }
}
