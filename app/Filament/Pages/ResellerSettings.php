<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Resellers;
use App\Filament\Resources\StorefrontPaymentMethods\StorefrontPaymentMethodResource;
use App\Models\Company;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\ResellerOwnCourierService;
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
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Every setting of the reseller program for the current company in one place
 * (owner rule, 2026-10-06): the module and its domains (Super Admin only, as
 * before), the money rules, the storefront link, and the payment channels
 * resellers pay their own-courier advances into. These fields used to sit on
 * the company form and in Storefront Settings; they are edited only here now.
 */
class ResellerSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $cluster = Resellers::class;

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.reseller-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Reseller Settings');
    }

    public function getTitle(): string
    {
        return __('Reseller Settings');
    }

    public static function canAccess(): bool
    {
        return SchemaFacade::hasTable('storefront_settings') && (Auth::user()?->canManageSettings() ?? false);
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

        $setting = $company->storefrontSetting;

        $this->form->fill([
            'reseller_module_enabled' => (bool) $company->reseller_module_enabled,
            'reseller_subdomains_enabled' => (bool) $company->reseller_subdomains_enabled,
            'reseller_custom_domains_enabled' => (bool) $company->reseller_custom_domains_enabled,
            'reseller_portal_domain' => $company->reseller_portal_domain,
            'reseller_cost_per_unit' => (float) ($setting?->reseller_cost_per_unit ?? 0),
            'reseller_packaging_cost_per_unit' => (float) ($setting?->reseller_packaging_cost_per_unit ?? 0),
            'reseller_min_withdrawal' => (float) ($setting?->reseller_min_withdrawal ?? 0),
            'reseller_program_enabled' => (bool) ($setting?->reseller_program_enabled ?? true),
        ]);
    }

    protected static function isSuperAdmin(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public function form(Schema $schema): Schema
    {
        $currency = fn (): string => $this->company()?->currency ?: 'BDT';

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Reseller module'))
                    ->description(__('Turns the reseller program on for this company and sets where resellers sign in and how their stores are reached. Super Admin only.'))
                    ->schema([
                        Toggle::make('reseller_module_enabled')
                            ->label(__('Reseller module enabled'))
                            ->helperText(__('Lets this company approve resellers, who then get their own portal and store.')),
                        Toggle::make('reseller_subdomains_enabled')
                            ->label(__('Reseller subdomains enabled'))
                            ->helperText(__('Lets every approved reseller choose "name.<this company\'s domain>" for their store. Needs a wildcard DNS record (*.domain) and a wildcard SSL certificate on the server first -- see docs/deployment.md.')),
                        Toggle::make('reseller_custom_domains_enabled')
                            ->label(__('Reseller custom domains enabled'))
                            ->helperText(__('Lets every approved reseller connect their own domain (e.g. myshop.com) to their store. The server must issue SSL certificates on demand first (a Caddy proxy using /internal/tls-ask) -- see docs/deployment.md.')),
                        TextInput::make('reseller_portal_domain')
                            ->label(__('Reseller portal domain'))
                            ->helperText(__('Leave blank to use "reseller." + this company\'s own domain. Set this only for a fully separate domain (e.g. a dedicated reseller-only website).'))
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->disabled(fn (): bool => ! static::isSuperAdmin()),

                Section::make(__('Money rules'))
                    ->description(__('What resellers pay and can withdraw. Nothing here is shown on the public storefront.'))
                    ->schema([
                        TextInput::make('reseller_cost_per_unit')
                            ->label(__('Reseller cost per unit'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->prefix($currency)
                            ->helperText(__("Added to a product's lowest wholesale tier price to get a reseller's cost. Resellers can sell at any price at or above that cost; the difference is their commission.")),
                        TextInput::make('reseller_packaging_cost_per_unit')
                            ->label(__('Packaging charge per piece (own courier)'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->prefix($currency)
                            ->helperText(__('Charged on orders a reseller sends with their own courier: this amount times the number of pieces. The reseller pays it in advance with the order.')),
                        TextInput::make('reseller_min_withdrawal')
                            ->label(__('Minimum reseller withdrawal'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->prefix($currency)
                            ->helperText(__('The smallest amount a reseller can request to withdraw from their delivered-order commission.')),
                    ])
                    ->columns(2),

                Section::make(__('Storefront'))
                    ->schema([
                        Toggle::make('reseller_program_enabled')
                            ->label(__('Show reseller program'))
                            ->helperText(__('Off hides "Become a reseller" from the storefront\'s account menu and footer. The reseller pages keep working at their direct URL — only the links are hidden.')),
                    ]),

                Section::make(__('Own-courier advance payments'))
                    ->description(__('Resellers pay the advance of their own-courier orders into these channels. They are the store\'s manual payment methods (also used at the storefront checkout), so they are managed on the Payment Methods page.'))
                    ->schema([
                        Text::make(fn (): HtmlString => $this->paymentMethodsSummary()),
                    ]),
            ]);
    }

    protected function paymentMethodsSummary(): HtmlString
    {
        $company = $this->company();
        $methods = $company ? app(ResellerOwnCourierService::class)->paymentMethods($company) : collect();
        $list = $methods->isEmpty()
            ? '<p>'.e(__('No active payment method yet: resellers cannot place own-courier orders until one is added.')).'</p>'
            : '<ul class="list-disc ps-5">'.$methods->map(fn (StorefrontPaymentMethod $method): string => '<li>'.e(trim($method->name.($method->account_number ? ' — '.$method->account_number : ''))).'</li>')->implode('').'</ul>';

        return new HtmlString($list.'<p class="mt-2"><a class="text-primary-600 underline" href="'.e(StorefrontPaymentMethodResource::getUrl()).'">'.e(__('Manage payment methods')).'</a></p>');
    }

    public function save(): void
    {
        $company = $this->company();

        abort_unless($company !== null, 404);

        $data = $this->form->getState();

        if (static::isSuperAdmin()) {
            $domain = Company::normalizeDomain($data['reseller_portal_domain'] ?? null);

            if ($domain !== null && Company::query()->withoutGlobalScopes()->where('reseller_portal_domain', $domain)->whereKeyNot($company->getKey())->exists()) {
                throw ValidationException::withMessages(['data.reseller_portal_domain' => __('Another company already uses this domain.')]);
            }

            $company->forceFill([
                'reseller_module_enabled' => (bool) $data['reseller_module_enabled'],
                'reseller_subdomains_enabled' => (bool) $data['reseller_subdomains_enabled'],
                'reseller_custom_domains_enabled' => (bool) $data['reseller_custom_domains_enabled'],
                'reseller_portal_domain' => $domain,
            ])->save();
        }

        $setting = $company->storefrontSetting ?? StorefrontSetting::query()->create(['company_id' => $company->getKey()]);
        $setting->forceFill([
            'reseller_cost_per_unit' => round((float) $data['reseller_cost_per_unit'], 2),
            'reseller_packaging_cost_per_unit' => round((float) $data['reseller_packaging_cost_per_unit'], 2),
            'reseller_min_withdrawal' => round((float) $data['reseller_min_withdrawal'], 2),
            'reseller_program_enabled' => (bool) $data['reseller_program_enabled'],
        ])->save();

        Notification::make()->title(__('Reseller settings saved'))->success()->send();
    }
}
