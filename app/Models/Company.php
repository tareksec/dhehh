<?php

namespace App\Models;

use App\Services\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'reseller_custom_domains_enabled',
        'investor_portal_enabled',
        'investor_portal_domain',
        'investor_project_completed_message',
        'ims_enabled',
        'ims_domain',
        'bin_number',
        'irc_number',
        'tin_number',
        'signatory_name',
        'signatory_title',
        'signature_path',
        'trade_portal_enabled',
        'registered_address',
        'factory_address',
        'challan_bin_number',
        'bank_accounts',
        'letterhead_path',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'domain_verified' => 'boolean',
        'reseller_module_enabled' => 'boolean',
        'reseller_subdomains_enabled' => 'boolean',
        'reseller_custom_domains_enabled' => 'boolean',
        'investor_portal_enabled' => 'boolean',
        'ims_enabled' => 'boolean',
        'trade_portal_enabled' => 'boolean',
        'bank_accounts' => 'array',
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
            $company->investor_portal_domain = static::normalizeDomain($company->investor_portal_domain);
            $company->ims_domain = static::normalizeDomain($company->ims_domain);
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

    /**
     * The company's Blade storefront settings (the reseller store). Stays the
     * answer to "the company's storefront setting" for everything that predates
     * the site split; the Next.js site has its own row, see nextjsSetting().
     */
    public function storefrontSetting(): HasOne
    {
        return $this->hasOne(StorefrontSetting::class)->where('site_type', StorefrontSetting::SITE_BLADE);
    }

    /** The company's Next.js site settings (the main storefront); null until one is created. */
    public function nextjsSetting(): HasOne
    {
        return $this->hasOne(StorefrontSetting::class)->where('site_type', StorefrontSetting::SITE_NEXTJS);
    }

    /** Every storefront settings row of the company, whatever the site. */
    public function storefrontSettings(): HasMany
    {
        return $this->hasMany(StorefrontSetting::class);
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
     * and RestrictPortalHosts -- both must agree on this exact rule.
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
     * The company whose investor portal is reached at $host: its own custom
     * investor_portal_domain, or "investor." + its ordinary domain when no
     * custom one is set, and only while the portal is switched on. The same
     * rule as forResellerPortalHost(); used by RestrictPortalHosts and
     * ResolveCompanyFromInvestorPortalDomain, which must always agree.
     */
    public static function forInvestorPortalHost(?string $host): ?self
    {
        $host = static::normalizeDomain($host);

        if ($host === null || ! Schema::hasTable('companies')) {
            return null;
        }

        // IMS v2: the investor portal lives on the IMS domain once IMS is on.
        if ($company = static::forImsHost($host)) {
            return $company;
        }

        $company = static::query()
            ->where('investor_portal_enabled', true)
            ->where('is_active', true)
            ->where('investor_portal_domain', $host)
            ->first();

        if ($company) {
            return $company;
        }

        if (! str_starts_with($host, 'investor.')) {
            return null;
        }

        return static::query()
            ->where('investor_portal_enabled', true)
            ->where('is_active', true)
            ->whereNull('investor_portal_domain')
            ->where('domain', substr($host, strlen('investor.')))
            ->first();
    }

    /**
     * The company whose IMS v2 is on $host (IMS-V2/ARCHITECTURE.md §3): the
     * exact ims_domain, with or without "www.", while IMS is switched on. There
     * is no subdomain rule (owner, 2026-10-19: IMS has its own root domain).
     */
    public static function forImsHost(?string $host): ?self
    {
        $host = static::normalizeDomain($host);

        if ($host === null || ! static::imsColumnsExist()) {
            return null;
        }

        return static::query()
            ->where('ims_enabled', true)
            ->where('is_active', true)
            ->where('ims_domain', $host)
            ->first();
    }

    /**
     * The company whose old investor-portal host this is, once its portal has
     * moved to the IMS domain (IMS-V2/ARCHITECTURE.md §3): that host now only
     * redirects to the IMS domain.
     */
    public static function forRetiredInvestorPortalHost(?string $host): ?self
    {
        $host = static::normalizeDomain($host);

        if ($host === null || ! static::imsColumnsExist()) {
            return null;
        }

        // Every company is on the group IMS domain (18_IMS_ALL_COMPANIES_PLAN.md §2), so any company's old portal host moves.
        $company = static::query()->where('is_active', true)->where('investor_portal_domain', $host)->first();

        if (! $company && str_starts_with($host, 'investor.')) {
            $company = static::query()->where('is_active', true)->whereNull('investor_portal_domain')->where('domain', substr($host, strlen('investor.')))->first();
        }

        return $company?->imsIsLive() ? $company : null;
    }

    /**
     * The company that carries the group's IMS domain (18_IMS_ALL_COMPANIES_PLAN.md
     * §2): the active company with IMS switched on and a domain set; with more
     * than one, the lowest id. Null while no company runs IMS.
     */
    public static function groupImsCompany(): ?self
    {
        if (! static::imsColumnsExist()) {
            return null;
        }

        return static::query()
            ->where('ims_enabled', true)
            ->where('is_active', true)
            ->whereNotNull('ims_domain')
            ->where('ims_domain', '!=', '')
            ->orderBy('id')
            ->first();
    }

    /** AppSetting key of the one sign-in address for every business (17_IMS_IN_PORTAL_PLAN.md §5). */
    public const IMS_CENTRAL_DOMAIN_SETTING = 'ims_central_domain';

    /**
     * The central investor sign-in host that is not tied to one company
     * (17_IMS_IN_PORTAL_PLAN.md §5), set by a Super Admin in Investor Portal
     * Settings; null while none is set.
     */
    public static function imsCentralHost(): ?string
    {
        if (! Schema::hasTable('app_settings')) {
            return null;
        }

        return static::normalizeDomain((string) AppSetting::getValue(self::IMS_CENTRAL_DOMAIN_SETTING, ''));
    }

    /**
     * Whether $host cannot be the central investor sign-in host because
     * something already answers on it: a company domain, any portal host
     * (custom or the "investor." / "reseller." default), an IMS domain, a
     * Next.js storefront domain, or a reseller's own domain or store
     * subdomain. Unlike hostIsTakenByThePlatform(), a new name under a
     * company's own domain (for example "ims.<company domain>") is allowed.
     */
    public static function centralHostIsTaken(string $host): bool
    {
        $host = static::normalizeDomain($host) ?? '';

        if ($host === '') {
            return true;
        }

        if (static::query()->where('domain', $host)->orWhere('reseller_portal_domain', $host)->orWhere('investor_portal_domain', $host)
            ->when(static::imsColumnsExist(), fn ($query) => $query->orWhere('ims_domain', $host))->exists()) {
            return true;
        }

        if (StorefrontSetting::nextjsDomainTaken($host)
            || static::forInvestorPortalHost($host)
            || static::forResellerPortalHost($host)
            || static::forRetiredInvestorPortalHost($host)
            || static::forResellerCustomDomainHost($host)) {
            return true;
        }

        $subdomain = static::forResellerSubdomainHost($host);

        return $subdomain !== null && Customer::withoutGlobalScopes()
            ->where('company_id', $subdomain[0]->getKey())
            ->where('reseller_subdomain', $subdomain[1])
            ->exists();
    }

    /** The store subdomain labels a reseller of this company may not take: the reserved ones and the central sign-in host's. */
    public function reservedResellerSubdomains(): array
    {
        $reserved = self::RESERVED_RESELLER_SUBDOMAINS;
        $central = static::imsCentralHost();

        if ($central !== null && filled($this->domain) && str_ends_with($central, '.'.$this->domain)) {
            $label = substr($central, 0, -strlen('.'.$this->domain));

            if (! str_contains($label, '.')) {
                $reserved[] = $label;
            }
        }

        return $reserved;
    }

    /** Whether $host is the central investor sign-in host (with or without "www."). */
    public static function isImsCentralHost(?string $host): bool
    {
        $central = static::imsCentralHost();
        $host = static::normalizeDomain($host);

        return $central !== null && $host !== null && $host === $central;
    }

    /** Whether this company has an IMS v2 domain of its own. */
    public function ownsImsDomain(): bool
    {
        return (bool) $this->ims_enabled && filled($this->ims_domain) && (bool) $this->is_active;
    }

    /**
     * The host this company's IMS runs on: its own IMS domain, else the group's
     * (18_IMS_ALL_COMPANIES_PLAN.md §2: every active company uses the one IMS
     * domain). Null while the company is inactive or no company runs IMS.
     */
    public function imsHost(): ?string
    {
        if (! $this->is_active) {
            return null;
        }

        if ($this->ownsImsDomain()) {
            return $this->ims_domain;
        }

        return static::groupImsCompany()?->ims_domain;
    }

    /** Whether this company runs IMS v2: on its own domain or on the group's. */
    public function imsIsLive(): bool
    {
        return $this->imsHost() !== null;
    }

    /** An address on the IMS domain ("" = the investor portal's root), or null while IMS is off. */
    public function imsUrl(string $path = ''): ?string
    {
        $host = $this->imsHost();

        if ($host === null) {
            return null;
        }

        return request()->getScheme().'://'.$host.($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /**
     * A link to one of this company's own investor pages (invite, join,
     * sign-up, staff link): on a shared IMS domain it says which company it is
     * for (?business=<id>), so the page checks the token against that company
     * (18_IMS_ALL_COMPANIES_PLAN.md §2). Null while the portal has no host.
     */
    public function investorLinkUrl(string $path): ?string
    {
        $url = $this->investorPortalUrl($path);

        if ($url === null || $this->ownsImsDomain() || ! $this->imsIsLive()) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'business='.$this->getKey();
    }

    /** An office address that opens this company in the office (?office_company=<id>), or null while IMS is off. */
    public function imsOfficeUrl(string $path = ''): ?string
    {
        $url = $this->imsUrl('office'.($path === '' ? '' : '/'.ltrim($path, '/')));

        return $url === null ? null : $url.(str_contains($url, '?') ? '&' : '?').'office_company='.$this->getKey();
    }

    /** Whether the Trade Portal (/trade) is open for this company (docs/tasneem-samples/PLAN.md §1). */
    public function tradePortalIsOn(): bool
    {
        return (bool) $this->trade_portal_enabled && (bool) $this->is_active;
    }

    /**
     * The BIN a Delivery Challan prints: its own when one is set, else the one
     * of the import papers. The two differ at Tasneem (PLAN.md §4), so both are kept.
     */
    public function challanBin(): ?string
    {
        return filled($this->challan_bin_number) ? $this->challan_bin_number : $this->bin_number;
    }

    /** The investor portal is reachable: switched on itself, or carried by IMS v2. */
    public function investorPortalIsOn(): bool
    {
        return (bool) $this->investor_portal_enabled || $this->imsIsLive();
    }

    protected static ?bool $imsColumnsExist = null;

    protected static function imsColumnsExist(): bool
    {
        // The table is checked every time (as the other host lookups do); only the
        // column check is remembered once it is true.
        if (! Schema::hasTable('companies')) {
            return false;
        }

        if (static::$imsColumnsExist !== true) {
            static::$imsColumnsExist = Schema::hasColumn('companies', 'ims_domain');
        }

        return static::$imsColumnsExist;
    }

    /** The host this company's investor portal opens on; the inverse of forInvestorPortalHost(). */
    public function investorPortalHost(): ?string
    {
        if ($this->imsIsLive()) {
            return $this->imsHost();
        }

        if (! $this->investor_portal_enabled) {
            return null;
        }

        if (filled($this->investor_portal_domain)) {
            return $this->investor_portal_domain;
        }

        return filled($this->domain) ? 'investor.'.$this->domain : null;
    }

    /** The investor portal's sign-in address, or null while the portal is off or has no host. */
    public function investorPortalUrl(string $path = ''): ?string
    {
        $host = $this->investorPortalHost();

        return $host === null ? null : request()->getScheme().'://'.$host.'/investor'.($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /**
     * Labels a reseller may never take as a store subdomain: they either serve
     * the reseller portal / admin / mail or would look like official pages.
     */
    public const RESERVED_RESELLER_SUBDOMAINS = [
        'www', 'reseller', 'resellers', 'investor', 'investors', 'portal', 'admin', 'app', 'api', 'mail', 'smtp', 'ftp',
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

        if ($host === null || ! str_contains($host, '.') || ! Schema::hasTable('companies') || static::isImsCentralHost($host)) {
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

    /**
     * The verified reseller whose own domain is $host, and their company, when
     * that company has custom domains switched on. Looked up without the
     * company scope because the company is exactly what is being resolved.
     *
     * @return array{0: self, 1: Customer}|null
     */
    public static function forResellerCustomDomainHost(?string $host): ?array
    {
        $host = static::normalizeDomain($host);

        if ($host === null || ! Schema::hasTable('companies')) {
            return null;
        }

        $reseller = Customer::withoutGlobalScopes()
            ->withoutTrashed()
            ->where('reseller_custom_domain', $host)
            ->whereNotNull('reseller_custom_domain_verified_at')
            ->where('reseller_status', 'approved')
            ->first();

        if (! $reseller) {
            return null;
        }

        $company = static::query()
            ->whereKey($reseller->company_id)
            ->where('is_active', true)
            ->where('reseller_module_enabled', true)
            ->where('reseller_custom_domains_enabled', true)
            ->first();

        return $company ? [$company, $reseller] : null;
    }

    /**
     * Whether $host is already spoken for by the platform: a company domain,
     * a portal host, or a store subdomain label under a company domain. A
     * reseller may not claim any of these as their own domain.
     */
    public static function hostIsTakenByThePlatform(string $host): bool
    {
        $host = static::normalizeDomain($host) ?? '';

        if ($host === '' || static::isImsCentralHost($host)) {
            return true;
        }

        if (static::query()->where('domain', $host)->orWhere('reseller_portal_domain', $host)->orWhere('investor_portal_domain', $host)
            ->when(static::imsColumnsExist(), fn ($query) => $query->orWhere('ims_domain', $host))->exists()) {
            return true;
        }

        // A company's headless Next.js storefront domain is just as reserved.
        if (StorefrontSetting::nextjsDomainTaken($host)) {
            return true;
        }

        // Anything underneath a company's domain ("x.<company domain>") is the
        // company's namespace -- subdomain stores and "reseller." live there.
        $parent = str_contains($host, '.') ? explode('.', $host, 2)[1] : '';

        return $parent !== '' && static::query()->where('domain', $parent)->exists();
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

    /**
     * Whether the portal host's front page is the public reseller landing page
     * (the guide, with its sign-in and apply buttons). On unless it is switched
     * off in Resellers → Reseller Settings; off, the host goes straight to the
     * portal's sign-in page.
     */
    public function resellerLandingEnabled(): bool
    {
        return (bool) ($this->storefrontSetting?->reseller_guide_enabled ?? true);
    }

    /**
     * Where the storefront's "Become a reseller" links go: the landing page
     * that is the portal host's front page while it is on (Resellers →
     * Reseller Settings), otherwise straight to the portal's application form.
     * Null when this company has no portal host.
     */
    public function resellerJoinUrl(): ?string
    {
        $host = $this->resellerPortalHost();

        if ($host === null) {
            return null;
        }

        return request()->getScheme().'://'.$host.($this->resellerLandingEnabled() ? '/' : '/portal/register');
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

    /**
     * The company_id a new company-owned model will end up with once
     * `BelongsToCompany`'s `creating` hook runs: the active `CompanyContext`
     * company, falling back to `defaultCompanyId()`. Exposed so a model's
     * own `saving` hook (which fires *before* `creating` — see
     * `Model::save()`/`performInsert()` — so `$model->company_id` is still
     * blank there on a brand-new record) can validate against the
     * company this record is actually about to get, instead of against a
     * not-yet-set null.
     */
    public static function resolveActiveCompanyId(): ?int
    {
        if (app()->bound(CompanyContext::class) && app(CompanyContext::class)->hasCompany()) {
            return app(CompanyContext::class)->id();
        }

        return static::defaultCompanyId();
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
