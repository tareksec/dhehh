<?php

namespace App\Http\Controllers\Auth;

use App\Support\ImsPanelSessions;
use Filament\Auth\Http\Controllers\LogoutController;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Filament\Facades\Filament;

/**
 * Filament's sign-out, except on an IMS panel while the other IMS panel is
 * still signed in in the same browser (ImsPanelSessions): then only this
 * panel's guard and session keys are cleared and the session gets a new id,
 * instead of the whole session being thrown away with the other sign-in.
 */
class PanelLogoutController extends LogoutController
{
    public function __invoke(): LogoutResponse
    {
        $panel = Filament::getCurrentPanel();

        if (! ImsPanelSessions::otherPanelSignedIn($panel)) {
            return parent::__invoke();
        }

        Filament::auth()->logout();
        ImsPanelSessions::forgetPanelKeys($panel);

        session()->regenerate();
        session()->regenerateToken();

        return app(LogoutResponse::class);
    }
}
