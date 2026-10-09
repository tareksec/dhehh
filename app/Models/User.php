<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\ValidatesEmailAddress;
use App\Support\AppDeployment;
use App\Support\AppRelease;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, ValidatesEmailAddress;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (
                Schema::hasColumn('users', 'acknowledged_app_deployment_id')
                && blank($user->acknowledged_app_deployment_id)
            ) {
                $deployment = AppDeployment::current();
                $release = AppRelease::latestPublished();

                $user->acknowledged_app_deployment_id = $deployment['deployment_id'] ?? null;
                $user->app_upgrade_acknowledged_at = now();

                if (Schema::hasColumn('users', 'acknowledged_app_version')) {
                    $user->acknowledged_app_version = $release['version'] ?? null;
                    $user->acknowledged_app_commit = $deployment['commit'] ?? null;
                    $user->acknowledged_app_built_at = $deployment['built_at'] ?? null;
                }
            }
        });

        static::saving(function (User $user): void {
            static::validateEmailAttribute($user, required: true);

            if (! $user->exists) {
                return;
            }

            if (Auth::id() === $user->getKey() && $user->isDirty('is_active') && $user->is_active === false) {
                throw ValidationException::withMessages([
                    'is_active' => 'You cannot deactivate your own user account.',
                ]);
            }

            $wasSuperAdmin = $user->getOriginal('role') === 'super_admin';
            $willRemainActiveSuperAdmin = $user->effectiveRole() === 'super_admin' && $user->is_active !== false;

            if ($wasSuperAdmin && ! $willRemainActiveSuperAdmin && static::activeSuperAdminCount($user->getKey()) === 0) {
                throw ValidationException::withMessages([
                    'role' => 'At least one active Super Admin must remain.',
                ]);
            }
        });

        static::deleting(function (User $user): void {
            if (Auth::id() === $user->getKey()) {
                throw ValidationException::withMessages([
                    'user' => 'You cannot delete your own user account.',
                ]);
            }

            if ($user->isSuperAdmin() && $user->is_active !== false && static::activeSuperAdminCount($user->getKey()) === 0) {
                throw ValidationException::withMessages([
                    'user' => 'At least one active Super Admin must remain.',
                ]);
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'app_upgrade_acknowledged_at' => 'datetime',
            'acknowledged_app_built_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active !== false;
    }

    public const ROLES = [
        'super_admin' => 'Super Admin',
        'manager' => 'Manager',
        'sales_staff' => 'Sales Staff',
        'inventory_staff' => 'Inventory Staff',
        'accountant' => 'Accountant',
    ];

    public const ROLE_PERMISSIONS = [
        'super_admin' => ['*'],
        'manager' => [
            'dashboard.view',
            'sales.view',
            'sales.create',
            'sales.update',
            'purchasing.view',
            'purchasing.create',
            'purchasing.update',
            'inventory.view',
            'inventory.create',
            'inventory.update',
            'accounts.view',
            'accounts.create',
            'accounts.update',
            'reports.view',
            'reports.export',
            'crm.view',
            'crm.manage',
            'voucher.create',
            'voucher.view',
            'voucher.view_all',
            'voucher.verify',
            'voucher.approve',
            'voucher.reject',
            'voucher.cancel',
            'fund_source.manage',
            'fund_transfer.create',
            'fund_transfer.approve',
            'finance.dashboard',
            'investments.view',
            'investments.manage',
            'marketing.view',
            'marketing.create',
            'marketing.update',
            'marketing.delete',
            'ai_tools.menu',
            'ai_tools.image_generation',
            'ai_tools.image_generation.review',
            'ai_tools.video_generation',
            'ai_tools.video_generation.review',
            'notifications.orders',
            'notifications.order_status',
            'notifications.staff',
            'notifications.company',
        ],
        'sales_staff' => [
            'dashboard.view',
            'sales.view',
            'sales.create',
            'sales.update',
            'inventory.view',
            'reports.view',
            'crm.view',
            'crm.manage',
            'voucher.create',
            'voucher.view',
            'notifications.orders',
            'notifications.order_status',
        ],
        'inventory_staff' => [
            'dashboard.view',
            'inventory.view',
            'inventory.create',
            'inventory.update',
            'purchasing.view',
            'reports.view',
            'voucher.create',
            'voucher.view',
            'notifications.orders',
        ],
        'accountant' => [
            'dashboard.view',
            'sales.view',
            'purchasing.view',
            'accounts.view',
            'accounts.create',
            'accounts.update',
            'reports.view',
            'reports.export',
            'voucher.create',
            'voucher.view',
            'voucher.view_all',
            'voucher.verify',
            'voucher.cancel',
            'fund_source.manage',
            'fund_transfer.create',
            'finance.dashboard',
            'investments.view',
            'notifications.orders',
            'notifications.order_status',
        ],
    ];

    public const CUSTOM_PERMISSION_OPTIONS = [
        'dashboard.view' => 'Dashboard: View',
        'sales.view' => 'Sales: View',
        'sales.create' => 'Sales: Create',
        'sales.update' => 'Sales: Update',
        'sales.delete' => 'Sales: Delete',
        'purchasing.view' => 'Purchasing: View',
        'purchasing.create' => 'Purchasing: Create',
        'purchasing.update' => 'Purchasing: Update',
        'purchasing.delete' => 'Purchasing: Delete',
        'inventory.view' => 'Inventory: View',
        'inventory.create' => 'Inventory: Create',
        'inventory.update' => 'Inventory: Update',
        'inventory.delete' => 'Inventory: Delete',
        'accounts.view' => 'Accounts: View',
        'accounts.create' => 'Accounts: Create',
        'accounts.update' => 'Accounts: Update',
        'accounts.delete' => 'Accounts: Delete',
        'expenses.ai_scan' => 'Expenses: AI Scan (upload photo, review and publish)',
        'reports.view' => 'Reports: View',
        'reports.export' => 'Reports: Export',
        'crm.view' => 'CRM Inbox: View',
        'crm.manage' => 'CRM Inbox: Reply and Manage',
        'backups.manage' => 'Backups: Manage',
        'settings.manage' => 'Settings: Manage',
        'users.manage' => 'Users: Manage',
        'voucher.create' => 'Voucher: Create',
        'voucher.view' => 'Voucher: View (own)',
        'voucher.view_all' => 'Voucher: View All',
        'voucher.verify' => 'Voucher: Verify',
        'voucher.approve' => 'Voucher: Approve',
        'voucher.reject' => 'Voucher: Reject',
        'voucher.cancel' => 'Voucher: Cancel',
        'voucher.delete' => 'Voucher: Move to Trash, Restore and Delete Permanently',
        'fund_source.manage' => 'Fund Source: Manage',
        'fund_transfer.create' => 'Fund Transfer: Create',
        'fund_transfer.approve' => 'Fund Transfer: Approve',
        'finance.dashboard' => 'Finance Dashboard: View',
        'investments.view' => 'Investments: View',
        'investments.manage' => 'Investments: Manage Projects and Investors',
        'investments.settle' => 'Investments: Calculate Settlements and Pay Payouts',
        'investments.manage_channel_partner' => 'Investments: Change Assigned Channel Partner',
        'investments.override_investment_window' => 'Investments: Add Investment After Window Closes',
        'investments.portal_manage' => 'Investments: Manage Investor Portal Access',
        'investments.payout_batch_create' => 'Investments: Make Payout Batches',
        'investments.payout_batch_approve' => 'Investments: Approve Payout Batches',
        'marketing.view' => 'Marketing/Ads: View',
        'marketing.create' => 'Marketing/Ads: Create',
        'marketing.update' => 'Marketing/Ads: Update',
        'marketing.delete' => 'Marketing/Ads: Delete',
        'ai_tools.menu' => 'AI Tools: Open Tool Menu',
        'ai_tools.image_generation' => 'AI Tools: Image Generation (generate + attach)',
        'ai_tools.image_generation.review' => 'AI Tools: Image Generation — approve others’ images for attaching',
        'ai_tools.video_generation' => 'AI Tools: Video Generation (generate + attach)',
        'ai_tools.video_generation.review' => 'AI Tools: Video Generation — approve others’ videos for attaching',
        'ai_tools.content_creation' => 'AI Tools: Content Creation (reserved — not built yet)',
        'notifications.orders' => 'Notifications: New Orders',
        'notifications.order_status' => 'Notifications: Order Status Changes',
        'notifications.staff' => 'Notifications: New Staff/User Added',
        'notifications.company' => 'Notifications: Company Info Changes',
    ];

    public const MODEL_MODULES = [
        Customer::class => 'sales',
        Order::class => 'sales',
        CustomerPayment::class => 'sales',
        ResellerCommission::class => 'sales',
        ResellerPayoutRequest::class => 'sales',
        Supplier::class => 'purchasing',
        Purchase::class => 'purchasing',
        SupplierPayment::class => 'purchasing',
        Product::class => 'inventory',
        Category::class => 'inventory',
        StockMovement::class => 'inventory',
        Account::class => 'accounts',
        Expense::class => 'accounts',
        ExpenseCategory::class => 'accounts',
        TransactionLedger::class => 'accounts',
        InvestmentProject::class => 'investments',
        Investor::class => 'investments',
        InvestorDraft::class => 'investments',
        Investment::class => 'investments',
        ProjectCostItem::class => 'investments',
        InvestorSecurityInstrument::class => 'investments',
        InvestmentWitness::class => 'investments',
        InvestmentDocument::class => 'investments',
        InvestorCycleElection::class => 'investments',
        InvestmentWithdrawalNotice::class => 'investments',
        ProjectSettlement::class => 'investments',
        SettlementPayout::class => 'investments',
        ChannelPartner::class => 'investments',
        ChannelPartnerPayout::class => 'investments',
        InvestorPortalAccount::class => 'investments',
        InvestorBankAccount::class => 'investments',
        AgreementTemplate::class => 'investments',
        AgreementAcceptance::class => 'investments',
        InvestorRequest::class => 'investments',
        PayoutBatch::class => 'investments',
        PayoutBatchItem::class => 'investments',
        MetaAdAccount::class => 'marketing',
        MetaAdCampaign::class => 'marketing',
        MetaAdSet::class => 'marketing',
        MetaAd::class => 'marketing',
        MetaAdProposal::class => 'marketing',
        MetaAudience::class => 'marketing',
        self::class => 'users',
        AuditLog::class => 'users',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->effectiveRole() === 'super_admin';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->is_active === false) {
            return false;
        }

        $permissions = $this->rolePermissions();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function roleOptions(): array
    {
        if (! self::userRolesTableExists()) {
            return self::ROLES;
        }

        $customRoles = UserRole::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();

        return array_merge(self::ROLES, $customRoles);
    }

    public static function defaultRole(): ?string
    {
        $options = self::roleOptions();

        return array_key_exists('sales_staff', $options)
            ? 'sales_staff'
            : array_key_first($options);
    }

    public function rolePermissions(): array
    {
        $role = $this->effectiveRole();

        if (array_key_exists($role, self::ROLE_PERMISSIONS)) {
            return self::ROLE_PERMISSIONS[$role];
        }

        if (! self::userRolesTableExists()) {
            return [];
        }

        return UserRole::query()
            ->where('slug', $role)
            ->where('is_active', true)
            ->value('permissions') ?? [];
    }

    public function effectiveRole(): string
    {
        return $this->getAttribute('role') ?: 'sales_staff';
    }

    public function canManageUsers(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('users.manage');
    }

    /**
     * Owner's rule: only a Super Admin changes permissions — a user's role
     * (their own included) and what each role is allowed to do. "Users:
     * Manage" still covers everything else about user accounts.
     */
    public function canChangePermissions(): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * Companies this user may give (or take away) access to on the Users
     * screen: every active company for a Super Admin, otherwise only their
     * own active companies.
     *
     * @return list<int>
     */
    public function assignableCompanyIds(): array
    {
        $query = $this->isSuperAdmin()
            ? Company::query()->where('is_active', true)->pluck('id')
            : $this->companies()->where('companies.is_active', true)->pluck('companies.id');

        return $query->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * Applies the owner's rule (2026-10-07) to a company-access change made by
     * this user: a non-Super-Admin can only add or remove companies they can
     * access themselves. The target's access to any other company is kept as
     * it was, and the default company only moves to one of this user's own.
     *
     * @param  list<int>  $requestedIds
     * @return array{0: list<int>, 1: ?int}
     */
    public function resolveCompanyAccessChange(array $requestedIds, ?int $requestedDefault, ?User $target = null): array
    {
        if ($this->isSuperAdmin()) {
            return [$requestedIds, $requestedDefault];
        }

        $allowed = $this->assignableCompanyIds();
        $kept = [];
        $currentDefault = null;

        if ($target?->exists) {
            $kept = array_diff(
                $target->companies()->pluck('companies.id')->map(fn ($id): int => (int) $id)->all(),
                $allowed,
            );
            $currentDefault = $target->companies()->wherePivot('is_default', true)->value('companies.id');
            $currentDefault = $currentDefault === null ? null : (int) $currentDefault;
        }

        $default = match (true) {
            $requestedDefault !== null && in_array($requestedDefault, $allowed, true) => $requestedDefault,
            $currentDefault !== null && in_array($currentDefault, $kept, true) => $currentDefault,
            default => null,
        };

        return [
            array_values(array_unique([...array_intersect($requestedIds, $allowed), ...$kept])),
            $default,
        ];
    }

    /**
     * Owner's rule (2026-10-07): apart from a Super Admin, a user only sees and
     * edits the users of their own companies (and themselves).
     */
    public function canSeeUser(User $target): bool
    {
        if ($this->isSuperAdmin() || $this->is($target)) {
            return true;
        }

        return $target->companies()->whereIn('companies.id', $this->assignableCompanyIds())->exists();
    }

    /**
     * Narrows a users query to the ones this user may see (see canSeeUser()).
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if ($viewer->isSuperAdmin()) {
            return $query;
        }

        $companyIds = $viewer->assignableCompanyIds();

        return $query->where(fn (Builder $query) => $query
            ->whereKey($viewer->getKey())
            ->orWhereHas('companies', fn (Builder $companies) => $companies->whereIn('companies.id', $companyIds)));
    }

    /**
     * "Users: Manage" must not be a path to taking over a Super Admin: only a
     * Super Admin may change, delete or reset the password of one.
     */
    public function canModifyUser(User $target): bool
    {
        return ! $target->isSuperAdmin() || $this->isSuperAdmin();
    }

    public function canEditPayments(): bool
    {
        return $this->isSuperAdmin() || $this->effectiveRole() === 'accountant';
    }

    public function canEditStockMovements(): bool
    {
        return $this->isSuperAdmin() || in_array($this->effectiveRole(), ['manager', 'inventory_staff'], true);
    }

    public function canEditAccounts(): bool
    {
        return $this->isSuperAdmin() || $this->effectiveRole() === 'accountant';
    }

    public function canEditExpenses(): bool
    {
        return $this->isSuperAdmin() || $this->effectiveRole() === 'accountant';
    }

    public function canUseExpenseScan(): bool
    {
        return $this->hasPermission('expenses.ai_scan');
    }

    public function canDeleteSensitiveRecords(): bool
    {
        return $this->isSuperAdmin();
    }

    public function canAccessReports(): bool
    {
        return $this->hasPermission('reports.view');
    }

    public function canExportReports(): bool
    {
        return $this->hasPermission('reports.export');
    }

    public function canManageBackups(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('backups.manage');
    }

    public function canManageSettings(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('settings.manage');
    }

    public function canCreateVoucher(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('voucher.create');
    }

    /** Trash, restore and permanently delete vouchers (owner, 2026-09-28). */
    public function canDeleteVoucher(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('voucher.delete');
    }

    public function canViewAllVouchers(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('voucher.view_all');
    }

    public function canVerifyVoucher(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('voucher.verify');
    }

    public function canApproveVoucher(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('voucher.approve');
    }

    public function canRejectVoucher(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('voucher.reject');
    }

    public function canCancelVoucher(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('voucher.cancel');
    }

    public function canManageFundSources(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('fund_source.manage');
    }

    public function canCreateFundTransfer(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('fund_transfer.create');
    }

    public function canApproveFundTransfer(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('fund_transfer.approve');
    }

    public function canAccessFinanceDashboard(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermission('finance.dashboard');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['role', 'is_default'])
            ->withTimestamps();
    }

    public function pushDevices(): HasMany
    {
        return $this->hasMany(PushDevice::class);
    }

    public function accessibleCompanies(): Builder|BelongsToMany
    {
        if ($this->isSuperAdmin()) {
            return Company::query()
                ->where('is_active', true)
                ->orderBy('name');
        }

        return $this->companies()
            ->where('companies.is_active', true)
            ->orderBy('companies.name');
    }

    public function defaultCompany(): ?Company
    {
        return $this->companies()
            ->wherePivot('is_default', true)
            ->where('companies.is_active', true)
            ->first();
    }

    /**
     * Persists the header company switcher's choice (a company id, or
     * "all" for Super Admins) so SetCurrentCompany can restore it when a
     * new session starts — after logout, closing the app or an app update.
     */
    public function rememberCompanySelection(int|string|null $selection): void
    {
        $selection = $selection === null ? null : (string) $selection;

        if ($this->last_company_selection !== $selection) {
            $this->forceFill(['last_company_selection' => $selection])->saveQuietly();
        }
    }

    public function canAccessCompany(int $companyId): bool
    {
        if ($this->isSuperAdmin()) {
            return Company::query()
                ->whereKey($companyId)
                ->where('is_active', true)
                ->exists();
        }

        return $this->companies()
            ->whereKey($companyId)
            ->where('companies.is_active', true)
            ->exists();
    }

    public function canPerformModelAbility(string $ability, string $modelClass): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $module = self::MODEL_MODULES[$modelClass] ?? null;

        if (! $module) {
            return false;
        }

        if ($module === 'users') {
            return $this->canManageUsers();
        }

        if ($module === 'investments') {
            return match ($ability) {
                'viewAny', 'view' => $this->hasPermission('investments.view'),
                'create', 'update' => $this->hasPermission('investments.manage'),
                // No dedicated investments.delete permission exists — a Resource's
                // own canDelete() (e.g. InvestorResource: no investments/referrals
                // left) adds the real guard on top of this (v3 P2.2).
                'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny' => $this->hasPermission('investments.manage'),
                default => false,
            };
        }

        $permissionAbility = match ($ability) {
            'viewAny', 'view' => 'view',
            'create' => 'create',
            'update' => 'update',
            'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny' => 'delete',
            default => null,
        };

        if (! $permissionAbility) {
            return false;
        }

        return $this->hasPermission("{$module}.{$permissionAbility}");
    }

    protected static function activeSuperAdminCount(?int $excludingUserId = null): int
    {
        return static::query()
            ->where('role', 'super_admin')
            ->where('is_active', true)
            ->when($excludingUserId, fn ($query) => $query->whereKeyNot($excludingUserId))
            ->count();
    }

    protected static function userRolesTableExists(): bool
    {
        return Schema::hasTable('user_roles');
    }
}
