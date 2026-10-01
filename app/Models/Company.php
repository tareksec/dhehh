<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

class Company extends Model
{
    public const CORE_COMPANIES = [
        [
            'name' => 'Garments Machinery Company',
            'slug' => 'garments-machinery',
            'business_type' => 'Garments Machinery',
            'invoice_prefix' => 'GM',
            'domain' => 'tasneemknitindustry.com',
        ],
        [
            'name' => 'Solar Items Company',
            'slug' => 'solar-items',
            'business_type' => 'Solar Items',
            'invoice_prefix' => 'SOL',
            'domain' => 'noorsolaren.com',
        ],
        [
            'name' => 'Gadget Items Company',
            'slug' => 'gadget-items',
            'business_type' => 'Gadget Items',
            'invoice_prefix' => 'GAD',
            'domain' => 'zamzamgadgetbd.com',
        ],
        [
            'name' => 'Gift Items Company',
            'slug' => 'gift-items',
            'business_type' => 'Gift Items',
            'invoice_prefix' => 'GFT',
            'domain' => 'zamzamint.com',
        ],
    ];

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'domain_verified',
        'business_type',
        'logo',
        'phone',
        'email',
        'address',
        'google_maps_url',
        'currency',
        'timezone',
        'invoice_prefix',
        'is_active',
        'settings',
        'dashboard_color',
        'reseller_module_enabled',
        'reseller_portal_domain',
        'reseller_subdomains_enabled',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'domain_verified' => 'boolean',
        'reseller_module_enabled' => 'boolean',
        'reseller_subdomains_enabled' => 'boolean',
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Company $company): void {
            if (! $company->exists && blank($company->storage_key)) {
                $company->storage_key = (string) Str::uuid();
            }

            if (! $company->exists && ! Str::isUuid((string) $company->storage_key)) {
                throw new LogicException('A company storage key must be a UUID.');
            }

            if ($company->exists && $company->isDirty('storage_key')) {
                throw new LogicException('A company storage key is immutable once assigned.');
            }

            $company->slug = $company->slug ?: Str::slug($company->name);
            $company->currency = $company->currency ?: 'BDT';
            $company->timezone = $company->timezone ?: config('app.timezone', 'Asia/Dhaka');
            $company->invoice_prefix = static::normalizeInvoicePrefix($company->invoice_prefix, $company->name);
            $company->domain = static::normalizeDomain($company->domain);
            $company->reseller_portal_domain = static::normalizeDomain($company->reseller_portal_domain);
        });

        static::created(function (Company $company): void {
            Account::ensureSystemAccountsForCompany($company);
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'is_default'])
            ->withTimestamps();
    }

    public function storefrontSetting(): HasOne
    {
        return $this->hasOne(StorefrontSetting::class);
    }

    public function storageRoot(): string
    {
        if (blank($this->storage_key)) {
            throw new LogicException('The company does not have a storage key.');
        }

        return 'companies/'.$this->storage_key;
    }

    public static function normalizeDomain(?string $domain): ?string
    {
        $domain = strtolower(trim((string) $domain));

        if ($domain === '') {
            return null;
        }

        $domain = preg_replace('#^https?://#', '', $domain) ?: $domain;
        $domain = strtok($domain, '/') ?: $domain;
        $domain = preg_replace('/:\d+$/', '', $domain) ?: $domain;

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }

    /**
     * The company whose reseller portal is reached at $host: either its own
     * custom reseller_portal_domain, or "reseller." + its ordinary domain
     * when no custom one is set. Used by ResolveCompanyFromResellerPortalDomain
     * and RestrictResellerPortalHost -- both must agree on this exact rule.
     */
    public static function forResellerPortalHost(?string $host): ?self
    {
        $host = static::normalizeDomain($host);

        if ($host === null || ! Schema::hasTable('companies')) {
            return null;
        }

        $company = static::query()
            ->where('reseller_module_enabled', true)
            ->where('is_active', true)
            ->where('reseller_portal_domain', $host)
            ->first();

        if ($company) {
            return $company;
        }

        if (! str_starts_with($host, 'reseller.')) {
            return null;
        }

        return static::query()
            ->where('reseller_module_enabled', true)
            ->where('is_active', true)
            ->whereNull('reseller_portal_domain')
            ->where('domain', substr($host, strlen('reseller.')))
            ->first();
    }

    /**
     * Labels a reseller may never take as a store subdomain: they either serve
     * the reseller portal / admin / mail or would look like official pages.
     */
    public const RESERVED_RESELLER_SUBDOMAINS = [
        'www', 'reseller', 'resellers', 'portal', 'admin', 'app', 'api', 'mail', 'smtp', 'ftp',
        'cdn', 'static', 'assets', 'shop', 'store', 'dashboard', 'login', 'support', 'help',
        'billing', 'staging', 'dev', 'test', 'demo',
    ];

    /**
     * The company + subdomain label when $host is "<label>.<company domain>"
     * for a company that has reseller subdomains switched on. The caller still
     * has to find the reseller with that label. Reserved labels never match.
     *
     * @return array{0: self, 1: string}|null
     */
    public static function forResellerSubdomainHost(?string $host): ?array
    {
        $host = static::normalizeDomain($host);

        if ($host === null || ! str_contains($host, '.') || ! Schema::hasTable('companies')) {
            return null;
        }

        [$label, $parent] = explode('.', $host, 2);

        if (in_array($label, self::RESERVED_RESELLER_SUBDOMAINS, true)) {
            return null;
        }

        $company = static::query()
            ->where('domain', $parent)
            ->where('is_active', true)
            ->where('reseller_module_enabled', true)
            ->where('reseller_subdomains_enabled', true)
            ->first();

        return $company ? [$company, $label] : null;
    }

    /** "<label>.<domain>" for a reseller's chosen subdomain, or null when subdomains are off. */
    public function resellerSubdomainHost(?string $label): ?string
    {
        if (blank($label) || blank($this->domain) || ! $this->reseller_module_enabled || ! $this->reseller_subdomains_enabled) {
            return null;
        }

        return $label.'.'.$this->domain;
    }

    /**
     * The host this company's reseller portal is reached at, or null when
     * the reseller module isn't enabled or this company has no domain yet.
     * The exact inverse of forResellerPortalHost().
     */
    public function resellerPortalHost(): ?string
    {
        if (! $this->reseller_module_enabled) {
            return null;
        }

        if (filled($this->reseller_portal_domain)) {
            return $this->reseller_portal_domain;
        }

        return filled($this->domain) ? 'reseller.'.$this->domain : null;
    }

    public static function normalizeInvoicePrefix(?string $prefix, ?string $companyName = null): string
    {
        $prefix = Str::upper(trim((string) $prefix));

        if ($prefix === '') {
            $prefix = Str::upper(Str::substr(Str::slug((string) $companyName, ''), 0, 3)) ?: 'INV';
        }

        if (strlen($prefix) > 20 || ! preg_match('/^[A-Z0-9-]+$/', $prefix)) {
            throw new LogicException('Invoice prefixes may contain only uppercase letters, numbers, and hyphens, up to 20 characters.');
        }

        return $prefix;
    }

    public static function defaultCompany(): ?self
    {
        if (! Schema::hasTable('companies')) {
            return null;
        }

        return static::query()
            ->where('is_active', true)
            ->oldest('id')
            ->first()
            ?? static::query()->oldest('id')->first();
    }

    public static function defaultCompanyId(): ?int
    {
        return static::defaultCompany()?->getKey();
    }

    public static function seedCoreCompanies(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        foreach (self::CORE_COMPANIES as $company) {
            $record = static::query()->firstOrCreate(
                ['slug' => $company['slug']],
                [
                    'name' => $company['name'],
                    'business_type' => $company['business_type'],
                    'currency' => 'BDT',
                    'timezone' => config('app.timezone', 'Asia/Dhaka'),
                    'invoice_prefix' => $company['invoice_prefix'],
                    'domain' => $company['domain'],
                    'domain_verified' => false,
                    'is_active' => true,
                    'settings' => [],
                ],
            );

            if (blank($record->domain)) {
                $record->forceFill(['domain' => $company['domain']])->save();
            }
        }
    }
}
