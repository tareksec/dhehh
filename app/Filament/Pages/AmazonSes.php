<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\Ses\SesApiException;
use App\Services\Ses\SesApiService;
use App\Services\Ses\SesTrackingService;
use App\Services\Ses\SesUsageService;
use App\Services\StorefrontNotificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Amazon SES from the dashboard, through its API (owner request 2026-10-23):
 * the AWS key, the account's state (sandbox or production, sending quota), the
 * domains and addresses allowed to send (with the DNS records a new domain
 * needs), the suppression list, and the switch for bounce / complaint / delivery
 * tracking that feeds the Email Log. The emails themselves still go through the
 * site's SMTP account (Integrations → Email Setup); this page only controls
 * the SES account behind it. The AWS key is kept per site, encrypted, with the
 * other notification credentials (StorefrontNotificationService::SES_CREDENTIAL_KEYS).
 * Email usage (owner request 2026-10-24): what went out, what reached the
 * recipient, the share of the free allowance used and an estimated cost, counted
 * by SesUsageService from the Email Log; the free allowance and the price are
 * typed in on this page because Amazon's API does not give them.
 */
class AmazonSes extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.amazon-ses';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public ?int $companyId = null;

    /** The site (StorefrontSetting row) whose AWS key and tracking are shown. */
    #[Locked]
    public ?int $settingId = null;

    /**
     * What the last refresh read from AWS: account, identities, suppressed, tracking, errors (one message per
     * part, so one missing permission does not hide the rest).
     *
     * @var array<string, mixed>
     */
    public array $overview = [];

    public bool $loaded = false;

    /** 0 = this month, 1 = last month: the month the usage numbers are about. */
    public int $monthsBack = 0;

    /** Cost settings: the free emails each month and the price of 1,000 emails after that (typed, so strings). */
    public ?string $freeLimit = null;

    public ?string $pricePer1000 = null;

    public static function getNavigationLabel(): string
    {
        return __('Amazon SES');
    }

    public function getTitle(): string
    {
        return __('Amazon SES');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        $company = app(CompanyContext::class)->company();

        return (bool) ($user?->canManageSettings() && $company && $user->canAccessCompany((int) $company->getKey()));
    }

    public function mount(): void
    {
        $company = $this->selectedCompany();
        $this->companyId = (int) $company->getKey();
        $this->settingId = $this->activeSetting()?->getKey();

        $this->fillForm();
    }

    public function hasSite(): bool
    {
        return $this->settingId !== null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('site_setting_id')
                    ->label(__('Site'))
                    ->options(fn (): array => $this->siteOptions())
                    ->visible(fn (): bool => count($this->siteOptions()) > 1)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(function (mixed $state): void {
                        $this->settingId = (int) $state;
                        $this->overview = [];
                        $this->loaded = false;
                        $this->fillForm();
                    })
                    ->helperText(__('The AWS key and the tracking belong to one site, like its email account. Pick the site.'))
                    ->columnSpanFull(),
                TextInput::make('ses_api_key_id')
                    ->label(__('AWS access key ID'))
                    ->placeholder('AKIA…')
                    ->maxLength(128)
                    ->autocomplete(false)
                    ->helperText(__('Of an AWS user that may manage Amazon SES (see the permissions below). This is not the SMTP user name the emails are sent with.')),
                TextInput::make('ses_api_secret')
                    ->label(__('AWS secret access key'))
                    ->password()
                    ->revealable()
                    ->maxLength(256)
                    ->autocomplete('new-password')
                    ->helperText(fn (): string => $this->secretSaved()
                        ? __('A secret access key is saved. Leave this empty to keep it.')
                        : __('Shown only once when the key is created in AWS. Stored encrypted.')),
                TextInput::make('ses_api_region')
                    ->label(__('AWS region'))
                    ->placeholder('ap-southeast-1')
                    ->maxLength(30)
                    ->regex('/^[a-z]{2}(-[a-z]+)+-\d$/i')
                    ->autocomplete(false)
                    ->helperText(__('The region the domains were verified in. Empty: the region of the site\'s SMTP host is used.')),
            ])
            ->columns(3);
    }

    /**
     * Removing a domain or address from the AWS account is for a super admin only (owner decision 2026-10-23): a
     * removed domain stops email for every company that sends from it, and the account is shared.
     */
    public function canRemoveDomains(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    /** True when the saved row already holds a secret (the box itself is never filled back). */
    public function secretSaved(): bool
    {
        return filled(data_get($this->activeSetting()?->notification_credentials, 'ses_api_secret'));
    }

    /** @return Collection<int, StorefrontSetting> */
    protected function companySettings(): Collection
    {
        return $this->companyId
            ? StorefrontSetting::withoutGlobalScopes()->where('company_id', $this->companyId)->orderBy('site_type')->orderBy('id')->get()
            : collect();
    }

    protected function activeSetting(): ?StorefrontSetting
    {
        $rows = $this->companySettings();
        $primaryId = StorefrontSetting::primaryFor($this->companyId)?->getKey();

        return $rows->firstWhere('id', $this->settingId)
            ?? ($primaryId ? $rows->firstWhere('id', $primaryId) : null)
            ?? $rows->first();
    }

    /** @return array<int, string> */
    protected function siteOptions(): array
    {
        return $this->companySettings()
            ->mapWithKeys(fn (StorefrontSetting $row): array => [$row->getKey() => $row->siteTypeLabel().' · '.($row->siteDomain() ?: '—')])
            ->all();
    }

    protected function fillForm(): void
    {
        $setting = $this->activeSetting();

        $this->form->fill([
            'site_setting_id' => $setting?->getKey(),
            'ses_api_key_id' => data_get($setting?->notification_credentials, 'ses_api_key_id'),
            'ses_api_secret' => null,
            'ses_api_region' => data_get($setting?->notification_credentials, 'ses_api_region'),
        ]);

        $costs = SesUsageService::settingsFor($setting);
        $this->freeLimit = $costs['free_limit'] === null ? null : (string) $costs['free_limit'];
        $this->pricePer1000 = $costs['price'] === null ? null : rtrim(rtrim(number_format($costs['price'], 4, '.', ''), '0'), '.');
    }

    // --- Usage and cost ---------------------------------------------------------------------------------

    /** This month's (or last month's) usage, counted from the company's Email Log. */
    public function usage(): array
    {
        $this->selectedCompany();

        return app(SesUsageService::class)->report((int) $this->companyId, $this->monthsBack, SesUsageService::settingsFor($this->activeSetting()));
    }

    public function showMonth(int $monthsBack): void
    {
        $this->selectedCompany();
        $this->monthsBack = max(0, min(1, $monthsBack));
    }

    /** Amazon does not tell the free allowance or the price, so the owner types them in. Empty clears one. */
    public function saveCostSettings(): void
    {
        $this->validate([
            'freeLimit' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'pricePer1000' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ], [], [
            'freeLimit' => __('Free emails each month'),
            'pricePer1000' => __('Price per 1,000 emails after that (US dollars)'),
        ]);

        $setting = $this->setting();
        $credentials = (array) $setting->notification_credentials;

        if (filled($this->freeLimit)) {
            $credentials['ses_free_limit'] = (int) $this->freeLimit;
        } else {
            unset($credentials['ses_free_limit']);
        }

        if (filled($this->pricePer1000)) {
            $credentials['ses_price_per_1000'] = rtrim(rtrim(number_format((float) $this->pricePer1000, 4, '.', ''), '0'), '.') ?: '0';
        } else {
            unset($credentials['ses_price_per_1000']);
        }

        $setting->forceFill(['notification_credentials' => $credentials])->save();

        app(AuditLogService::class)->record('ses.cost_settings_saved', $setting, [], [
            'site' => $setting->getKey(),
            'free_limit' => $credentials['ses_free_limit'] ?? null,
            'price_per_1000' => $credentials['ses_price_per_1000'] ?? null,
        ]);

        $this->fillForm();

        Notification::make()->title(__('Cost settings saved'))->success()->send();
    }

    /** The site being managed, after re-checking who is asking (every handler starts here). */
    protected function setting(): StorefrontSetting
    {
        $this->selectedCompany();
        $setting = $this->activeSetting();

        abort_unless($setting !== null, 404, __('This company has no site yet.'));

        return $setting;
    }

    protected function selectedCompany(): Company
    {
        $company = app(CompanyContext::class)->company();

        abort_unless($company !== null, 404, 'Select a company before opening Amazon SES.');

        if ($this->companyId !== null) {
            abort_unless((int) $company->getKey() === $this->companyId, 409, 'The selected company changed. Reload Amazon SES before continuing.');
        }

        $user = Auth::user();
        abort_unless($user?->canManageSettings() && $user->canAccessCompany((int) $company->getKey()), 403);

        return $company;
    }

    // --- Connection -------------------------------------------------------------------------------------

    public function saveCredentials(): void
    {
        $state = $this->form->getState();
        $setting = $this->setting();
        $credentials = (array) $setting->notification_credentials;

        $credentials['ses_api_key_id'] = trim((string) ($state['ses_api_key_id'] ?? ''));
        $credentials['ses_api_region'] = strtolower(trim((string) ($state['ses_api_region'] ?? '')));

        if (filled($state['ses_api_secret'] ?? null)) {
            $credentials['ses_api_secret'] = trim((string) $state['ses_api_secret']);
        }

        // Only this page's own keys are tidied; the other keys (an SMTP encryption of "" means None) stay as they are.
        foreach (['ses_api_key_id', 'ses_api_region'] as $key) {
            if ($credentials[$key] === '') {
                unset($credentials[$key]);
            }
        }

        $setting->forceFill(['notification_credentials' => $credentials])->save();

        app(AuditLogService::class)->record('ses.credentials_saved', $setting, [], ['site' => $setting->getKey()]);

        $this->fillForm();

        Notification::make()->title(__('AWS key saved'))->success()->send();

        $this->refresh();
    }

    public function forgetKeyAction(): Action
    {
        return Action::make('forgetKey')
            ->label(__('Remove the AWS key'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Remove the AWS key?'))
            ->modalDescription(__('The dashboard can no longer manage Amazon SES for this site. Emails keep going out through the SMTP account.'))
            ->visible(fn (): bool => $this->hasSite() && filled(data_get($this->activeSetting()?->notification_credentials, 'ses_api_key_id')))
            ->action(function (): void {
                $setting = $this->setting();

                if (SesTrackingService::isEnabled($setting)) {
                    Notification::make()->title(__('Turn tracking off first'))->body(__('Tracking needs the key to clean up in AWS.'))->warning()->send();

                    return;
                }

                $credentials = Arr::except((array) $setting->notification_credentials, ['ses_api_key_id', 'ses_api_secret', 'ses_api_region']);
                $setting->forceFill(['notification_credentials' => $credentials])->save();

                $this->overview = [];
                $this->loaded = false;
                $this->fillForm();

                Notification::make()->title(__('AWS key removed'))->success()->send();
            });
    }

    /** Reads the account, the identities, the suppression list and the tracking state from AWS. */
    public function refresh(): void
    {
        $setting = $this->setting();
        $ses = app(SesApiService::class);
        $overview = ['errors' => []];

        if ($ses->configured($setting)) {
            $overview['account'] = $this->read($overview, 'account', fn () => $ses->account($setting));
            $overview['identities'] = $this->read($overview, 'identities', fn () => $ses->identities($setting)) ?? [];
            $overview['suppressed'] = $this->read($overview, 'suppressed', fn () => $ses->suppressed($setting)) ?? [];
        }

        $overview['tracking'] = [
            'enabled' => SesTrackingService::isEnabled($setting),
            'config_set' => data_get($setting->notification_credentials, 'ses_config_set'),
            'url' => SesTrackingService::webhookUrl($setting),
            'confirmed' => null,
        ];

        if ($ses->configured($setting) && $overview['tracking']['enabled']) {
            $overview['tracking']['confirmed'] = $this->read($overview, 'tracking', fn () => app(SesTrackingService::class)->subscriptionConfirmed($setting));
        }

        // A key Amazon does not accept fails every call the same way: say it once, under the account, and hide
        // the parts that could not be read for that reason.
        if (isset($overview['errors']['account'])) {
            foreach (['identities', 'suppressed', 'tracking'] as $part) {
                if (($overview['errors'][$part] ?? null) === $overview['errors']['account']) {
                    unset($overview['errors'][$part]);

                    if ($part !== 'tracking') {
                        unset($overview[$part]);
                    }
                }
            }
        }

        $overview['from_address'] = app(StorefrontNotificationService::class)->mailFromAddress($setting);
        $overview['from_domain'] = $this->fromDomain($setting);

        // One AWS account can serve several companies: tell this company's own domains from the others'.
        if (isset($overview['identities'])) {
            $overview['identities'] = array_map(
                fn (array $identity): array => $identity + ['own' => $this->isOwnIdentity($identity['name'], $overview['from_address'])],
                $overview['identities'],
            );
        }

        $overview['smtp_is_ses'] = preg_match(SesApiService::SMTP_HOST_PATTERN, (string) data_get($setting->notification_credentials, 'mail_host')) === 1;

        $this->overview = $overview;
        $this->loaded = true;
    }

    /**
     * Runs one read; a failure is kept as that part's message instead of stopping the rest.
     *
     * @param  array<string, mixed>  $overview
     */
    protected function read(array &$overview, string $part, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (SesApiException $exception) {
            $overview['errors'][$part] = $exception->getMessage();

            return null;
        }
    }

    /**
     * The domains this company sends from or serves its sites on: the From address's domain, the company's
     * storefront domain and each site's own domain, in lower case without scheme, path or "www.".
     *
     * @return array<int, string>
     */
    protected function ownDomains(?string $fromAddress): array
    {
        $domains = [];

        if ($fromAddress !== null && str_contains($fromAddress, '@')) {
            $domains[] = substr(strrchr($fromAddress, '@'), 1);
        }

        foreach ($this->companySettings() as $site) {
            $domains[] = (string) $site->siteDomain();
        }

        return collect($domains)
            ->map(fn (string $domain): string => (string) preg_replace('#^(?:https?://)?(?:www\.)?([^/:]+).*$#i', '$1', strtolower(trim($domain))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a domain or address in the AWS account is this company's: the From address itself, or a domain that
     * is (or is the parent of) one of its own domains. Everything else belongs to another company on the same account.
     */
    protected function isOwnIdentity(string $identity, ?string $fromAddress): bool
    {
        $identity = strtolower($identity);

        if ($fromAddress !== null && $identity === strtolower($fromAddress)) {
            return true;
        }

        $domain = str_contains($identity, '@') ? substr(strrchr($identity, '@'), 1) : $identity;

        return collect($this->ownDomains($fromAddress))
            ->contains(fn (string $own): bool => $own === $domain || str_ends_with($own, '.'.$domain));
    }

    protected function fromDomain(StorefrontSetting $setting): ?string
    {
        $from = app(StorefrontNotificationService::class)->mailFromAddress($setting);

        return $from !== null && str_contains($from, '@') ? strtolower(substr(strrchr($from, '@'), 1)) : null;
    }

    // --- Domains and addresses --------------------------------------------------------------------------

    public function addIdentityAction(): Action
    {
        return Action::make('addIdentity')
            ->label(__('Add a domain or address'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('Add a domain or address'))
            ->modalDescription(__('A domain (shop.example) comes back with DNS records to add at your domain provider. An address (orders@shop.example) makes Amazon email its owner a link to click.'))
            ->schema([
                TextInput::make('identity')
                    ->label(__('Domain or email address'))
                    ->required()
                    ->maxLength(254)
                    ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                        $name = app(SesApiService::class)->normalizeIdentity((string) $value);

                        if (preg_match('/^[a-z0-9._%+\-]+@(?:[a-z0-9\-]+\.)+[a-z]{2,}$/', $name) !== 1
                            && preg_match('/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $name) !== 1) {
                            $fail(__('Enter a domain such as shop.example or an address such as orders@shop.example.'));
                        }
                    }),
            ])
            ->visible(fn (): bool => $this->hasSite())
            ->action(function (array $data): void {
                $setting = $this->setting();

                try {
                    $identity = app(SesApiService::class)->addIdentity($setting, (string) $data['identity']);
                } catch (SesApiException $exception) {
                    Notification::make()->title(__('Could not add it'))->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                app(AuditLogService::class)->record('ses.identity_added', $setting, [], ['identity' => $identity['name']]);

                Notification::make()
                    ->title(__('Added to Amazon SES'))
                    ->body($identity['dns_records'] !== []
                        ? __('Add the DNS records shown under the domain at your domain provider. Verification follows within minutes to hours.')
                        : __('Amazon emailed a verification link to :address. Click it to finish.', ['address' => $identity['name']]))
                    ->success()
                    ->send();

                $this->refresh();
            });
    }

    public function deleteIdentityAction(): Action
    {
        return Action::make('deleteIdentity')
            ->label(__('Remove'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Remove from Amazon SES?'))
            ->modalDescription(function (array $arguments): string {
                $name = (string) ($arguments['identity'] ?? '');
                $from = (string) data_get($this->overview, 'from_domain');
                $sendsFrom = $name !== '' && ($name === $from || str_ends_with($from, '.'.$name));

                return $sendsFrom
                    ? __(':name is what this site sends its email from. Emails fail until it is added and verified again.', ['name' => $name])
                    : __(':name can no longer be used to send email.', ['name' => $name]);
            })
            ->action(function (array $arguments): void {
                $setting = $this->setting();
                $name = (string) ($arguments['identity'] ?? '');

                // The button is hidden for everyone else; this also refuses a request crafted by hand.
                if (! $this->canRemoveDomains()) {
                    Notification::make()->title(__('Could not remove it'))->body(__('Only a super admin can remove a domain or address.'))->danger()->send();

                    return;
                }

                try {
                    app(SesApiService::class)->deleteIdentity($setting, $name);
                } catch (SesApiException $exception) {
                    Notification::make()->title(__('Could not remove it'))->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                app(AuditLogService::class)->record('ses.identity_removed', $setting, [], ['identity' => (string) ($arguments['identity'] ?? '')]);

                Notification::make()->title(__('Removed from Amazon SES'))->success()->send();

                $this->refresh();
            });
    }

    // --- Suppression list -------------------------------------------------------------------------------

    public function removeSuppressedAction(): Action
    {
        return Action::make('removeSuppressed')
            ->label(__('Allow again'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Send to this address again?'))
            ->modalDescription(fn (array $arguments): string => __('Amazon stopped sending to :email after an earlier bounce or complaint. If it bounces again, it is added back.', ['email' => (string) ($arguments['email'] ?? '')]))
            ->action(function (array $arguments): void {
                $setting = $this->setting();

                try {
                    app(SesApiService::class)->removeSuppressed($setting, (string) ($arguments['email'] ?? ''));
                } catch (SesApiException $exception) {
                    Notification::make()->title(__('Could not change it'))->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                app(AuditLogService::class)->record('ses.suppression_removed', $setting, [], ['email' => (string) ($arguments['email'] ?? '')]);

                Notification::make()->title(__('The address can receive email again'))->success()->send();

                $this->refresh();
            });
    }

    public function addSuppressedAction(): Action
    {
        return Action::make('addSuppressed')
            ->label(__('Block an address'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->modalHeading(__('Block an address'))
            ->modalDescription(__('Amazon will not send to it again until you allow it.'))
            ->schema([
                TextInput::make('email')->label(__('Email address'))->email()->required()->maxLength(254),
            ])
            ->visible(fn (): bool => $this->hasSite())
            ->action(function (array $data): void {
                $setting = $this->setting();

                try {
                    app(SesApiService::class)->addSuppressed($setting, (string) $data['email']);
                } catch (SesApiException $exception) {
                    Notification::make()->title(__('Could not change it'))->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                app(AuditLogService::class)->record('ses.suppression_added', $setting, [], ['email' => (string) $data['email']]);

                Notification::make()->title(__('The address is blocked'))->success()->send();

                $this->refresh();
            });
    }

    // --- Tracking -----------------------------------------------------------------------------------------

    public function enableTrackingAction(): Action
    {
        return Action::make('enableTracking')
            ->label(__('Turn tracking on'))
            ->icon(Heroicon::OutlinedSignal)
            ->requiresConfirmation()
            ->modalHeading(__('Turn bounce and complaint tracking on?'))
            ->modalDescription(__('This creates a notification topic and a configuration set in your AWS account and subscribes this ERP to it. Emails sent through Amazon SES from then on are reported back as delivered, bounced or marked as spam.'))
            ->action(function (): void {
                $setting = $this->setting();

                try {
                    app(SesTrackingService::class)->enable($setting);
                } catch (SesApiException $exception) {
                    Notification::make()->title(__('Could not turn tracking on'))->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                app(AuditLogService::class)->record('ses.tracking_enabled', $setting, [], ['site' => $setting->getKey()]);

                Notification::make()
                    ->title(__('Tracking is on'))
                    ->body(__('Amazon is confirming the connection to this ERP. Press Refresh in a minute to see it confirmed.'))
                    ->success()
                    ->send();

                $this->refresh();
            });
    }

    public function disableTrackingAction(): Action
    {
        return Action::make('disableTracking')
            ->label(__('Turn tracking off'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Turn tracking off?'))
            ->modalDescription(__('The topic and the configuration set are deleted from your AWS account. Emails are still sent; they just stay "Sent" in the Email Log.'))
            ->action(function (): void {
                $setting = $this->setting();

                try {
                    app(SesTrackingService::class)->disable($setting);
                } catch (SesApiException $exception) {
                    Notification::make()->title(__('Could not clean up in AWS'))->body($exception->getMessage())->danger()->persistent()->send();
                    $this->refresh();

                    return;
                }

                app(AuditLogService::class)->record('ses.tracking_disabled', $setting, [], ['site' => $setting->getKey()]);

                Notification::make()->title(__('Tracking is off'))->success()->send();

                $this->refresh();
            });
    }

    /**
     * The set-up steps with their state: true done, false to do, null not known yet (the part could not be read).
     *
     * @return array<int, array{ok: ?bool, label: string, hint: ?string}>
     */
    public function checklist(): array
    {
        $account = $this->overview['account'] ?? null;
        $identities = collect($this->overview['identities'] ?? []);
        $address = (string) ($this->overview['from_address'] ?? '');
        $domain = (string) ($this->overview['from_domain'] ?? '');
        $tracking = (array) ($this->overview['tracking'] ?? []);

        $fromVerified = $domain === '' ? null : ($account === null ? null : $identities->contains(
            fn (array $identity): bool => $identity['verification'] === 'SUCCESS'
                && ($identity['name'] === $address || $identity['name'] === $domain || str_ends_with($domain, '.'.$identity['name'])),
        ));

        return [
            [
                'ok' => $account === null ? null : true,
                'label' => __('The AWS key works'),
                'hint' => $account === null ? __('Save the key and press Refresh.') : null,
            ],
            [
                'ok' => $account === null ? null : ! $account['sandbox'],
                'label' => __('The account can send to anyone (out of the sandbox)'),
                'hint' => $account !== null && $account['sandbox'] ? __('In the sandbox Amazon only delivers to addresses you verified yourself. Ask for production access in the AWS console: SES → Account dashboard.') : null,
            ],
            [
                'ok' => $domain === '' ? false : $fromVerified,
                'label' => __('The From address is on a verified domain'),
                'hint' => $domain === '' ? __('No From address is saved. Set it in Integrations → Email Setup.') : ($fromVerified === false ? __(':domain is not verified in Amazon SES yet. Add it below and publish its DNS records.', ['domain' => $domain]) : null),
            ],
            [
                'ok' => (bool) ($this->overview['smtp_is_ses'] ?? false),
                'label' => __('The site sends its email through Amazon SES'),
                'hint' => ($this->overview['smtp_is_ses'] ?? false) ? null : __('Fill in Integrations → Email Setup with the Amazon SES region and SMTP login.'),
            ],
            [
                'ok' => ! ($tracking['enabled'] ?? false) ? false : ($tracking['confirmed'] ?? null),
                'label' => __('Bounces, complaints and deliveries are reported to the Email Log'),
                'hint' => ($tracking['enabled'] ?? false) ? (($tracking['confirmed'] ?? null) === false ? __('Amazon has not confirmed the connection yet. Wait a minute and press Refresh.') : null) : __('Turn tracking on below.'),
            ],
        ];
    }

    /** The permissions the AWS key needs, as the policy to paste into AWS IAM. */
    public function iamPolicy(): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Action' => [
                    'ses:GetAccount',
                    'ses:ListEmailIdentities', 'ses:GetEmailIdentity', 'ses:CreateEmailIdentity', 'ses:DeleteEmailIdentity',
                    'ses:ListSuppressedDestinations', 'ses:PutSuppressedDestination', 'ses:DeleteSuppressedDestination',
                    'ses:CreateConfigurationSet', 'ses:DeleteConfigurationSet',
                    'ses:CreateConfigurationSetEventDestination', 'ses:UpdateConfigurationSetEventDestination',
                    'sns:CreateTopic', 'sns:DeleteTopic', 'sns:SetTopicAttributes', 'sns:Subscribe', 'sns:ListSubscriptionsByTopic',
                ],
                'Resource' => '*',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
