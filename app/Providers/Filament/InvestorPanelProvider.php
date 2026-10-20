<?php

namespace App\Providers\Filament;

use App\Filament\Ims\Portal\Pages\Auth\JoinWithInvite;
use App\Filament\Ims\Portal\PortalNavigation;
use App\Filament\Ims\Portal\Pages\Feed;
use App\Filament\Ims\Portal\Pages\Kyc;
use App\Filament\Ims\Portal\Pages\Nominees;
use App\Filament\Ims\Portal\Pages\Notifications;
use App\Filament\Ims\Portal\Pages\ProjectDetail;
use App\Filament\Ims\Portal\Pages\Projects;
use App\Filament\Ims\Portal\Pages\Support;
use App\Filament\Investor\Pages\Agreements;
use App\Filament\Investor\Pages\Auth\AcceptInvite;
use App\Filament\Investor\Pages\Auth\ForgotPassword;
use App\Filament\Investor\Pages\Auth\Login;
use App\Filament\Investor\Pages\Auth\Register;
use App\Filament\Investor\Pages\BankAccounts;
use App\Filament\Investor\Pages\Commissions;
use App\Filament\Investor\Pages\Dashboard;
use App\Filament\Investor\Pages\Documents;
use App\Filament\Investor\Pages\Invite;
use App\Filament\Investor\Pages\MyBusinesses;
use App\Filament\Investor\Pages\MyInvestments;
use App\Filament\Investor\Pages\MyInvestors;
use App\Filament\Investor\Pages\Payouts;
use App\Filament\Investor\Pages\Profile;
use App\Filament\Investor\Pages\Requests;
use App\Filament\Investor\Pages\Statement;
use App\Http\Controllers\Ims\ImsPortalFileController;
use App\Http\Controllers\Ims\ImsPortalStatementController;
use App\Http\Controllers\InvestorPortal\InvestorBusinessSwitchController;
use App\Http\Controllers\InvestorPortal\InvestorPortalDocumentController;
use App\Http\Controllers\InvestorPortal\InvestorPortalLocaleController;
use App\Http\Controllers\InvestorPortal\InvestorPortalReportController;
use App\Http\Controllers\InvestorPortal\InvestorPortalStaffEntryController;
use App\Http\Middleware\ResolveCompanyFromCentralInvestorSession;
use App\Http\Middleware\ResolveCompanyFromInvestorPortalDomain;
use App\Http\Middleware\RestrictPortalHosts;
use App\Http\Middleware\SetInvestorPortalLocale;
use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The investor portal (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md): investors
 * and channel partners see their own investments, statement, payouts,
 * documents and commissions. Reached at "investor.<company domain>/investor"
 * or the company's own investor_portal_domain, resolved by
 * ResolveCompanyFromInvestorPortalDomain; RestrictPortalHosts keeps it off
 * every other host, the same way as the reseller portal. Accounts are
 * created by staff (invite by SMS) or by self sign-up through a channel
 * partner's invite link, reviewed in Investor Drafts.
 */
class InvestorPanelProvider extends PanelProvider
{
    /**
     * Buttons and links in near-black ink; the lime highlight is the theme
     * CSS's --portal-accent. Shade 600 is the button colour.
     */
    public const INK = [
        50 => 'oklch(0.975 0.003 260)',
        100 => 'oklch(0.95 0.005 260)',
        200 => 'oklch(0.9 0.008 260)',
        300 => 'oklch(0.82 0.01 260)',
        400 => 'oklch(0.72 0.012 260)',
        500 => 'oklch(0.45 0.015 260)',
        600 => 'oklch(0.27 0.014 260)',
        700 => 'oklch(0.23 0.013 260)',
        800 => 'oklch(0.2 0.012 260)',
        900 => 'oklch(0.17 0.01 260)',
        950 => 'oklch(0.13 0.008 260)',
    ];

