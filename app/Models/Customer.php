<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ValidatesEmailAddress;
use App\Services\CompanyContext;
use App\Services\ResellerNotificationService;
use App\Support\CompanyMedia;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Also doubles as the storefront customer-login Authenticatable (guard
 * "customer" in config/auth.php). A Customer row starts as a plain CRM/order
 * record with no password; it only becomes a login-capable account once
 * CustomerAccountService::register() sets one, so admin/checkout-created
 * customers are unaffected.
 *
 * Also the reseller-portal Filament user (panel "reseller"). The portal's own
 * middleware resolves the company that owns the current portal host into
 * CompanyContext before auth runs, so canAccessPanel() only has to check that
 * this record belongs to that same company.
 */
class Customer extends Model implements AuthenticatableContract, FilamentUser, HasName
{
    use Authenticatable, BelongsToCompany, ValidatesEmailAddress;

    public const TYPES = [
        'regular' => 'Regular',
        'retail' => 'Retail',
        'wholesale' => 'Wholesale',
        'vip' => 'VIP',
    ];

    public const SOURCES = [
        'walk_in' => 'Walk-in',
        'facebook' => 'Facebook',
        'website' => 'Website',
        'referral' => 'Referral',
        'phone_call' => 'Phone Call',
        'other' => 'Other',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'email',
        'address',
        'customer_type',
        'customer_source',
        'reseller_status',
        'reseller_level_id',
        'business_name',
        'reseller_note',
        'reseller_slug',
        'reseller_subdomain',
        'reseller_subdomain_changed_at',
        'reseller_subdomain_live_at',
        'reseller_custom_domain',
        'reseller_custom_domain_verified_at',
        'reseller_store_name',
        'reseller_logo',
        'reseller_logo_dark',
        'opening_balance',
        'current_balance',
        'is_active',
        'opted_out_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'password_reset_code',
        'login_otp_code',
    ];

