<?php

namespace App\Providers\Filament;

use App\Filament\Auth\AdminLogin;
use App\Filament\Trade\Pages\Home;
use App\Filament\Trade\TradeNavigation;
use App\Http\Controllers\Trade\TradeChallanPrintController;
use App\Http\Controllers\Trade\TradePortalLocaleController;
use App\Http\Middleware\PreventAdminPageCaching;
use App\Http\Middleware\ResolveTradePortalCompany;
use App\Http\Middleware\RestrictPortalHosts;
use App\Http\Middleware\SetTradePortalLocale;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
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
 * The Trade Portal (docs/tasneem-samples/PLAN.md §1, BUILD-BRIEF.md): a small,
 * phone-first, Bangla-first panel at "/trade" on the main domain for a company
 * that has it switched on (Companies → Trade Portal). Same staff accounts as
 * the ERP (guard "web"); the "trade.access" permission lets someone in, and a
 * role with only "trade.*" permissions (Trade Owner) keeps them out of /admin
 * (User::canAccessPanel()). The open company is the person's choice, checked
 * on every request by ResolveTradePortalCompany.
 *
 * Its pages live in app/Filament/Trade and call the ERP's own services; no
 * ERP admin screen is registered here.
 */
class TradePortalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('trade')
            ->path('trade')
            ->authGuard('web')
            ->login(AdminLogin::class)
            ->brandName(fn (): string => app(CompanySettingsService::class)->name(app(CompanyContext::class)->company()))
            ->brandLogo(fn (): ?string => app(CompanySettingsService::class)->logoUrl(app(CompanyContext::class)->company()))
            ->brandLogoHeight('2.25rem')
            // Tailwind blue (its 700 is the theme's --trade-primary): buttons get a strong blue with white text.
            ->colors(['primary' => Color::Blue])
            ->font('Hind Siliguri', provider: LocalFontProvider::class)
            // Light only: the owner works in daylight in the shop (BUILD-BRIEF.md "light theme").
            ->darkMode(false)
            ->maxContentWidth(Width::SevenExtraLarge)
            ->topNavigation()
            ->viteTheme('resources/css/filament/trade/theme.css')
            ->homeUrl(fn (): string => Home::getUrl(panel: 'trade'))
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => TradeNavigation::build($builder))
            ->pages([
                Home::class,
            ])
            ->discoverPages(in: app_path('Filament/Trade/Pages'), for: 'App\Filament\Trade\Pages')
            ->discoverResources(in: app_path('Filament/Trade/Resources'), for: 'App\Filament\Trade\Resources')
            ->routes(function (): void {
                Route::get('language/{locale}', TradePortalLocaleController::class)->whereIn('locale', SetTradePortalLocale::LOCALES)->name('language');
            })
            ->authenticatedRoutes(function (): void {
                Route::get('challans/{challan}/print', TradeChallanPrintController::class)->whereNumber('challan')->name('challans.print');
            })
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): View => view('filament.trade.language-switch'))
            // Phones: the bottom tab bar with its menu sheet.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.trade.mobile-nav'))
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_START, fn (): View => view('filament.trade.language-switch', ['simple' => true]))
            ->middleware([
                RestrictPortalHosts::class,
                PreventAdminPageCaching::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ResolveTradePortalCompany::class,
                SetTradePortalLocale::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Livewire's own update requests re-run only these; without them
            // the company would not be resolved on a form submit.
            ->persistentMiddleware([
                ResolveTradePortalCompany::class,
                SetTradePortalLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
