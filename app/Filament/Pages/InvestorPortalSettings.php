<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Investments;
use App\Models\Company;
use App\Models\InvestorPortalAccount;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Investor portal on/off and its address for the current company
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §7.1). The custom domain is
 * Super Admin only, like the reseller portal's.
 */
class InvestorPortalSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $cluster = Investments::class;

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.investor-portal-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Investor Portal Settings');
    }

    public function getTitle(): string
    {
        return __('Investor Portal Settings');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('investments.portal_manage') ?? false;
    }

    public function company(): ?Company
    {
        $context = app(CompanyContext::class);

        return $context->isAllCompanies() ? null : $context->company();
    }

    public function mount(): void
    {
        $company = $this->company();

        if (! $company) {
            return;
        }

        $this->form->fill([
            'investor_portal_enabled' => (bool) $company->investor_portal_enabled,
            'investor_portal_domain' => $company->investor_portal_domain,
            'ims_enabled' => (bool) $company->ims_enabled,
            'ims_domain' => $company->ims_domain,
        ]);
    }

    protected static function isSuperAdmin(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Investor portal'))
                    ->description(__('Investors and channel partners sign in with their phone to see their own investments, statement, payouts, documents and commissions. Nobody can sign in until you invite them from their page.'))
                    ->schema([
                        Toggle::make('investor_portal_enabled')
                            ->label(__('Investor portal enabled')),
                        TextInput::make('investor_portal_domain')
                            ->label(__('Investor portal domain'))
                            ->helperText(__('Leave blank to use "investor." + this company\'s own domain. Super Admin only.'))
                            ->maxLength(255)
                            ->disabled(fn (): bool => ! static::isSuperAdmin()),
                        Text::make(fn (): HtmlString|string => $this->portalAddress()),
                    ]),
                Section::make(__('IMS v2 on its own domain'))
                    ->description(__('IMS v2 opens on a separate main domain you bought (not a subdomain): the investor portal at the domain itself, the IMS office at /office. Point the domain and its www to the server first (Cloudflare and Coolify, see IMS-V2/ARCHITECTURE.md). Super Admin only.'))
                    ->schema([
                        Toggle::make('ims_enabled')
                            ->label(__('IMS v2 switched on'))
                            ->disabled(fn (): bool => ! (Auth::user()?->hasPermission('ims.domain.manage') ?? false)),
                        TextInput::make('ims_domain')
                            ->label(__('IMS domain'))
                            ->placeholder('example.com')
                            ->helperText(__('The main domain only, for example example.com. www.example.com opens it too.'))
                            ->maxLength(255)
                            ->disabled(fn (): bool => ! (Auth::user()?->hasPermission('ims.domain.manage') ?? false)),
                        Text::make(fn (): HtmlString|string => $this->imsAddress()),
                    ]),
                Section::make(__('Messages'))
                    ->schema([
                        Text::make(__('The SMS investors get (sign-in and password-reset codes, project started, completed and profit settled) are written on Settings → SMS Templates. The SMS gateway is set on Settings → Integrations.')),
                    ]),
                Section::make(__('Accounts'))
                    ->schema([
                        Text::make(fn (): string => __('Active: :active · Invited, password not set yet: :invited · Disabled: :disabled', $this->accountCounts())),
                    ]),
            ]);
    }

    /** @return array{active: int, invited: int, disabled: int} */
    protected function accountCounts(): array
    {
        $counts = InvestorPortalAccount::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'active' => (int) ($counts[InvestorPortalAccount::STATUS_ACTIVE] ?? 0),
            'invited' => (int) ($counts[InvestorPortalAccount::STATUS_INVITED] ?? 0),
            'disabled' => (int) ($counts[InvestorPortalAccount::STATUS_DISABLED] ?? 0),
        ];
    }

    protected function portalAddress(): HtmlString|string
    {
        $company = $this->company();
        $url = $company?->investorPortalUrl();

        if ($url === null) {
            return __('The portal has no address yet: turn it on, and give this company a domain (or set a portal domain).');
        }

        $text = e(__('Portal address (add it to the server\'s domains first, see docs/deployment.md):'));

        return new HtmlString($text.' <a class="text-primary-600 underline" href="'.e($url).'" target="_blank" rel="noopener">'.e($url).'</a>');
    }

    protected function imsAddress(): HtmlString|string
    {
        $company = $this->company();

        if (! $company?->imsIsLive()) {
            return __('IMS v2 is off. Investors keep using the portal address above.');
        }

        $portal = (string) $company->imsUrl();
        $office = (string) $company->imsUrl('office');

        return new HtmlString(e(__('Investor portal:')).' <a class="text-primary-600 underline" href="'.e($portal).'" target="_blank" rel="noopener">'.e($portal).'</a> · '
            .e(__('IMS office:')).' <a class="text-primary-600 underline" href="'.e($office).'" target="_blank" rel="noopener">'.e($office).'</a>');
    }

    public function save(): void
    {
        $company = $this->company();

        abort_unless($company !== null && static::canAccess(), 404);

        $data = $this->form->getState();
        $before = $company->only(['investor_portal_enabled', 'investor_portal_domain']);

        $company->investor_portal_enabled = (bool) $data['investor_portal_enabled'];

        if (static::isSuperAdmin()) {
            $domain = Company::normalizeDomain($data['investor_portal_domain'] ?? null);

            if ($domain !== null && (
                Company::query()->withoutGlobalScopes()->where('investor_portal_domain', $domain)->whereKeyNot($company->getKey())->exists()
                || Company::query()->withoutGlobalScopes()->where('domain', $domain)->orWhere('reseller_portal_domain', $domain)->exists()
            )) {
                throw ValidationException::withMessages(['data.investor_portal_domain' => __('Another company already uses this domain.')]);
            }

            $company->investor_portal_domain = $domain;
        }

        $imsBefore = $company->only(['ims_enabled', 'ims_domain']);

        if (Auth::user()?->hasPermission('ims.domain.manage')) {
            $imsDomain = Company::normalizeDomain($data['ims_domain'] ?? null);

            if ($imsDomain !== null && $imsDomain !== $company->ims_domain && Company::hostIsTakenByThePlatform($imsDomain)) {
                throw ValidationException::withMessages(['data.ims_domain' => __('This domain is already used by a company, a portal or a store. IMS needs its own main domain.')]);
            }

            if ((bool) ($data['ims_enabled'] ?? false) && $imsDomain === null) {
                throw ValidationException::withMessages(['data.ims_domain' => __('Enter the IMS domain before switching IMS on.')]);
            }

            $company->ims_domain = $imsDomain;
            $company->ims_enabled = (bool) ($data['ims_enabled'] ?? false);
        }

        $company->save();

        app(AuditLogService::class)->record('investor_portal.settings_saved', $company, $before, $company->only(['investor_portal_enabled', 'investor_portal_domain']));

        if ($imsBefore !== $company->only(['ims_enabled', 'ims_domain'])) {
            app(AuditLogService::class)->record('ims.domain_saved', $company, $imsBefore, $company->only(['ims_enabled', 'ims_domain']));
        }

        Notification::make()->title(__('Investor portal settings saved'))->success()->send();
    }
}