    public const RESELLER_STATUSES = [
        'none' => 'Not a reseller',
        'pending' => 'Application pending',
        'approved' => 'Approved reseller',
        'rejected' => 'Rejected',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
        'password_reset_expires_at' => 'datetime',
        'login_otp_expires_at' => 'datetime',
        'login_otp_sent_at' => 'datetime',
        'login_otp_attempts' => 'integer',
        'opted_out_at' => 'datetime',
        'reseller_custom_domain_verified_at' => 'datetime',
        'reseller_subdomain_changed_at' => 'datetime',
        'reseller_subdomain_live_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            static::validateEmailAttribute($customer);
        });

        static::saved(function (Customer $customer): void {
            $customer->syncCurrentBalance();
        });

        // The approval SMS (wording set in Reseller Settings; empty = not sent).
        static::updated(function (Customer $customer): void {
            if ($customer->wasChanged('reseller_status') && $customer->reseller_status === 'approved') {
                app(ResellerNotificationService::class)->notify(ResellerNotificationService::EVENT_APPROVED, $customer);
            }
        });
    }

    /**
     * Whether this record has a storefront login (password set). Every
     * Customer starts as a plain CRM/order record with no login.
     */
    public function isRegistered(): bool
    {
        return filled($this->password);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Orders placed through this reseller's storefront (distinct from
     * orders() above, which is every order where this Customer is the
     * buyer -- a reseller and their storefront's buyers are never
     * conflated, even when the reseller buys from their own store).
     */
    public function resellerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'reseller_customer_id');
    }

    public function resellerProducts(): HasMany
    {
        return $this->hasMany(ResellerProduct::class);
    }

    /**
     * Products this reseller has picked for their storefront, regardless
     * of the pivot's is_active flag -- callers filtering for the public
     * storefront should additionally constrain wherePivot('is_active', true).
     */
    public function resellerCatalog(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'reseller_products')
            ->withPivot(['is_active'])
            ->withTimestamps();
    }

    public function depositEntries(): HasMany
    {
        return $this->hasMany(ResellerDepositEntry::class);
    }

    public function resellerCommissions(): HasMany
    {
        return $this->hasMany(ResellerCommission::class);
    }

    public function resellerPayoutRequests(): HasMany
    {
        return $this->hasMany(ResellerPayoutRequest::class);
    }

    public function resellerLevel(): BelongsTo
    {
        return $this->belongsTo(ResellerLevel::class, 'reseller_level_id');
    }

    /**
     * What a returned order costs this reseller: their level's own return charge
     * when the level sets one, otherwise the company's amount from Reseller
     * Settings (owner decision 2026-10-16).
     */
    public function resellerReturnCharge(): float
    {
        $own = $this->resellerLevel?->return_charge;

        if ($own !== null) {
            return round((float) $own, 2);
        }

        return round((float) (StorefrontSetting::bladeFor((int) $this->company_id)?->reseller_return_charge ?? 0), 2);
    }

    /** The smallest withdrawal this reseller may ask for: their level's amount when it sets one, else the company's. */
    public function resellerMinWithdrawal(): float
    {
        $own = $this->resellerLevel?->min_withdrawal;

        if ($own !== null) {
            return round((float) $own, 2);
        }

        return round((float) (StorefrontSetting::bladeFor((int) $this->company_id)?->reseller_min_withdrawal ?? 0), 2);
    }

    public function resellerPayoutAccounts(): HasMany
    {
        return $this->hasMany(ResellerPayoutAccount::class);
    }

    public function isApprovedReseller(): bool
    {
        return $this->reseller_status === 'approved';
    }

    /**
     * The public address of this reseller's own store,
     * {company-domain}/store/{slug} -- null until they have a slug and the
     * company has a domain.
     */
    public function resellerStoreUrl(): ?string
    {
        if ($this->hasLiveCustomDomain()) {
            return 'https://'.$this->reseller_custom_domain;
        }

        $subdomainHost = $this->company?->resellerSubdomainHost($this->reseller_subdomain);

        // While a new subdomain is still being set up, share the long link
        // (which already works) instead of an address that does not open yet.
        if ($subdomainHost && $this->resellerSubdomainIsReady()) {
            return 'https://'.$subdomainHost;
        }

        $domain = $this->company?->domain;

        if (blank($this->reseller_slug) || blank($domain)) {
            return null;
        }

        return 'https://'.$domain.route('storefront.reseller_store.products.index', $this->reseller_slug, false);
    }

    /** Public URL of the store-header logo for light mode (falls back to the dark one if only that was uploaded). */
    public function resellerLogoUrl(): ?string
    {
        return $this->logoUrl($this->reseller_logo) ?? $this->logoUrl($this->reseller_logo_dark);
    }

    /** Dark-mode logo; only set when there is a light one too, so the header can switch between them. */
    public function resellerLogoDarkUrl(): ?string
    {
        return filled($this->reseller_logo) ? $this->logoUrl($this->reseller_logo_dark) : null;
    }

    protected function logoUrl(?string $path): ?string
    {
        return filled($path) ? CompanyMedia::publicUrl($path, $this->company) : null;
    }

    /**
     * A chosen subdomain is ready once its DNS + SSL answered (live_at), or
     * the company's set-up time has passed since it was chosen. A subdomain
     * chosen before this was tracked (no changed_at) is already live.
     */
    public function resellerSubdomainIsReady(): bool
    {
        if (blank($this->reseller_subdomain)) {
            return false;
        }

        if ($this->reseller_subdomain_live_at !== null || $this->reseller_subdomain_changed_at === null) {
            return true;
        }

        return ! $this->resellerSubdomainReadyAt()->isFuture();
    }

    /** When a newly chosen subdomain is expected to be live (changed_at + the company's set-up minutes). */
    public function resellerSubdomainReadyAt(): ?Carbon
    {
        if ($this->reseller_subdomain_changed_at === null) {
            return null;
        }

        $minutes = (int) ($this->company?->storefrontSetting?->reseller_subdomain_setup_minutes ?? 10);

        return $this->reseller_subdomain_changed_at->copy()->addMinutes(max(1, $minutes));
    }

    /** Their own domain is saved, verified, and the company still allows custom domains. */
    public function hasLiveCustomDomain(): bool
    {
        return filled($this->reseller_custom_domain)
            && $this->reseller_custom_domain_verified_at !== null
            && (bool) $this->company?->reseller_custom_domains_enabled
            && (bool) $this->company?->reseller_module_enabled;
    }

    /**
     * The name shown in the header of this reseller's own store -- only the
     * reseller's own name/brand, never the company's (owner's answer,
     * 13_RESELLER_PORTAL_PLAN.md §2.1 Q3).
     */
    public function resellerStoreName(): string
    {
        return filled($this->reseller_store_name)
            ? $this->reseller_store_name
            : ($this->business_name ?: $this->name);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'reseller') {
            return false;
        }

        if ($this->reseller_status === 'rejected' || ! $this->is_active) {
            return false;
        }

        // Pending/approved may both sign in -- the portal itself shows a
        // status page instead of the dashboard while pending or rejected
        // (see ResellerPortal\Pages\ApplicationStatus).
        if (! in_array($this->reseller_status, ['pending', 'approved'], true)) {
            return false;
        }

        $company = app(CompanyContext::class)->company();

        return $company !== null
            && $company->getKey() === $this->company_id
            && $company->reseller_module_enabled;
    }

    public function getFilamentName(): string
    {
        return $this->business_name ?: $this->name;
    }

    /**
     * Generates a company-unique reseller_slug from business_name (falling
     * back to name) the first time a reseller is approved. Never overwrites
     * an existing slug -- once set, changing it is the reseller's own call
     * via the self-service store page.
     */
    public function ensureResellerSlug(): void
    {
        if (filled($this->reseller_slug)) {
            return;
        }

        $base = Str::slug($this->business_name ?: $this->name) ?: 'store';
        $slug = $base;
        $suffix = 2;

        while (
            static::query()
                ->where('company_id', $this->company_id)
                ->where('reseller_slug', $slug)
                ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        $this->reseller_slug = $slug;
    }

    public function storefrontActivities(): HasMany
    {
        return $this->hasMany(StorefrontCustomerActivity::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function riskProfile(): HasOne
    {
        return $this->hasOne(CustomerRiskProfile::class)->latestOfMany();
    }

    public function originLead(): HasOne
    {
        return $this->hasOne(Lead::class, 'converted_customer_id');
    }

    public static function typeOptions(): array
    {
        $customTypes = static::query()
            ->whereNotNull('customer_type')
            ->where('customer_type', '!=', '')
            ->distinct()
            ->pluck('customer_type')
            ->mapWithKeys(fn (string $type): array => [$type => static::typeLabel($type)])
            ->all();

        return self::TYPES + $customTypes;
    }

    public static function typeKey(string $type): string
    {
        $type = trim($type);

        return Str::limit($type, 50, '') ?: 'regular';
    }

    public static function typeLabel(?string $type): ?string
    {
        if (blank($type)) {
            return null;
        }

        if (array_key_exists($type, self::TYPES)) {
            return self::TYPES[$type];
        }

        if (str_contains($type, ' ') && preg_match('/[A-Z]/', $type)) {
            return $type;
        }

        return Str::of($type)->replace(['_', '-'], ' ')->title()->toString();
    }

    public static function sourceOptions(): array
    {
        $customSources = static::query()
            ->whereNotNull('customer_source')
            ->where('customer_source', '!=', '')
            ->distinct()
            ->pluck('customer_source')
            ->mapWithKeys(fn (string $source): array => [$source => static::sourceLabel($source)])
            ->all();

        return self::SOURCES + $customSources;
    }

    public static function sourceKey(string $source): string
    {
        $source = trim($source);

        return Str::limit($source, 50, '') ?: 'other';
    }

    public static function sourceLabel(?string $source): ?string
    {
        if (blank($source)) {
            return null;
        }

        if (array_key_exists($source, self::SOURCES)) {
            return self::SOURCES[$source];
        }

        if (str_contains($source, ' ') && preg_match('/[A-Z]/', $source)) {
            return $source;
        }

        return Str::of($source)->replace(['_', '-'], ' ')->title()->toString();
    }

    public function syncCurrentBalance(): void
    {
        $balance = (float) $this->opening_balance + (float) $this->orders()
            ->whereIn('status', Order::ACCOUNTED_STATUSES)
            ->sum('due_amount') - (float) $this->payments()->sum('amount');

        if ($this->current_balance != $balance) {
            $this->forceFill(['current_balance' => $balance])->saveQuietly();
        }
    }
}
