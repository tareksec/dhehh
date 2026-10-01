<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Concerns\ForApprovedResellers;
use App\Models\Company;
use App\Models\Customer;
use App\Services\ResellerDomainDnsChecker;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

/**
 * Reseller portal Phase 5 (13_RESELLER_PORTAL_PLAN.md §3 row 6): the store's
 * URL slug and the shop name shown in its header. Per the owner's answer
 * (§2.1 Q3) the header shows only the reseller's own name/brand -- never the
 * company's -- so this name is what visitors see. Slug rules are the same as
 * the storefront's own "Your Store" page: letters, numbers, dashes and
 * underscores, unique within the company.
 */
class MyStore extends Page
{
    use ForApprovedResellers;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.reseller.pages.my-store';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function getTitle(): string
    {
        return __('My Store');
    }

    public static function getNavigationLabel(): string
    {
        return __('My Store');
    }

    public function mount(): void
    {
        $reseller = $this->reseller();

        $this->form->fill([
            'reseller_slug' => $reseller->reseller_slug,
            'reseller_subdomain' => $reseller->reseller_subdomain,
            'reseller_custom_domain' => $reseller->reseller_custom_domain,
            'reseller_store_name' => $reseller->reseller_store_name,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $reseller = $this->reseller();

        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('reseller_slug')
                            ->label(__('Store URL name'))
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(
                                table: 'customers',
                                column: 'reseller_slug',
                                ignorable: $reseller,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('company_id', $reseller->company_id),
                            )
                            ->helperText(__('Only letters, numbers, dashes and underscores. If you change this, links you already shared will stop working.')),

                        TextInput::make('reseller_subdomain')
                            ->label(__('Store subdomain'))
                            ->visible(fn (): bool => (bool) $this->company()?->reseller_subdomains_enabled)
                            ->minLength(3)
                            ->maxLength(40)
                            ->regex('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/')
                            ->validationMessages(['regex' => __('Use lowercase letters, numbers and dashes only, and do not start or end with a dash.')])
                            ->notIn(Company::RESERVED_RESELLER_SUBDOMAINS)
                            ->unique(
                                table: 'customers',
                                column: 'reseller_subdomain',
                                ignorable: $reseller,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('company_id', $reseller->company_id),
                            )
                            ->suffix(fn (): string => '.'.$this->company()?->domain)
                            ->helperText(__('Your store opens at this address. Leave it empty to keep using the long link. If you change it, links you already shared will stop working.')),

                        TextInput::make('reseller_custom_domain')
                            ->label(__('Your own domain'))
                            ->visible(fn (): bool => (bool) $this->company()?->reseller_custom_domains_enabled)
                            ->maxLength(190)
                            ->placeholder('myshop.com')
                            ->rules([
                                fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($reseller): void {
                                    $domain = Company::normalizeDomain((string) $value);

                                    if ($domain === null) {
                                        return;
                                    }

                                    if (! preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
                                        $fail(__('Enter a domain like myshop.com.'));
                                    } elseif (Company::hostIsTakenByThePlatform($domain)) {
                                        $fail(__('This domain cannot be used.'));
                                    } elseif (Customer::withoutGlobalScopes()->where('reseller_custom_domain', $domain)->whereKeyNot($reseller->getKey())->exists()) {
                                        $fail(__('This domain is already used by another store.'));
                                    }
                                },
                            ])
                            ->helperText(__('Optional. A domain you own, for example myshop.com. Save it, then point it to us as shown below and press Check DNS.')),

                        TextInput::make('reseller_store_name')
                            ->label(__('Store name'))
                            ->maxLength(255)
                            ->placeholder($reseller->business_name ?: $reseller->name)
                            ->helperText(__('Shown at the top of your store. Leave it empty to use your business name.')),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $reseller = $this->reseller();

        $customDomain = $this->company()?->reseller_custom_domains_enabled
            ? Company::normalizeDomain($data['reseller_custom_domain'] ?? null)
            : $reseller->reseller_custom_domain;
        $domainChanged = $customDomain !== $reseller->reseller_custom_domain;

        $reseller->update([
            'reseller_slug' => $data['reseller_slug'],
            'reseller_custom_domain' => $customDomain,
            // A new or changed domain has to be checked again before it goes live.
            'reseller_custom_domain_verified_at' => $domainChanged ? null : $reseller->reseller_custom_domain_verified_at,
            'reseller_subdomain' => $this->company()?->reseller_subdomains_enabled
                ? (filled($data['reseller_subdomain'] ?? null) ? strtolower(trim($data['reseller_subdomain'])) : null)
                : $reseller->reseller_subdomain,
            'reseller_store_name' => filled($data['reseller_store_name'] ?? null) ? trim($data['reseller_store_name']) : null,
        ]);

        Notification::make()
            ->title(__('Your store settings have been saved.'))
            ->success()
            ->send();
    }

    /** Re-checks the saved custom domain's DNS and, if it points to us, makes it live. */
    public function verifyDomain(): void
    {
        $reseller = $this->reseller()->fresh();
        $company = $this->company();

        if (! $company?->reseller_custom_domains_enabled || blank($reseller->reseller_custom_domain)) {
            return;
        }

        if (app(ResellerDomainDnsChecker::class)->pointsToUs($reseller->reseller_custom_domain, $company)) {
            $reseller->forceFill(['reseller_custom_domain_verified_at' => now()])->save();

            Notification::make()->title(__('Your domain is verified. Your store is now live there.'))->success()->send();

            return;
        }

        Notification::make()
            ->title(__('We could not see your domain pointing to us yet. DNS changes can take a few hours -- try again later.'))
            ->warning()
            ->send();
    }

    public function customDomainStatus(): ?array
    {
        $reseller = $this->reseller()->fresh();
        $company = $this->company();

        if (! $company?->reseller_custom_domains_enabled || blank($reseller->reseller_custom_domain)) {
            return null;
        }

        return [
            'domain' => $reseller->reseller_custom_domain,
            'verified' => $reseller->reseller_custom_domain_verified_at !== null,
            'target' => $company->domain,
        ];
    }

    public function storeUrl(): ?string
    {
        return $this->reseller()->resellerStoreUrl();
    }
}
