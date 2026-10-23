<?php

namespace App\Http\Controllers\Trade;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetTradePortalLocale;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** GET trade/language/{locale}: the portal's বাংলা / English switch; back to the same page. */
class TradePortalLocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, SetTradePortalLocale::LOCALES, true), 404);

        $request->session()->put(SetTradePortalLocale::SESSION_KEY, $locale);

        $previous = url()->previous();
        $target = parse_url($previous, PHP_URL_HOST) === $request->getHost() && $previous !== $request->fullUrl()
            ? $previous
            : Filament::getPanel('trade')->getUrl();

        return redirect()->to($target);
    }
}
