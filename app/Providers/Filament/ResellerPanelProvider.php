<?php

namespace App\Providers\Filament;

use App\Filament\Reseller\Pages\Auth\Apply;
use App\Filament\Reseller\Pages\Auth\ForgotPassword;
use App\Filament\Reseller\Pages\Auth\Login;
use App\Filament\Reseller\Pages\Auth\ResetPasswordByLink;
use App\Filament\Reseller\Pages\CreateOrder;
use App\Filament\Reseller\Pages\Dashboard;
use App\Filament\Reseller\Pages\Earnings;
use App\Filament\Reseller\Pages\EditOrder;
use App\Filament\Reseller\Pages\FraudChecker;
use App\Filament\Reseller\Pages\MyStore;
use App\Filament\Reseller\Pages\Orders;
use App\Filament\Reseller\Pages\PayoutAccounts;
use App\Filament\Reseller\Pages\Products;
use App\Filament\Reseller\Pages\Profile;
use App\Filament\Reseller\Pages\Withdrawals;
use App\Http\Middleware\ResolveCompanyFromResellerPortalDomain;
use App\Http\Middleware\RestrictPortalHosts;
use App\Models\Company;
use App\Models\Customer;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Services\DynamicColorService;
use App\Services\ResellerOwnCourierService;
use App\Support\ModuleGuard;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The reseller portal (13_RESELLER_PORTAL_PLAN.md). Reachable at
 * "reseller.<company-domain>" or a company's own custom
 * reseller_portal_domain, both resolved by ResolveCompanyFromResellerPortalDomain
 * -- never by Filament's own ->domain(), which only matches one fixed host
 * and can't express "any company's domain, prefixed with reseller.". The
 * panel is path-scoped ("/portal") instead, on every host; RestrictPortalHosts
 * (registered here AND globally in bootstrap/app.php) is what actually keeps
 * it off the shop/admin hosts and keeps everything else off the portal host.
 */
class ResellerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('reseller')
            ->path('portal')
            ->authGuard('customer')
            ->login(Login::class)
            ->registration(Apply::class)
            // Reset by SMS code on one page (ForgotPassword). Filament's own
            // e-mailed link is not used; the signed "reset" route is the page
            // behind the link staff email from the admin (ResetPasswordByLink).
            ->passwordReset(ForgotPassword::class, ResetPasswordByLink::class)
            ->brandName(fn (): string => app(CompanySettingsService::class)->name($this->portalCompany()))
            ->brandLogo(fn (): ?string => app(CompanySettingsService::class)->logoUrl($this->portalCompany()))
            ->darkModeBrandLogo(fn (): ?string => app(CompanySettingsService::class)->darkLogoUrl(company: $this->portalCompany()))
            ->pages([
                Dashboard::class,
                CreateOrder::class,
                EditOrder::class,
                Orders::class,
                FraudChecker::class,
                Products::class,
                Earnings::class,
                Withdrawals::class,
                PayoutAccounts::class,
                MyStore::class,
                Profile::class,
            ])
            ->homeUrl(Dashboard::class)
            // The name at the top of the user menu opens the Profile page (owner request, 2026-10-10).
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action->url(fn (): string => Profile::getUrl()),
            ])
            // The reseller's deposit, beside the user menu on every page (owner rule, 2026-10-06).
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): View|string => $this->depositBadge())
            // The tab bar at the bottom of a phone screen, for approved resellers only.
            ->renderHook(PanelsRenderHook::LAYOUT_END, fn (): View|string => $this->bottomNav())
            // "Read the rules first" above the application form, while the guide is on.
            ->renderHook(PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE, fn (): View|string => $this->guideLink())
            // Hind Siliguri, self-hosted: @font-face lives in the portal theme, so no font URL here.
            ->font('Hind Siliguri', provider: LocalFontProvider::class)
            // The store's own colour (Storefront Settings > theme colour) instead of Filament's default amber.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => $this->brandColorStyle())
            ->viteTheme('resources/css/filament/reseller/theme.css')
            ->middleware([
                RestrictPortalHosts::class,
                ResolveCompanyFromResellerPortalDomain::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Livewire re-runs only "persistent" middleware on its own update
            // requests (every form submit and table action on the portal).
            // Without this the company is not resolved from the host there, so
            // an application would be saved to the default company and a
            // login could not find the reseller's company.
            ->persistentMiddleware([
                ResolveCompanyFromResellerPortalDomain::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    protected function portalCompany(): ?Company
    {
        return app(CompanyContext::class)->company();
    }

    /**
     * The portal's primary colour shades as CSS variables, from the company's
     * storefront theme colour (the same colour its reseller stores wear). Kept
     * amber when the company has no storefront settings yet.
     */
    protected function brandColorStyle(): HtmlString
    {
        return ModuleGuard::run('reseller-portal.brand-colour', function (): HtmlString {
            $hex = $this->portalCompany()?->storefrontSetting?->normalizedThemeColor() ?? DynamicColorService::DEFAULT_COLOR;
            $declarations = implode('; ', app(DynamicColorService::class)->cssVariables($hex));

            return new HtmlString('<style>:root { '.e($declarations).'; }</style>');
        }, new HtmlString(''));
    }

    protected function guideLink(): View|string
    {
        if (! ($this->portalCompany()?->storefrontSetting?->reseller_guide_enabled ?? true)) {
            return '';
        }

        return view('filament.reseller.guide-link', ['url' => route('reseller.portal.guide')]);
    }

    /**
     * The phone-width tab bar (owner request 2026-10-15): Home, Products, a
     * raised "create order" button, Orders and Earnings. Hidden from the large
     * screens, where the sidebar shows everything, and from anyone who is not
     * an approved reseller (the login and apply pages, a pending application).
     */
    protected function bottomNav(): View|string
    {
        return ModuleGuard::run('reseller-portal.bottom-nav', function (): View|string {
            $reseller = Filament::auth()->user();

            if (! $reseller instanceof Customer || ! $reseller->isApprovedReseller()) {
                return '';
            }

            return view('filament.reseller.bottom-nav', ['items' => [
                ['key' => 'dashboard', 'label' => Dashboard::getNavigationLabel(), 'icon' => 'heroicon-o-home', 'url' => Dashboard::getUrl(), 'exact' => true, 'primary' => false],
                ['key' => 'products', 'label' => Products::getNavigationLabel(), 'icon' => 'heroicon-o-shopping-bag', 'url' => Products::getUrl(), 'exact' => false, 'primary' => false],
                ['key' => 'create-order', 'label' => __('Create order'), 'icon' => 'heroicon-o-plus', 'url' => CreateOrder::getUrl(), 'exact' => false, 'primary' => true],
                ['key' => 'orders', 'label' => Orders::getNavigationLabel(), 'icon' => 'heroicon-o-clipboard-document-list', 'url' => Orders::getUrl(), 'exact' => false, 'primary' => false],
                ['key' => 'earnings', 'label' => Earnings::getNavigationLabel(), 'icon' => 'heroicon-o-banknotes', 'url' => Earnings::getUrl(), 'exact' => false, 'primary' => false],
            ]]);
        }, '');
    }

    /** Only approved resellers have a deposit (overpaid own-courier advances, ResellerOwnCourierService). */
    protected function depositBadge(): View|string
    {
        $reseller = Filament::auth()->user();

        if (! $reseller instanceof Customer || ! $reseller->isApprovedReseller()) {
            return '';
        }

        return view('filament.reseller.deposit-badge', [
            'balance' => app(ResellerOwnCourierService::class)->depositBalance($reseller),
            'currency' => app(CompanyContext::class)->company()?->currency ?: 'BDT',
        ]);
    }
}
