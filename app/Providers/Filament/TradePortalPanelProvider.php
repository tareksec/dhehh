<?php

namespace App\Providers\Filament;

use App\Filament\Auth\AdminLogin;
use App\Filament\Trade\Pages\Home;
use App\Filament\Trade\TradeNavigation;
use App\Http\Controllers\Trade\TradeChallanPrintController;
use App\Http\Controllers\Trade\TradeLcDocumentController;
use App\Http\Controllers\Trade\TradePortalLocaleController;
use App\Http\Controllers\Trade\TradeReportDownloadController;
use App\Http\Middleware\PreventAdminPageCaching;
use App\Http\Middleware\ResolveTradePortalCompany;
use App\Http\Middleware\RestrictPortalHosts;
use App\Http\Middleware\SetTradePortalLocale;
use App\Models\LcFile;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Services\TradeReportService;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
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
            // Navy like the theme's --trade-navy-2 (owner's reference 2026-10-23), written out shade by
            // shade: a palette generated from the hex makes 600 a mid blue, and buttons then turn pale.
            ->colors(['primary' => [
                50 => 'oklch(0.97 0.014 262)',
                100 => 'oklch(0.93 0.03 262)',
                200 => 'oklch(0.87 0.05 262)',
                300 => 'oklch(0.77 0.08 262)',
                400 => 'oklch(0.62 0.11 262)',
                500 => 'oklch(0.5 0.12 262)',
                600 => 'oklch(0.41 0.11 262)',
                700 => 'oklch(0.36 0.1 262)',
                800 => 'oklch(0.3 0.08 262)',
                900 => 'oklch(0.25 0.07 262)',
                950 => 'oklch(0.19 0.05 262)',
            ]])
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
                Route::get('lc-files/{lcFile}/papers/{document}', TradeLcDocumentController::class)
                    ->whereNumber('lcFile')
                    ->whereIn('document', array_keys(LcFile::DOCUMENTS))
                    ->name('lc-files.document');
                Route::get('reports/{report}/download/{format}', TradeReportDownloadController::class)
                    ->whereIn('report', array_keys(TradeReportService::REPORTS))
                    ->whereIn('format', ['print', 'pdf', 'xlsx'])
                    ->name('reports.download');
            })
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): View => view('filament.trade.language-switch'))
            ->renderHook(PanelsRenderHook::PAGE_HEADER_HEADING_BEFORE, fn (): View => view('filament.trade.back-button'))
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
