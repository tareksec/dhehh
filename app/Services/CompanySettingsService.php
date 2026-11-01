<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Company;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;

class CompanySettingsService
{
    protected const MAX_EMBEDDED_PUBLIC_IMAGE_BYTES = 5 * 1024 * 1024;

    public const NAME = 'company.name';

    public const LOGO = 'company.logo';

    public const DARK_LOGO = 'company.dark_logo';

    public const ADDRESS = 'company.address';

    public const PHONE = 'company.phone';

    public const EMAIL = 'company.email';

    public const CURRENCY = 'company.currency';

    public const TIMEZONE = 'company.timezone';

    public const DATE_FORMAT = 'company.date_format';

    public function name(?Company $company = null): string
    {
        $company ??= $this->currentCompany();

        if ($company) {
            return (string) ($company->name ?: config('app.name', 'Business Dashboard'));
        }

        return (string) $this->value(self::NAME, config('app.name', 'Business Dashboard'));
    }

    public function profile(?Company $company = null): array
    {
        $company ??= $this->currentCompany();

        if ($company) {
            return $this->companyProfile($company);
        }

        return [
            'name' => $this->name(),
            'logo' => $this->value(self::LOGO),
            'dark_logo' => $this->value(self::DARK_LOGO),
            'logo_url' => $this->logoUrl(),
            'dark_logo_url' => $this->darkLogoUrl(),
            'logo_path' => $this->logoPath(),
            'dark_logo_path' => $this->darkLogoPath(),
            'address' => $this->value(self::ADDRESS),
            'phone' => $this->value(self::PHONE),
            'email' => $this->value(self::EMAIL),
            'currency' => $this->value(self::CURRENCY, 'BDT'),
            'timezone' => $this->value(self::TIMEZONE, config('app.timezone', 'UTC')),
            'date_format' => $this->value(self::DATE_FORMAT, 'd M Y'),
            'invoice_prefix' => 'INV',
            'shipping_zones' => ['inside' => [], 'outside' => [], 'suburb' => []],
        ];
    }

    public const INVOICE_DEFAULTS = [
        'hotline' => '',
        'support_hotline' => '',
        'facebook_url' => '',
        'facebook_label' => '',
        'whatsapp' => '',
        'website' => '',
        'thank_you' => 'Thank You For Purchasing From Us.',
        'show_images' => true,
        'show_weight' => true,
        'show_barcode' => true,
        'show_slip' => true,
    ];

    public function invoice(?Company $company = null): array
    {
        $company ??= $this->currentCompany();
        $stored = (array) (((array) $company?->settings)['invoice'] ?? []);

        $merged = array_merge(self::INVOICE_DEFAULTS, array_intersect_key($stored, self::INVOICE_DEFAULTS));

        foreach (['show_images', 'show_weight', 'show_barcode', 'show_slip'] as $flag) {
            $merged[$flag] = (bool) $merged[$flag];
        }

        return $merged;
    }

    public function saveInvoice(array $data, ?Company $company = null): void
    {
        $company ??= $this->currentCompany();

        if (! $company) {
            throw new LogicException('Select a company before saving invoice settings.');
        }

        $invoice = self::INVOICE_DEFAULTS;

        foreach ($invoice as $key => $default) {
            if (is_bool($default)) {
                $invoice[$key] = (bool) ($data[$key] ?? $default);
            } else {
                $invoice[$key] = trim((string) ($data[$key] ?? $default));
            }
        }

        $settings = $company->settings ?? [];
        $settings['invoice'] = $invoice;

        $company->forceFill(['settings' => $settings])->save();
    }

    public function save(array $data, ?Company $company = null): void
    {
        $company ??= $this->currentCompany();

        if ($company) {
            $invoicePrefix = Str::upper(trim((string) ($data['invoice_prefix'] ?? $company->invoice_prefix)));

            Validator::make(
                ['invoice_prefix' => $invoicePrefix],
                [
                    'invoice_prefix' => [
                        'required',
                        'string',
                        'max:20',
                        'regex:/^[A-Z0-9-]+$/',
                        Rule::unique('companies', 'invoice_prefix')->ignore($company->getKey()),
                    ],
                ],
            )->validate();

            $settings = $company->settings ?? [];
            $settings['dark_logo'] = trim((string) ($data['dark_logo'] ?? ''));
            $settings['favicon'] = trim((string) ($data['favicon'] ?? ''));
            $settings['date_format'] = trim((string) ($data['date_format'] ?? 'd M Y'));

            if (isset($data['shipping_zones'])) {
                $settings['shipping_zones'] = [
                    'inside' => array_values((array) ($data['shipping_zones']['inside'] ?? [])),
                    'outside' => array_values((array) ($data['shipping_zones']['outside'] ?? [])),
                    'suburb' => array_values((array) ($data['shipping_zones']['suburb'] ?? [])),
                ];
            }

            $company->fill([
                'name' => trim((string) ($data['name'] ?? '')),
                'logo' => trim((string) ($data['logo'] ?? '')),
                'address' => trim((string) ($data['address'] ?? '')),
                'phone' => trim((string) ($data['phone'] ?? '')),
                'email' => trim((string) ($data['email'] ?? '')),
                'currency' => trim((string) ($data['currency'] ?? 'BDT')),
                'timezone' => trim((string) ($data['timezone'] ?? config('app.timezone', 'UTC'))),
                'invoice_prefix' => $invoicePrefix,
                'settings' => $settings,
            ])->save();

            return;
        }

        AppSetting::setValue(self::NAME, trim((string) ($data['name'] ?? '')));
        AppSetting::setValue(self::LOGO, trim((string) ($data['logo'] ?? '')));
        AppSetting::setValue(self::DARK_LOGO, trim((string) ($data['dark_logo'] ?? '')));
        AppSetting::setValue(self::ADDRESS, trim((string) ($data['address'] ?? '')));
        AppSetting::setValue(self::PHONE, trim((string) ($data['phone'] ?? '')));
        AppSetting::setValue(self::EMAIL, trim((string) ($data['email'] ?? '')));
        AppSetting::setValue(self::CURRENCY, trim((string) ($data['currency'] ?? 'BDT')));
        AppSetting::setValue(self::TIMEZONE, trim((string) ($data['timezone'] ?? config('app.timezone', 'UTC'))));
        AppSetting::setValue(self::DATE_FORMAT, trim((string) ($data['date_format'] ?? 'd M Y')));
    }

