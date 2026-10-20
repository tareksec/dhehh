<?php

namespace App\Http\Controllers\InvestorPortal;

use App\Http\Controllers\Controller;
use App\Models\InvestorPortalAccount;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\InvestorBusinessSwitchService;
use App\Services\InvestorPortalAccountService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Both ends of the portal's business switcher (InvestorBusinessSwitchService,
 * 17_IMS_IN_PORTAL_PLAN.md §5): "switch" makes another business the active
 * one (in this session on the central sign-in host, or by sending the
 * browser to that business's own host with a one-time token); "enter" trades
 * that token for a sign-in to the person's account there. It sets no
 * "remember me" cookie. A token that was used, expired or is not for this
 * portal goes to the sign-in page, never into an account.
 */
class InvestorBusinessSwitchController extends Controller
{
    public function switch(Request $request, int $company, InvestorBusinessSwitchService $switches): RedirectResponse
    {
        $account = Filament::auth()->user();
        $url = $account instanceof InvestorPortalAccount ? $switches->switchTo($account, $company, $request) : null;

        if ($url === null) {
            Notification::make()->danger()->title(__('That business cannot be opened from here.'))->send();

            return redirect()->to(Filament::getPanel('investor')->getUrl());
        }

        return redirect()->to($url)->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    public function enter(Request $request, string $token, CompanyContext $context, InvestorBusinessSwitchService $switches): RedirectResponse
    {
        $panel = Filament::getPanel('investor');
        $company = $context->company();
        $account = $company ? $switches->redeem($token, $company) : null;

        if ($account === null || ! $account->canAccessPanel($panel)) {
            Notification::make()->danger()->title(__('This link has expired. Please sign in.'))->send();

            return redirect()->to($panel->getLoginUrl());
        }

        Auth::shouldUse($panel->getAuthGuard());
        $panel->auth()->login($account);
        $request->session()->regenerate();
        app(InvestorPortalAccountService::class)->recordLogin($account, $request->ip(), 'business_switch');
        app(AuditLogService::class)->record('investor_portal.business_switched', $account);

        return redirect()->to($panel->getUrl())->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
