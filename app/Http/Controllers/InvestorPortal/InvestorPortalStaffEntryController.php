<?php

namespace App\Http\Controllers\InvestorPortal;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\CompanyContext;
use App\Services\InvestorPortalStaffEntryService;
use App\Support\InvestorPortalStaffPreview;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The portal end of "Open investor portal" (InvestorPortalStaffEntryService):
 * trades the one-time token for a staff-preview session and lands on the
 * portal's home page. It does not touch the person's last sign-in and sets no
 * "remember me" cookie. A token that was used, expired or is not for this
 * portal goes to the sign-in page, never into an account.
 */
class InvestorPortalStaffEntryController extends Controller
{
    public function __invoke(Request $request, string $token, CompanyContext $context, InvestorPortalStaffEntryService $entries): RedirectResponse
    {
        $panel = Filament::getPanel('investor');
        $company = $context->company();
        $entry = $company ? $entries->redeem($token, $company) : null;

        if ($entry === null) {
            Notification::make()->danger()->title(__('This staff link has expired. Open the portal again from the dashboard.'))->send();

            return redirect()->to($panel->getLoginUrl());
        }

        Auth::shouldUse($panel->getAuthGuard());
        $panel->auth()->login($entry['account']);
        $request->session()->regenerate();
        InvestorPortalStaffPreview::start($request, $entry['account'], $entry['staff_user_id'], $entry['staff_name']);

        app(AuditLogService::class)->record('investor_portal.staff_entered', $entry['account']);

        return redirect()->to($panel->getUrl())->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
