<?php

namespace App\Providers\Filament;

use App\Filament\Reseller\Pages\Auth\Apply;
use App\Filament\Reseller\Pages\Auth\ForgotPassword;
use App\Filament\Reseller\Pages\Auth\Login;
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
use App\Http\Middleware\RestrictResellerPortalHost;
use App\Models\Company;
use App\Models\Customer;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Services\ResellerOwnCourierService;
use Filament\Facades\Filament;
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
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The reseller portal (13_RESELLER_PORTAL_PLAN.md). Reachable at
 * "reseller.<company-domain>" or a company's own custom
 * reseller_portal_domain, both resolved by ResolveCompanyFromResellerPortalDomain
 * -- never by Filament's own ->domain(), which only matches one fixed host
 * and can't express "any company's domain, prefixed with reseller.". The
 * panel is path-scoped ("/portal") instead, on every host; RestrictResellerPortalHost
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
            // Reset by SMS code on one page (ForgotPassword); Filament's
            // e-mailed link is not used, so its signed "reset" route only
            // points back at the same page.
            ->passwordReset(ForgotPassword::class, ForgotPassword::class)
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
            // The reseller's deposit, beside the user menu on every page (owner rule, 2026-10-06).
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): View|string => $this->depositBadge())
            // "Read the rules first" above the application form, while the guide is on.
            ->renderHook(PanelsRenderHook::AUTH_REGISTER_FORM_BEFORE, fn (): View|string => $this->guideLink())
            ->viteTheme('resources/css/filament/reseller/theme.css')
            ->middleware([
                RestrictResellerPortalHost::class,
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

    protected function guideLink(): View|string
    {
        if (! ($this->portalCompany()?->storefrontSetting?->reseller_guide_enabled ?? true)) {
            return '';
        }

        return view('filament.reseller.guide-link', ['url' => route('reseller.portal.guide')]);
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
