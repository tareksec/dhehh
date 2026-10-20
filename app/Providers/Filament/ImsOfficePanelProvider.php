<?php

namespace App\Providers\Filament;

use App\Filament\Auth\AdminLogin;
use App\Filament\Clusters\Investments;
use App\Filament\Ims\Office\OfficeNavigation;
use App\Filament\Ims\Office\Pages\Home;
use App\Filament\Resources\AgreementTemplates\AgreementTemplateResource;
use App\Filament\Resources\ChannelPartners\ChannelPartnerResource;
use App\Filament\Resources\InvestmentProjects\InvestmentProjectResource;
use App\Filament\Resources\InvestmentRecords\InvestmentRecordResource;
use App\Filament\Resources\InvestorDrafts\InvestorDraftResource;
use App\Filament\Resources\InvestorGroups\InvestorGroupResource;
use App\Filament\Resources\InvestorRequests\InvestorRequestResource;
use App\Filament\Resources\Investors\InvestorResource;
use App\Filament\Resources\PayoutBatches\PayoutBatchResource;
use App\Filament\Resources\ProjectSettlements\ProjectSettlementResource;
use App\Http\Controllers\Admin\InvestmentDocumentDownloadController;
use App\Http\Controllers\Admin\InvestmentReportController;
use App\Http\Controllers\Admin\InvestorContractDownloadController;
use App\Http\Controllers\Admin\InvestorPortalOpenController;
use App\Http\Controllers\Ims\ImsOfficeFileController;
use App\Http\Controllers\Ims\ImsOfficeLocaleController;
use App\Http\Middleware\ResolveCompanyFromImsDomain;
use App\Http\Middleware\RestrictPortalHosts;
use App\Http\Middleware\SetImsOfficeLocale;
use App\Models\Company;
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
 * The IMS office (IMS-V2/ARCHITECTURE.md §4): staff run investors, KYC,
 * groups, settlements, payouts, capital returns, the feed and support at
 * "<IMS domain>/office". Same staff accounts as the ERP (guard "web"), but
 * the domain differs, so the sign-in is separate. The company is the one
 * whose IMS domain this is (ResolveCompanyFromImsDomain); RestrictPortalHosts
 * keeps /office off every other host.
 *
 * The ERP's investor resources are registered here unchanged (one code, two
 * panels); the office's own pages and resources live in app/Filament/Ims/Office.
 */
class ImsOfficePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('office')
            ->path('office')
            ->authGuard('web')
            ->login(AdminLogin::class)
            ->brandName(fn (): string => app(CompanySettingsService::class)->name($this->officeCompany()))
            ->brandLogo(fn (): ?string => app(CompanySettingsService::class)->logoUrl($this->officeCompany()))
            ->brandLogoHeight('2.5rem')
            ->colors(['primary' => Color::hex(InvestorPanelProvider::IMS_PRIMARY)])
            ->font('Hind Siliguri', provider: LocalFontProvider::class)
            ->darkMode(false)
            ->maxContentWidth(Width::Full)
            // The reference look (IMS-V2/DESIGN.md §6.1): the menu as pills in a round
            // top bar, the most used pages in an icon rail on the left.
            ->topNavigation()
            ->viteTheme('resources/css/filament/ims/theme.css')
            ->databaseNotifications(isLazy: false)
            ->homeUrl(fn (): string => Home::getUrl(panel: 'office'))
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => OfficeNavigation::build($builder))
            ->pages([
                Home::class,
                Investments::class,
            ])
            ->discoverPages(in: app_path('Filament/Ims/Office/Pages'), for: 'App\Filament\Ims\Office\Pages')
            ->resources([
                InvestorResource::class,
                InvestorDraftResource::class,
                ChannelPartnerResource::class,
                InvestorGroupResource::class,
                InvestmentProjectResource::class,
                InvestmentRecordResource::class,
                ProjectSettlementResource::class,
                PayoutBatchResource::class,
                InvestorRequestResource::class,
                AgreementTemplateResource::class,
            ])
            ->discoverResources(in: app_path('Filament/Ims/Office/Resources'), for: 'App\Filament\Ims\Office\Resources')
            ->discoverWidgets(in: app_path('Filament/Ims/Office/Widgets'), for: 'App\Filament\Ims\Office\Widgets')
            ->routes(function (): void {
                Route::get('language/{locale}', ImsOfficeLocaleController::class)->whereIn('locale', SetImsOfficeLocale::LOCALES)->name('language');
            })
            // The ERP's investor downloads, on the IMS domain (App\Support\ImsPanelLinks).
            ->authenticatedRoutes(function (): void {
                Route::name('files.')->prefix('files')->group(function (): void {
                    Route::get('investment-documents/{document}/download', InvestmentDocumentDownloadController::class)->whereNumber('document')->name('investment-documents.download');
                    Route::get('investor-security-instruments/{instrument}/contract', InvestorContractDownloadController::class)->whereNumber('instrument')->name('investor-security-instruments.contract');
                    Route::get('settlement-payouts/{payout}/report', [InvestmentReportController::class, 'investorPayout'])->whereNumber('payout')->name('investments.reports.investor-payout');
                    Route::get('projects/{project}/register', [InvestmentReportController::class, 'projectRegister'])->whereNumber('project')->name('investments.reports.project-register');
                    Route::get('investor-portal/investors/{investor}/open', [InvestorPortalOpenController::class, 'investor'])->whereNumber('investor')->middleware('throttle:30,1')->name('investor-portal.open-investor');
                    Route::get('investor-portal/channel-partners/{partner}/open', [InvestorPortalOpenController::class, 'partner'])->whereNumber('partner')->middleware('throttle:30,1')->name('investor-portal.open-partner');
                    Route::get('kyc/{document}', [ImsOfficeFileController::class, 'kyc'])->whereNumber('document')->name('kyc');
                    Route::get('update-photos/{photo}', [ImsOfficeFileController::class, 'updatePhoto'])->whereNumber('photo')->name('update-photo');
                    Route::get('feed-images/{image}', [ImsOfficeFileController::class, 'feedImage'])->whereNumber('image')->name('feed-image');
                    Route::get('capital-returns/{return}/proof', [ImsOfficeFileController::class, 'capitalReturnProof'])->whereNumber('return')->name('capital-return-proof');
                    Route::get('project-reports/{report}/pdf', [ImsOfficeFileController::class, 'projectReport'])->whereNumber('report')->name('project-report');
                });
            })
            ->renderHook(PanelsRenderHook::BODY_START, fn (): View => view('filament.ims.icon-rail', ['rail' => OfficeNavigation::rail()]))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): View => view('filament.ims.office.language-switch'))
            // Small phones: the language switch sits in the menu drawer instead of the top bar.
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn (): View => view('filament.ims.office.language-switch', ['drawer' => true]))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn (): View => view('filament.ims.auth-hero', ['panel' => 'office']))
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_START, fn (): View => view('filament.ims.office.language-switch', ['simple' => true]))
            ->middleware([
                RestrictPortalHosts::class,
                ResolveCompanyFromImsDomain::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                SetImsOfficeLocale::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Livewire's own update requests re-run only these; without them
            // the company would not be resolved on a form submit.
            ->persistentMiddleware([
                ResolveCompanyFromImsDomain::class,
                SetImsOfficeLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    protected function officeCompany(): ?Company
    {
        return app(CompanyContext::class)->company();
    }
}
