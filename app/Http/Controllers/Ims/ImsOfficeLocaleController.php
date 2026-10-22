<?php

namespace App\Http\Controllers\Ims;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetImsOfficeLocale;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** GET office/language/{locale}: the office's English / বাংলা switch; back to the same page. */
class ImsOfficeLocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, SetImsOfficeLocale::LOCALES, true), 404);

        $request->session()->put(SetImsOfficeLocale::SESSION_KEY, $locale);

        $previous = url()->previous();
        $target = parse_url($previous, PHP_URL_HOST) === $request->getHost() && $previous !== $request->fullUrl()
            ? $previous
            : Filament::getPanel('office')->getUrl();

        return redirect()->to($target);
    }
}
