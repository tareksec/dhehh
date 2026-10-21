<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Resellers;
use App\Filament\Resources\StorefrontPaymentMethods\StorefrontPaymentMethodResource;
use App\Models\Company;
use App\Models\StorefrontPayment;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\ResellerNotificationService;
use App\Services\ResellerOwnCourierService;
use App\Services\ResellerSubdomainProbe;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
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
            'reseller_subdomain_setup_minutes' => (int) ($setting?->reseller_subdomain_setup_minutes ?? 10),
            'reseller_cost_per_unit' => (float) ($setting?->reseller_cost_per_unit ?? 0),
            'reseller_packaging_cost_per_unit' => (float) ($setting?->reseller_packaging_cost_per_unit ?? 0),
            'reseller_return_charge' => (float) ($setting?->reseller_return_charge ?? 50),
            'reseller_min_withdrawal' => (float) ($setting?->reseller_min_withdrawal ?? 0),
            'reseller_program_enabled' => (bool) ($setting?->reseller_program_enabled ?? true),
            'reseller_guide_enabled' => (bool) ($setting?->reseller_guide_enabled ?? true),
            'reseller_guide_intro' => $setting?->reseller_guide_intro,
            'reseller_guide_show_figures' => (bool) ($setting?->reseller_guide_show_figures ?? false),
            'reseller_guide_rules' => $setting?->reseller_guide_rules ?? [],
            'reseller_announcement_enabled' => (bool) ($setting?->reseller_announcement_enabled ?? false),
            'reseller_announcement' => $setting?->reseller_announcement,
            ...collect(ResellerNotificationService::COLUMNS)->mapWithKeys(fn (string $column): array => [$column => $setting?->{$column}])->all(),
            ...$this->paymentFormState($setting),
        ]);
    }

    /**
     * The own-courier payment fields: the ticked store methods and the
     * reseller-only rows (with their ids, so a second save updates them).
     *
     * @return array<string, mixed>
     */
    protected function paymentFormState(?StorefrontSetting $setting): array
    {
        return [
            'reseller_payment_method_ids' => $this->chosenStoreMethodIds($setting),
            'reseller_only_payment_methods' => StorefrontPaymentMethod::query()
                ->where('reseller_only', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (StorefrontPaymentMethod $method): array => [
                    'id' => $method->getKey(),
                    'name' => $method->name,
                    'account_number' => $method->account_number,
                    'instructions' => $method->instructions,
                    'is_active' => (bool) $method->is_active,
                ])
                ->all(),
        ];
    }

    /** The store's manual methods an admin can let resellers use (active, not reseller-only). */
    protected function storeMethods(): Collection
    {
        return StorefrontPaymentMethod::query()
            ->forStore()
            ->where('type', StorefrontPaymentMethod::TYPE_MANUAL)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * What is ticked: the saved choice, or every store method until the admin
     * has saved one (that is what resellers see until then).
     *
     * @return list<int>
     */
    protected function chosenStoreMethodIds(?StorefrontSetting $setting): array
    {
        $available = $this->storeMethods()->modelKeys();
        $chosen = $setting?->reseller_payment_method_ids;

        return $chosen === null ? array_map('intval', $available) : array_values(array_intersect($available, array_map('intval', $chosen)));
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
                        TextInput::make('reseller_subdomain_setup_minutes')
                            ->label(__('Subdomain set-up time (minutes)'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->required()
                            ->helperText(__('After a reseller picks a subdomain, their My Store page counts down this long while the address is set up, and their store link switches to it once it opens over HTTPS (checked automatically) or this time has passed.')),
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
                            ->helperText(__("Added to a product's lowest wholesale tier price to get a reseller's cost. Resellers can sell at any price at or above that cost; the difference is their profit.")),
                        TextInput::make('reseller_packaging_cost_per_unit')
                            ->label(__('Packaging charge per piece (own courier)'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->prefix($currency)
                            ->helperText(__('Charged on orders a reseller sends with their own courier: this amount times the number of pieces. The reseller pays it in advance with the order.')),
                        TextInput::make('reseller_return_charge')
                            ->label(__('Return charge per order'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->prefix($currency)
                            ->helperText(__('Taken once from the reseller\'s deposit when one of their orders is returned (not when it is cancelled before delivery). Enter 0 for no charge. If the deposit holds less, it goes below zero and the reseller\'s next credits make it up.')),
                        TextInput::make('reseller_min_withdrawal')
                            ->label(__('Minimum reseller withdrawal'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->prefix($currency)
                            ->helperText(__('The smallest amount a reseller can request to withdraw from their delivered-order profit.')),
                    ])
                    ->columns(2),

                Section::make(__('Storefront'))
                    ->schema([
                        Toggle::make('reseller_program_enabled')
                            ->label(__('Show reseller program'))
                            ->helperText(__('Off hides "Become a reseller" from the storefront\'s account menu and footer. The reseller pages keep working at their direct URL — only the links are hidden.')),
                    ]),

                Section::make(__('Reseller guide page'))
                    ->description(fn (): HtmlString => $this->guideDescription())
                    ->schema([
                        Toggle::make('reseller_guide_enabled')
                            ->label(__('Show the reseller guide'))
                            ->helperText(__('A public page on the reseller portal that explains how the program works and lists your rules, with buttons to apply or sign in. The storefront\'s "Become a reseller" link opens it while it is on.')),
                        Toggle::make('reseller_guide_show_figures')
                            ->label(__('Show money figures on the guide'))
                            ->helperText(__('Shows the minimum withdrawal and the packaging charge per piece on the guide. Reseller costs are never shown.')),
                        Textarea::make('reseller_guide_intro')
                            ->label(__('Introduction'))
                            ->helperText(__('The text under the guide\'s heading. Leave blank for the standard introduction.'))
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                        Repeater::make('reseller_guide_rules')
                            ->label(__('Rules and regulations'))
                            ->helperText(__('Your own rules, shown in this order under "Rules and regulations". Applicants agree to them by applying.'))
                            ->addActionLabel(__('Add rule'))
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                            ->schema([
                                TextInput::make('title')
                                    ->label(__('Rule'))
                                    ->required()
                                    ->maxLength(200),
                                Textarea::make('body')
                                    ->label(__('Details (optional)'))
                                    ->rows(3)
                                    ->maxLength(2000),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Portal announcement'))
                    ->description(__('A notice every approved reseller sees at the top of their dashboard, for example a holiday closing or a price change. Turn it off to hide it without losing the text.'))
                    ->schema([
                        Toggle::make('reseller_announcement_enabled')
                            ->label(__('Show the announcement'))
                            ->live(),
                        Textarea::make('reseller_announcement')
                            ->label(__('Announcement'))
                            ->rows(3)
                            ->maxLength(500)
                            ->required(fn (Get $get): bool => (bool) $get('reseller_announcement_enabled'))
                            ->columnSpanFull(),
                    ]),

                Section::make(__('SMS to resellers'))
                    ->description(__('Write the text of each SMS a reseller gets. Leave a message empty and it is not sent. SMS goes through the SMS gateway in Settings → Integrations, and numbers that opted out are skipped. You can use the words shown under each box; they are replaced with the reseller\'s own details.'))
                    ->schema(collect(ResellerNotificationService::COLUMNS)
                        ->map(fn (string $column, string $event): Textarea => Textarea::make($column)
                            ->label($this->messageLabel($event))
                            ->helperText(__('Words you can use: :words', ['words' => implode(' ', ResellerNotificationService::placeholders()[$event])]))
                            ->rows(2)
                            ->maxLength(300)
                            ->columnSpanFull())
                        ->values()
                        ->all()),

                Section::make(__('Own-courier advance payments'))
                    ->description(__('Resellers pay the advance of their own-courier orders into these channels. Tick the store\'s payment methods they may use, and add channels that exist only for resellers. The store\'s checkout never shows the reseller-only channels.'))
                    ->schema([
                        CheckboxList::make('reseller_payment_method_ids')
                            ->label(__('Store payment methods resellers can use'))
                            ->options(fn (): array => $this->storeMethods()
                                ->mapWithKeys(fn (StorefrontPaymentMethod $method): array => [
                                    $method->getKey() => trim($method->name.($method->account_number ? ' — '.$method->account_number : '')),
                                ])
                                ->all())
                            ->columns(2)
                            ->helperText(__('Only active manual (send-money / bank) methods from the store\'s Payment Methods list. A method you add there later stays hidden from resellers until you tick it here.'))
                            ->columnSpanFull(),
                        Repeater::make('reseller_only_payment_methods')
                            ->label(__('Reseller-only payment methods'))
                            ->helperText(__('Shown to resellers only. Changing the store\'s payment methods never changes these. A method that was already used for a payment is switched off instead of deleted.'))
                            ->addActionLabel(__('Add reseller-only method'))
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->default([])
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('name')
                                    ->label(__('Name'))
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('e.g. bKash (Reseller)'),
                                TextInput::make('account_number')
                                    ->label(__('Send-money / account number'))
                                    ->required()
                                    ->maxLength(255),
                                Textarea::make('instructions')
                                    ->label(__('Instructions'))
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->columnSpanFull(),
                                Toggle::make('is_active')
                                    ->label(__('Active'))
                                    ->default(true),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                        Text::make(fn (): HtmlString => $this->paymentMethodsSummary()),
                    ]),
            ]);
    }

    protected function paymentMethodsSummary(): HtmlString
    {
        $company = $this->company();
        $methods = $company ? app(ResellerOwnCourierService::class)->paymentMethods($company) : collect();
        $warning = $methods->isEmpty()
            ? '<p class="mb-2">'.e(__('No payment method is available to resellers yet: they cannot place own-courier orders until one is chosen or added.')).'</p>'
            : '';

        return new HtmlString($warning.'<p><a class="text-primary-600 underline" href="'.e(StorefrontPaymentMethodResource::getUrl()).'">'.e(__('Manage payment methods')).'</a></p>');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('useSuggestedMessages')
                ->label(__('Fill empty SMS texts with suggested wording'))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('gray')
                ->visible(fn (): bool => $this->company() !== null)
                ->action(fn () => $this->useSuggestedMessages()),
            Action::make('checkSubdomainSetup')
                ->label(__('Test subdomain setup'))
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->visible(fn (): bool => filled($this->company()?->domain))
                ->action(fn () => $this->testSubdomainSetup()),
        ];
    }

    /** The label of one SMS message box. */
    protected function messageLabel(string $event): string
    {
        return match ($event) {
            ResellerNotificationService::EVENT_APPROVED => __('When an application is approved'),
            ResellerNotificationService::EVENT_PROFIT_PAYABLE => __('When a profit becomes payable'),
            default => __('When a withdrawal is paid'),
        };
    }

    /**
     * Puts suggested wording into the SMS boxes that are still empty. It is only a draft in the
     * form: nothing is saved or sent until the admin presses Save, and wording already written is kept.
     */
    protected function useSuggestedMessages(): void
    {
        foreach (ResellerNotificationService::COLUMNS as $event => $column) {
            if (blank($this->data[$column] ?? null)) {
                $this->data[$column] = ResellerNotificationService::suggestedWording($event);
            }
        }

        Notification::make()
            ->title(__('Suggested wording added'))
            ->body(__('Read and change it if you like, then press Save changes. Nothing is sent until you save.'))
            ->success()
            ->send();
    }

    /**
     * Opens a made-up name under the company's domain the way a visitor of a
     * reseller's store would: it must resolve to this server (the wildcard
     * DNS record) and open over HTTPS (the wildcard certificate). Tells the
     * admin which of the two is still missing.
     */
    protected function testSubdomainSetup(): void
    {
        $company = $this->company();

        if (! $company || blank($company->domain)) {
            return;
        }

        $host = 'setup-test-'.Str::lower(Str::random(6)).'.'.$company->domain;
        $result = app(ResellerSubdomainProbe::class)->diagnose($host, $company);

        $notification = match ($result['status']) {
            'ok' => Notification::make()->success()
                ->title(__('Subdomains open over HTTPS'))
                ->body(__(':host opened with a valid SSL certificate, so new reseller store addresses will work.', ['host' => $host])),
            'dns' => Notification::make()->danger()
                ->title(__('Subdomains do not reach this server yet'))
                ->body(__(':host does not resolve to this server. Add a DNS record for *.:domain that points at the server (DNS only, not proxied), then test again; DNS changes can take a few minutes.', ['host' => $host, 'domain' => $company->domain])),
            default => Notification::make()->danger()
                ->title(__('Subdomains resolve but HTTPS does not work'))
                ->body(__(':host resolves to this server, but opening it over HTTPS failed (:detail). A wildcard SSL certificate for *.:domain is needed.', ['host' => $host, 'domain' => $company->domain, 'detail' => $result['detail'] ?? '-'])),
        };

        $notification->persistent()->send();
    }

    protected function guideDescription(): HtmlString
    {
        $text = e(__('What a new reseller reads before applying.'));
        $host = $this->company()?->resellerPortalHost();

        if ($host === null) {
            return new HtmlString($text.' '.e(__('It goes live once the reseller module is on and the company has a domain.')));
        }

        $url = request()->getScheme().'://'.$host.'/portal/guide';

        return new HtmlString($text.' <a class="text-primary-600 underline" href="'.e($url).'" target="_blank" rel="noopener">'.e($url).'</a>');
    }

    /**
     * Keeps the ticked store methods and syncs the reseller-only rows: rows
     * are updated in place, new ones created, and a removed one is deleted
     * unless a payment already points at it (then it is only switched off).
     *
     * @param  array<string, mixed>  $data
     */
    protected function savePaymentMethods(StorefrontSetting $setting, array $data): void
    {
        $items = collect($data['reseller_only_payment_methods'] ?? [])->values();
        $names = $items->map(fn (array $item): string => mb_strtolower(trim((string) $item['name'])));

        if ($names->count() !== $names->unique()->count()) {
            throw ValidationException::withMessages(['data.reseller_only_payment_methods' => __('Two reseller-only payment methods cannot have the same name.')]);
        }

        $setting->forceFill([
            'reseller_payment_method_ids' => array_values($this->storeMethods()
                ->whereIn('id', array_map('intval', $data['reseller_payment_method_ids'] ?? []))
                ->modelKeys()),
        ])->save();

        $existing = StorefrontPaymentMethod::query()->where('reseller_only', true)->get()->keyBy('id');
        $kept = [];

        foreach ($items as $position => $item) {
            $attributes = [
                'name' => trim((string) $item['name']),
                'account_number' => trim((string) $item['account_number']),
                'instructions' => filled($item['instructions'] ?? null) ? trim($item['instructions']) : null,
                'is_active' => (bool) ($item['is_active'] ?? true),
                'sort_order' => $position,
            ];
            $method = $existing->get((int) ($item['id'] ?? 0));

            if ($method) {
                $method->update($attributes);
            } else {
                $method = StorefrontPaymentMethod::query()->create($attributes + [
                    'company_id' => $setting->company_id,
                    'type' => StorefrontPaymentMethod::TYPE_MANUAL,
                    'reseller_only' => true,
                ]);
            }

            $kept[] = $method->getKey();
        }

        foreach ($existing->except($kept) as $method) {
            if (StorefrontPayment::query()->where('storefront_payment_method_id', $method->getKey())->exists()) {
                $method->update(['is_active' => false]);
            } else {
                $method->delete();
            }
        }

        $this->form->fill([...$this->data, ...$this->paymentFormState($setting)]);
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

        $setting = $company->storefrontSetting ?? StorefrontSetting::query()->create(['company_id' => $company->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE]);

        if (static::isSuperAdmin()) {
            $setting->reseller_subdomain_setup_minutes = (int) $data['reseller_subdomain_setup_minutes'];
        }

        $setting->forceFill([
            'reseller_cost_per_unit' => round((float) $data['reseller_cost_per_unit'], 2),
            'reseller_packaging_cost_per_unit' => round((float) $data['reseller_packaging_cost_per_unit'], 2),
            'reseller_return_charge' => round((float) $data['reseller_return_charge'], 2),
            'reseller_min_withdrawal' => round((float) $data['reseller_min_withdrawal'], 2),
            'reseller_program_enabled' => (bool) $data['reseller_program_enabled'],
            'reseller_guide_enabled' => (bool) $data['reseller_guide_enabled'],
            'reseller_guide_intro' => filled($data['reseller_guide_intro'] ?? null) ? trim($data['reseller_guide_intro']) : null,
            'reseller_guide_show_figures' => (bool) $data['reseller_guide_show_figures'],
            'reseller_announcement_enabled' => (bool) ($data['reseller_announcement_enabled'] ?? false),
            'reseller_announcement' => filled($data['reseller_announcement'] ?? null) ? trim($data['reseller_announcement']) : null,
            ...collect(ResellerNotificationService::COLUMNS)
                ->mapWithKeys(fn (string $column): array => [$column => filled($data[$column] ?? null) ? trim($data[$column]) : null])
                ->all(),
            'reseller_guide_rules' => collect($data['reseller_guide_rules'] ?? [])
                ->map(fn (array $rule): array => ['title' => trim((string) ($rule['title'] ?? '')), 'body' => filled($rule['body'] ?? null) ? trim($rule['body']) : null])
                ->filter(fn (array $rule): bool => $rule['title'] !== '')
                ->values()
                ->all(),
        ])->save();

        $this->savePaymentMethods($setting, $data);

        Notification::make()->title(__('Reseller settings saved'))->success()->send();
    }
}
