<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\ManagesCompanySite;
use App\Models\StorefrontSetting;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\Email\EmailProvider;
use App\Services\Email\EmailUsageService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Settings → Email delivery (owner request 2026-11-02, replacing the usage section of the Amazon SES page): what
 * a site's email account sent, whichever mail service it is. The service is found from the SMTP host saved in
 * Integrations → Email Setup (EmailProvider); the numbers are counted from the company's Email Log for that
 * host (EmailUsageService), so Amazon SES, Cloudflare, Brevo, Gmail or any other SMTP service works the same
 * way. No service's API gives its free allowance or price, so the owner types them in, kept per site.
 * Delivered / bounced / spam only exist for a service that reports back (Amazon SES with tracking on); the page
 * says so for the others.
 */
class EmailDelivery extends Page
{
    use ManagesCompanySite;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'email-delivery';

    protected string $view = 'filament.pages.email-delivery';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public ?int $companyId = null;

    /** The site (StorefrontSetting row) whose email account and cost settings are shown. */
    #[Locked]
    public ?int $settingId = null;

    /** 0 = this month, 1 = last month: the month the numbers are about. */
    public int $monthsBack = 0;

    /** Cost settings: the free emails each month and the price of 1,000 emails after that (typed, so strings). */
    public ?string $freeLimit = null;

    public ?string $pricePer1000 = null;

    public static function getNavigationLabel(): string
    {
        return __('Email delivery');
    }

    public function getTitle(): string
    {
        return __('Email delivery');
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

    protected function pageName(): string
    {
        return 'Email delivery';
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
                        $this->fillForm();
                    })
                    ->helperText(__('The email account belongs to one site. Pick the site.')),
            ]);
    }

    protected function fillForm(): void
    {
        $setting = $this->activeSetting();

        $this->form->fill(['site_setting_id' => $setting?->getKey()]);

        $costs = EmailUsageService::settingsFor($setting);
        $this->freeLimit = $costs['free_limit'] === null ? null : (string) $costs['free_limit'];
        $this->pricePer1000 = $costs['price'] === null ? null : rtrim(rtrim(number_format($costs['price'], 4, '.', ''), '0'), '.');
    }

    /** The mail service the shown site sends through. */
    public function provider(): EmailProvider
    {
        $this->selectedCompany();

        return EmailProvider::forSetting($this->activeSetting());
    }

    /** Whether the service reports delivered / bounced / spam back for this site (so those numbers mean something). */
    public function trackingOn(): bool
    {
        return $this->provider()->trackingOn($this->activeSetting());
    }

    /** This month's (or last month's) usage, counted from the company's Email Log. */
    public function usage(): array
    {
        $this->selectedCompany();
        $setting = $this->activeSetting();

        return app(EmailUsageService::class)->report(
            (int) $this->companyId,
            EmailProvider::forSetting($setting)->via,
            $this->monthsBack,
            EmailUsageService::settingsFor($setting),
        );
    }

    public function showMonth(int $monthsBack): void
    {
        $this->selectedCompany();
        $this->monthsBack = max(0, min(1, $monthsBack));
    }

    /** No mail service tells the free allowance or the price, so the owner types them in. Empty clears one. */
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

        // The values typed on the Amazon SES page before 2026-11-02 (`ses_*`) are replaced by the new ones.
        unset($credentials['ses_free_limit'], $credentials['ses_price_per_1000']);

        if (filled($this->freeLimit)) {
            $credentials['email_free_limit'] = (int) $this->freeLimit;
        } else {
            unset($credentials['email_free_limit']);
        }

        if (filled($this->pricePer1000)) {
            $credentials['email_price_per_1000'] = rtrim(rtrim(number_format((float) $this->pricePer1000, 4, '.', ''), '0'), '.') ?: '0';
        } else {
            unset($credentials['email_price_per_1000']);
        }

        $setting->forceFill(['notification_credentials' => $credentials])->save();

        app(AuditLogService::class)->record('email.cost_settings_saved', $setting, [], [
            'site' => $setting->getKey(),
            'free_limit' => $credentials['email_free_limit'] ?? null,
            'price_per_1000' => $credentials['email_price_per_1000'] ?? null,
        ]);

        $this->fillForm();

        Notification::make()->title(__('Cost settings saved'))->success()->send();
    }

    /** Where to change the email account itself (Integrations → Email Setup), or null when the user may not open it. */
    public function emailSetupUrl(): ?string
    {
        return Integrations::canAccess() ? Integrations::getUrl() : null;
    }

    public function amazonSesUrl(): ?string
    {
        return AmazonSes::canAccess() ? AmazonSes::getUrl() : null;
    }

    /** The saved site row, for the view (its sending domain and From address). */
    public function site(): ?StorefrontSetting
    {
        $this->selectedCompany();

        return $this->activeSetting();
    }
}
