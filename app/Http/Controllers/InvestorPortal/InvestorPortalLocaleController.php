<?php

namespace App\Http\Controllers\InvestorPortal;

use App\Filament\Investor\Pages\Dashboard;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetInvestorPortalLocale;
use App\Models\InvestorPortalAccount;
use App\Support\InvestorPortalStaffPreview;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET investor/language/{locale}: the English / বাংলা switch. Remembers the
 * choice for this browser and, for a signed-in person, on the account (the
 * same language their portal SMS use). A staff preview only changes its own
 * browser, never the person's account.
 */
class InvestorPortalLocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, SetInvestorPortalLocale::LOCALES, true), 404);

        $request->session()->put(SetInvestorPortalLocale::SESSION_KEY, $locale);

        $account = Filament::auth()->user();

        if ($account instanceof InvestorPortalAccount && ! InvestorPortalStaffPreview::isFor($account) && $account->locale !== $locale) {
            $account->forceFill(['locale' => $locale])->save();
        }

        // Back to the page the switch was on, never to another host.
        $previous = url()->previous();
        $target = parse_url($previous, PHP_URL_HOST) === $request->getHost() && $previous !== $request->fullUrl()
            ? $previous
            : ($account instanceof InvestorPortalAccount ? Dashboard::getUrl() : Filament::getLoginUrl());

        return redirect()->to($target);
    }
}
