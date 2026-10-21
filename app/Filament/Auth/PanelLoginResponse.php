<?php

namespace App\Filament\Auth;

use App\Support\ImsPanelSessions;
use Filament\Auth\Http\Responses\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Filament's own "back to the page you wanted, else the panel's home" after
 * signing in, except that an IMS panel never lands on the other IMS panel's
 * page (ImsPanelSessions). Every other panel behaves exactly as before.
 */
class PanelLoginResponse extends LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        ImsPanelSessions::forgetForeignIntendedUrl(Filament::getCurrentPanel());

        return parent::toResponse($request);
    }
}
