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

        $company->save();

        app(AuditLogService::class)->record('investor_portal.settings_saved', $company, $before, $company->only(['investor_portal_enabled', 'investor_portal_domain']));

        Notification::make()->title(__('Investor portal settings saved'))->success()->send();
    }
}
