<?php

namespace App\Providers\Filament;

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
use App\Filament\Investor\Pages\MyInvestments;
use App\Filament\Investor\Pages\MyInvestors;
use App\Filament\Investor\Pages\Payouts;
use App\Filament\Investor\Pages\Profile;
use App\Filament\Investor\Pages\Requests;
use App\Filament\Investor\Pages\Statement;
use App\Http\Controllers\InvestorPortal\InvestorPortalDocumentController;
use App\Http\Controllers\InvestorPortal\InvestorPortalReportController;
use App\Http\Controllers\InvestorPortal\InvestorPortalStaffEntryController;
use App\Http\Middleware\ResolveCompanyFromInvestorPortalDomain;
use App\Http\Middleware\RestrictPortalHosts;
use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
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
            ->darkModeBrandLogo(fn (): ?string => app(CompanySettingsService::class)->darkLogoUrl(company: $this->portalCompany()))
            ->pages([
                Dashboard::class,
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
            ])
            ->homeUrl(fn (): string => Dashboard::getUrl())
            ->routes(function (): void {
                Route::get('invite/{token}', AcceptInvite::class)->name('invite');
                // One-time link from the dashboard's "Open investor portal" button.
                Route::get('staff-entry/{token}', InvestorPortalStaffEntryController::class)->middleware('throttle:20,1')->name('staff-entry');
            })
            ->authenticatedRoutes(function (): void {
                Route::get('documents/{document}/download', InvestorPortalDocumentController::class)->name('documents.download');
                Route::get('payouts/{payout}/report', [InvestorPortalReportController::class, 'payout'])->name('reports.payout');
                Route::get('statement/print', [InvestorPortalReportController::class, 'statement'])->name('reports.statement');
            })
            ->renderHook(PanelsRenderHook::CONTENT_BEFORE, fn (): View => view('filament.investor.staff-preview-banner'))
            ->viteTheme('resources/css/filament/investor/theme.css')
            ->middleware([
                RestrictPortalHosts::class,
                ResolveCompanyFromInvestorPortalDomain::class,
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
            // requests (every form submit on the portal); without this the
            // company would not be resolved there (the reseller portal's bug).
            ->persistentMiddleware([
                ResolveCompanyFromInvestorPortalDomain::class,
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