    /** IMS v2's deep green (IMS-V2/DESIGN.md §3 --ims-primary); both IMS panels build their palette from it. */
    public const IMS_PRIMARY = '#16834F';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('investor')
            ->path('investor')
            ->authGuard('investor')
            ->login(Login::class)
            // Self sign-up only through a channel partner's invite link (?code=…).
            ->registration(Register::class)
            // Reset by SMS code on one page; Filament's e-mailed link is not used.
            ->passwordReset(ForgotPassword::class, ForgotPassword::class)
            ->brandName(fn (): string => app(CompanySettingsService::class)->name($this->portalCompany()))
            ->brandLogo(fn (): ?string => app(CompanySettingsService::class)->logoUrl($this->portalCompany()))
            // Header logo at a standard size (Filament's 1.5rem default is tiny); the sign-in pages use a larger one from the theme CSS.
            ->brandLogoHeight('2.75rem')
            // Only a real dark logo: the sidebar is dark in both modes and shows it plain; a light logo sits on a white chip there.
            ->darkModeBrandLogo(fn (): ?string => app(CompanySettingsService::class)->darkLogoUrl(fallbackToLight: false, company: $this->portalCompany()))
            ->pages([
                Dashboard::class,
                MyBusinesses::class,
                MyInvestments::class,
                Statement::class,
                Payouts::class,
                Documents::class,
                Requests::class,
                BankAccounts::class,
                Agreements::class,
                MyInvestors::class,
                Commissions::class,
                Invite::class,
                Profile::class,
                // IMS v2 (app/Filament/Ims/Portal).
                Kyc::class,
                Nominees::class,
                Projects::class,
                ProjectDetail::class,
                Notifications::class,
                Feed::class,
                Support::class,
            ])
            // IMS v2 look (IMS-V2/DESIGN.md §6, the owner's reference images, 2026-10-19):
            // a round top bar with the main pages as pills and "More", an icon rail on
            // the left of wide screens, and the bottom tab bar on phones.
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => PortalNavigation::build($builder))
            ->topNavigation()
            ->colors(['primary' => Color::hex(self::IMS_PRIMARY)])
            // Light and dark mode; light until the person picks dark (the moon button in the top bar and on the sign-in page).
            ->darkMode()
            ->defaultThemeMode(ThemeMode::Light)
            // Hind Siliguri, self-hosted: @font-face lives in the portal theme.
            ->font('Hind Siliguri', provider: LocalFontProvider::class)
            ->homeUrl(fn (): string => Dashboard::getUrl())
            // The name at the top of the user menu opens the Profile page.
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action->url(fn (): string => Profile::getUrl()),
            ])
            ->routes(function (): void {
                Route::get('invite/{token}', AcceptInvite::class)->name('invite');
                // IMS v2: joining with an office invite + a one-time code.
                Route::get('join/{token}', JoinWithInvite::class)->middleware('throttle:30,1')->name('join');
                Route::get('language/{locale}', InvestorPortalLocaleController::class)->whereIn('locale', SetInvestorPortalLocale::LOCALES)->name('language');
                // One-time link from the dashboard's "Open investor portal" button.
                Route::get('staff-entry/{token}', InvestorPortalStaffEntryController::class)->middleware('throttle:20,1')->name('staff-entry');
                // One person, many businesses (17_IMS_IN_PORTAL_PLAN.md §5): the other business's end of the switcher.
                Route::get('business-entry/{token}', [InvestorBusinessSwitchController::class, 'enter'])->middleware('throttle:20,1')->name('business-entry');
            })
            ->authenticatedRoutes(function (): void {
                Route::get('documents/{document}/download', InvestorPortalDocumentController::class)->name('documents.download');
                Route::get('payouts/{payout}/report', [InvestorPortalReportController::class, 'payout'])->name('reports.payout');
                Route::get('statement/print', [InvestorPortalReportController::class, 'statement'])->name('reports.statement');
                Route::post('switch-business/{company}', [InvestorBusinessSwitchController::class, 'switch'])->whereNumber('company')->middleware('throttle:20,1')->name('switch-business');
                // IMS v2 files, checked against what this investor may see.
                Route::name('ims.')->prefix('ims')->group(function (): void {
                    Route::get('kyc/{document}', [ImsPortalFileController::class, 'kyc'])->whereNumber('document')->name('kyc');
                    Route::get('update-photos/{photo}', [ImsPortalFileController::class, 'updatePhoto'])->whereNumber('photo')->name('update-photo');
                    Route::get('feed-images/{image}', [ImsPortalFileController::class, 'feedImage'])->whereNumber('image')->name('feed-image');
                    Route::get('project-reports/{report}/pdf', [ImsPortalFileController::class, 'projectReport'])->whereNumber('report')->name('project-report');
                    Route::get('statements/{period}/pdf', [ImsPortalStatementController::class, 'pdf'])->where('period', '\d{4}-\d{2}')->name('statement-pdf');
                });
            })
            ->renderHook(PanelsRenderHook::CONTENT_BEFORE, fn (): View => view('filament.investor.staff-preview-banner'))
            ->renderHook(PanelsRenderHook::BODY_START, fn (): View => view('filament.ims.icon-rail', ['rail' => PortalNavigation::rail()]))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): View => view('filament.investor.business-switch'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): View => view('filament.investor.language-switch'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): View => view('filament.ims.theme-toggle'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): View => view('filament.ims.portal.bell'))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn (): View => view('filament.ims.auth-hero', ['panel' => 'investor']))
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_START, fn (): View => view('filament.ims.auth-tools', ['panel' => 'investor']))
            // Phones: Home / Investments / Payouts / Menu along the bottom of the screen.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.investor.mobile-nav'))
            // IMS v2 look (IMS-V2/DESIGN.md): the shared green IMS theme.
            ->viteTheme('resources/css/filament/ims/theme.css')
            ->middleware([
                RestrictPortalHosts::class,
                ResolveCompanyFromInvestorPortalDomain::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // The central sign-in host has no company until the session says which one (17_IMS_IN_PORTAL_PLAN.md §5).
                ResolveCompanyFromCentralInvestorSession::class,
                AuthenticateSession::class,
                SetInvestorPortalLocale::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Livewire re-runs only "persistent" middleware on its own update
            // requests (every form submit on the portal); without this the
            // company would not be resolved there (the reseller portal's bug).
            ->persistentMiddleware([
                ResolveCompanyFromInvestorPortalDomain::class,
                ResolveCompanyFromCentralInvestorSession::class,
                SetInvestorPortalLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    protected function portalCompany(): ?Company
    {
        return app(CompanyContext::class)->company();
    }
}
