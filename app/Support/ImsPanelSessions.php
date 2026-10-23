<?php

namespace App\Support;

use App\Services\ImsOfficeCompanies;
use App\Services\InvestorBusinessSwitchService;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Auth;

/**
 * The two IMS panels on one IMS domain share the browser's one session
 * cookie (owner report 2026-10-21): the investor portal signs in with guard
 * "investor", the office with guard "web". Both may be signed in at once, so
 * neither may act on the other's part of the session.
 *
 * - "Where to go after signing in" (Laravel's url.intended) is one key for
 *   the whole session: a portal page left from before must never be where
 *   the office sign-in lands, and the other way round.
 * - Signing out of one panel keeps the other signed in: only this panel's
 *   guard and session keys are cleared.
 *
 * Every other panel (the ERP admin, the reseller portal) is left as Filament
 * runs it.
 */
final class ImsPanelSessions
{
    /**
     * Each IMS panel's own session keys, cleared when it signs out.
     *
     * @var array<string, list<string>>
     */
    private const PANEL_KEYS = [
        'office' => [ImsOfficeCompanies::SESSION_KEY, 'password_hash_web'],
        'investor' => [
            InvestorBusinessSwitchService::SESSION_KEY,
            InvestorBusinessSwitchService::LINK_SESSION_KEY,
            InvestorPortalStaffPreview::SESSION_KEY,
            'password_hash_investor',
        ],
    ];

    public static function isImsPanel(?Panel $panel): bool
    {
        return $panel !== null && array_key_exists($panel->getId(), self::PANEL_KEYS);
    }

    /** Drops an "after sign-in" address that belongs to the other IMS panel. */
    public static function forgetForeignIntendedUrl(?Panel $panel): void
    {
        if (! self::isImsPanel($panel)) {
            return;
        }

        self::forgetIntendedUrlOutside($panel);
    }

    /**
     * Drops an "after sign-in" address that is not under $panel's own path.
     * Also used by the Trade Portal, which shares the main domain's session
     * with /admin: a Trade Owner must never land on an /admin page.
     */
    public static function forgetIntendedUrlOutside(Panel $panel): void
    {
        if (! app()->bound('session.store')) {
            return;
        }

        $session = app('session.store');
        $intended = $session->get('url.intended');

        if (! is_string($intended)) {
            return;
        }

        $path = trim((string) parse_url($intended, PHP_URL_PATH), '/');
        $panelPath = trim($panel->getPath(), '/');

        if ($path !== $panelPath && ! str_starts_with($path, $panelPath.'/')) {
            $session->forget('url.intended');
        }
    }

    /** True while the other IMS panel is still signed in in this session. */
    public static function otherPanelSignedIn(?Panel $panel): bool
    {
        if (! self::isImsPanel($panel)) {
            return false;
        }

        $guard = $panel->getAuthGuard();

        foreach (array_keys(self::PANEL_KEYS) as $id) {
            $otherGuard = Filament::getPanel($id)->getAuthGuard();

            if ($otherGuard !== $guard && Auth::guard($otherGuard)->check()) {
                return true;
            }
        }

        return false;
    }

    /** This panel's own session keys (its business choice, staff preview, password check). */
    public static function forgetPanelKeys(Panel $panel): void
    {
        app('session.store')->forget(self::PANEL_KEYS[$panel->getId()] ?? []);
    }
}