    public function logoUrl(?Company $company = null): ?string
    {
        $company ??= $this->currentCompany();

        if ($company) {
            return $this->publicUrl($company->logo, $company);
        }

        return $this->publicUrl($this->value(self::LOGO));
    }

    public function darkLogoUrl(bool $fallbackToLight = true, ?Company $company = null): ?string
    {
        $company ??= $this->currentCompany();

        if ($company) {
            $settings = (array) $company->settings;
            $darkLogo = $this->publicUrl($settings['dark_logo'] ?? null, $company);

            return $darkLogo ?: ($fallbackToLight ? $this->logoUrl($company) : null);
        }

        return $this->publicUrl($this->value(self::DARK_LOGO)) ?: ($fallbackToLight ? $this->logoUrl() : null);
    }

    public function faviconUrl(?Company $company = null): ?string
    {
        $company ??= $this->currentCompany();

        if ($company) {
            return $this->publicUrl(((array) $company->settings)['favicon'] ?? null, $company);
        }

        return null;
    }

    public function logoPath(?Company $company = null): ?string
    {
        $company ??= $this->currentCompany();

        if ($company) {
            return $this->publicPath($company->logo, $company);
        }

        return $this->publicPath($this->value(self::LOGO));
    }

    public function darkLogoPath(bool $fallbackToLight = true, ?Company $company = null): ?string
    {
        $company ??= $this->currentCompany();

        if ($company) {
            $settings = (array) $company->settings;
            $darkLogo = $this->publicPath($settings['dark_logo'] ?? null, $company);

            return $darkLogo ?: ($fallbackToLight ? $this->logoPath($company) : null);
        }

        return $this->publicPath($this->value(self::DARK_LOGO)) ?: ($fallbackToLight ? $this->logoPath() : null);
    }

    public function invoiceImagePath(?string $path, Company $company): ?string
    {
        return $this->publicPath($path, $company);
    }

    protected function publicUrl(?string $path, ?Company $company = null): ?string
    {
        try {
            return app(CompanyStorageService::class)->publicUrl($path, $company);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    protected function publicPath(?string $path, ?Company $company = null): ?string
    {
        try {
            $location = app(CompanyStorageService::class)->locatePublic($path, $company);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($location === null) {
            return null;
        }

        $disk = Storage::disk($location['disk']);

        if (config("filesystems.disks.{$location['disk']}.driver") === 'local') {
            return $disk->path($location['path']);
        }

        try {
            if ($disk->size($location['path']) > self::MAX_EMBEDDED_PUBLIC_IMAGE_BYTES) {
                return null;
            }

            $contents = $disk->get($location['path']);
            $reportedMimeType = $disk->mimeType($location['path']);
        } catch (\Throwable) {
            return null;
        }

        if (strlen($contents) > self::MAX_EMBEDDED_PUBLIC_IMAGE_BYTES) {
            return null;
        }

        $mimeType = match (strtolower(pathinfo($location['path'], PATHINFO_EXTENSION))) {
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => is_string($reportedMimeType) && str_starts_with($reportedMimeType, 'image/')
                ? $reportedMimeType
                : null,
        };

        return $mimeType === null
            ? null
            : 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }

    public function formatMoney(float|int|string|null $amount): string
    {
        return MoneyFormatter::currency($amount, (string) $this->profile()['currency']);
    }

    public function formatDate($date): string
    {
        if (! $date) {
            return '-';
        }

        return $date->timezone($this->profile()['timezone'])->format($this->profile()['date_format']);
    }

    protected function value(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable('app_settings')) {
            return $default;
        }

        return AppSetting::getValue($key, $default);
    }

    protected function currentCompany(): ?Company
    {
        if (! app()->bound(CompanyContext::class) || ! app(CompanyContext::class)->hasCompany()) {
            return null;
        }

        return app(CompanyContext::class)->company();
    }

    protected function companyProfile(Company $company): array
    {
        $settings = (array) $company->settings;

        return [
            'name' => $this->name($company),
            'logo' => $company->logo,
            'dark_logo' => $settings['dark_logo'] ?? null,
            'favicon' => $settings['favicon'] ?? null,
            'logo_url' => $this->logoUrl($company),
            'dark_logo_url' => $this->darkLogoUrl(company: $company),
            'logo_path' => $this->logoPath($company),
            'dark_logo_path' => $this->darkLogoPath(company: $company),
            'address' => $company->address,
            'phone' => $company->phone,
            'email' => $company->email,
            'currency' => $company->currency ?: 'BDT',
            'timezone' => $company->timezone ?: config('app.timezone', 'UTC'),
            'date_format' => $settings['date_format'] ?? 'd M Y',
            'invoice_prefix' => $company->invoice_prefix ?: 'INV',
            'shipping_zones' => [
                'inside' => $settings['shipping_zones']['inside'] ?? [],
                'outside' => $settings['shipping_zones']['outside'] ?? [],
                'suburb' => $settings['shipping_zones']['suburb'] ?? [],
            ],
        ];
    }
}
