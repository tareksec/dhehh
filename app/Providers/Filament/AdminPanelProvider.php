<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\BusinessOverview;
use App\Filament\Widgets\CustomerDueNotifications;
use App\Filament\Widgets\CustomerRiskAlerts;
use App\Filament\Widgets\CustomerRiskOverview;
use App\Filament\Widgets\LowStockProducts;
use App\Filament\Widgets\SalesPurchaseTrend;
use App\Filament\Widgets\TopBusinessPerformers;
use App\Http\Middleware\PreventAdminPageCaching;
use App\Http\Middleware\RestrictPortalHosts;
use App\Http\Middleware\SetCurrentCompany;
use App\Http\Middleware\SyncAppUpdates;
use App\Models\Company;
use App\Services\AppUpdateService;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Services\DynamicColorService;
use App\Services\ProductSetupService;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(isSimple: false)
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('18rem')
            ->collapsedSidebarWidth('4.5rem')
            // Let every admin page use the full width available beside the
            // sidebar. On phones/tablets the content is already narrower than
            // the old 80rem (7xl) cap, so this only widens the desktop layout.
            ->maxContentWidth(Width::Full)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->databaseNotifications(isLazy: false)
            ->databaseNotificationsPolling('15s')
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action
                    ->label('Profile Settings')
                    ->icon(Heroicon::OutlinedUserCircle),
                Action::make('upgradeApp')
                    ->label('Upgrade App')
                    ->icon(Heroicon::OutlinedArrowUpCircle)
                    ->color('warning')
                    ->badge('New')
                    ->badgeColor('warning')
                    ->sort(PHP_INT_MAX - 1)
                    ->actionJs(<<<'JS'
                        window.ZamZamAppUpdater
                            ? window.ZamZamAppUpdater.openUpgradeDialog()
                            : window.dispatchEvent(new CustomEvent('open-modal', { detail: { id: 'app-upgrade-confirmation' } }))
                        JS)
                    ->extraAttributes(fn (): array => [
                        'data-zz-app-upgrade-action' => '',
                        'aria-label' => 'Upgrade App to the latest deployed version',
                        ...(app(AppUpdateService::class)->isAvailable(auth()->user())
                            ? []
                            : [
                                'hidden' => 'hidden',
                                'aria-hidden' => 'true',
                            ]),
                    ]),
            ])
            ->brandName(fn (): string => app(CompanySettingsService::class)->name($this->brandCompany()))
            ->brandLogo(fn (): ?string => app(CompanySettingsService::class)->logoUrl($this->brandCompany()))
            ->darkModeBrandLogo(fn (): ?string => app(CompanySettingsService::class)->darkLogoUrl(company: $this->brandCompany()))
            ->brandLogoHeight('2.25rem')
            ->colors([
                // Base/fallback palette — used for "All Companies" (no single
                // company selected) and overridden per request below via CSS
                // custom properties for whichever company is active.
                'primary' => Color::Amber,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                function (): HtmlString {
                    $context = app(CompanyContext::class);

                    // "All Companies" (or no company resolved) keeps the
                    // static Amber fallback set via ->colors() above —
                    // consistent with the existing All-Companies safeguard
                    // that disables company-scoped write actions.
                    $company = $context->hasCompany() && ! $context->isAllCompanies()
                        ? $context->company()
                        : null;

                    $hex = $company?->dashboard_color ?: DynamicColorService::DEFAULT_COLOR;

                    $declarations = implode('; ', app(DynamicColorService::class)->cssVariables($hex));

                    return new HtmlString(
                        '<style>:root { '.e($declarations).'; }</style>'
                    );
                },
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <style>
                        .fi-page-header-main-ctn > .fi-header {
                            position: sticky;
                            top: 4rem;
                            z-index: 20;
                            margin-inline: -1rem;
                            padding: 1rem;
                            background-color: rgb(249 250 251 / 0.94);
                            backdrop-filter: blur(10px);
                            border-bottom: 1px solid rgb(229 231 235 / 0.75);
                        }

                        .dark .fi-page-header-main-ctn > .fi-header {
                            background-color: rgb(17 24 39 / 0.94);
                            border-bottom-color: rgb(255 255 255 / 0.1);
                        }

                        .zz-storefront-settings-custom-header,
                        .zz-meta-custom-header {
                            position: sticky;
                            top: 4rem;
                            z-index: 20;
                            margin-inline: -1rem;
                            padding: 1rem;
                            background-color: rgb(249 250 251 / 0.94);
                            backdrop-filter: blur(10px);
                            border-bottom: 1px solid rgb(229 231 235 / 0.75);
                        }

                        .dark .zz-storefront-settings-custom-header,
                        .dark .zz-meta-custom-header {
                            background-color: rgb(17 24 39 / 0.94);
                            border-bottom-color: rgb(255 255 255 / 0.1);
                        }

                        .fi-header-actions-ctn {
                            justify-content: flex-end;
                        }

                        /* Dropdown panels (sub-navigation tabs collapsed to a
                           select on mobile, column manager, etc.) must float
                           above the sticky page header above, not behind it. */
                        .fi-dropdown-panel {
                            z-index: 30;
                        }

                        .fi-sidebar-item-icon {
                            transform-origin: center;
                            transition:
                                color 180ms ease,
                                opacity 180ms ease,
                                transform 220ms cubic-bezier(0.34, 1.56, 0.64, 1),
                                filter 220ms ease;
                        }

                        .fi-sidebar-item-btn:hover .fi-sidebar-item-icon,
                        .fi-sidebar-item-active .fi-sidebar-item-icon {
                            transform: translateY(-1px) scale(1.12) rotate(-4deg);
                            filter: drop-shadow(0 4px 7px rgb(245 158 11 / 0.28));
                        }

                        .fi-sidebar-item-btn:active .fi-sidebar-item-icon {
                            transform: scale(0.96);
                        }

                        .zz-company-switcher {
                            flex-shrink: 0;
                            width: min(14rem, 42vw);
                        }

                        .zz-company-switcher .fi-dropdown {
                            width: 100%;
                        }

                        .zz-company-switcher .fi-input-wrp {
                            width: 100%;
                        }

                        .zz-company-switcher-trigger {
                            cursor: pointer;
                            min-height: 2.25rem;
                            width: 100%;
                            padding-inline-start: .75rem;
                            padding-inline-end: .25rem;
                            font-size: .875rem;
                            font-weight: 400;
                            text-align: start;
                        }

                        .zz-company-switcher-trigger-label {
                            display: block;
                            overflow: hidden;
                            text-overflow: ellipsis;
                            white-space: nowrap;
                        }

                        .zz-mobile-notifications-item {
                            display: none;
                        }

                        [data-zz-app-upgrade-action][hidden] {
                            display: none !important;
                        }

                        @media (max-width: 640px) {
                            .zz-storefront-settings-custom-header,
                            .zz-meta-custom-header {
                                padding: 0.625rem 1rem 1rem;
                            }

                            .zz-company-switcher {
                                width: min(12rem, 46vw);
                            }

                            /* On mobile the header has no room for a separate
                               bell icon — hide it and expose notifications
                               from inside the profile/avatar dropdown menu
                               instead (see USER_MENU_PROFILE_AFTER hook). */
                            .fi-topbar-database-notifications-btn {
                                display: none;
                            }

                            .fi-topbar-end {
                                column-gap: 0.375rem;
                                padding-inline-end: 10px;
                            }

                            .zz-mobile-notifications-item {
                                display: flex;
                            }
                        }

                        @media (prefers-reduced-motion: reduce) {
                            .fi-sidebar-item-icon {
                                transition: none;
                            }

                            .fi-sidebar-item-btn:hover .fi-sidebar-item-icon,
                            .fi-sidebar-item-active .fi-sidebar-item-icon,
                            .fi-sidebar-item-btn:active .fi-sidebar-item-icon {
                                transform: none;
                                filter: none;
                            }
                        }
                    </style>
                HTML),
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <script>
                        // Successful Livewire saves already update their component.
                        // Re-check deployment readiness without automatically replacing
                        // the loaded document; only the explicit Upgrade POST may cross
                        // into a newer frontend build.
                        window.addEventListener('notificationsSent', async () => {
                            // Pages that manage their own live state (e.g. the
                            // Inbox chat) opt out — a full reload would wipe the
                            // open conversation mid-chat.
                            if (document.querySelector('[data-zz-no-reload]')) {
                                return;
                            }

                            if (window.ZamZamAppUpdater) {
                                await window.ZamZamAppUpdater.reloadIfCurrent();
                            }
                        });

                        // Chrome-style pull-to-refresh for the mobile app.
                        // The Capacitor webview has no reload UI, so dragging
                        // down from the very top of the page reloads it. Real
                        // mobile browsers already ship native pull-to-refresh,
                        // so this only activates inside the app's webview.
                        (() => {
                            const inAppWebView = !! window.Capacitor
                                || /; wv\)/.test(navigator.userAgent);

                            if (! inAppWebView || window.__zzPullToRefresh) {
                                return;
                            }
                            window.__zzPullToRefresh = true;

                            const THRESHOLD = 80;
                            const indicator = document.createElement('div');
                            indicator.style.cssText = 'position:fixed;top:-3.5rem;left:50%;z-index:9999;width:2.5rem;height:2.5rem;margin-left:-1.25rem;border-radius:50%;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;transition:none;pointer-events:none;';
                            indicator.innerHTML = '<svg viewBox="0 0 24 24" style="width:1.35rem;height:1.35rem;fill:none;stroke:rgb(217 119 6);stroke-width:2.5;stroke-linecap:round;"><path d="M20 11A8 8 0 1 0 18.7 16"/><path d="M20 5v6h-6"/></svg>';
                            document.body.appendChild(indicator);

                            let startY = null;
                            let pulling = false;
                            let distance = 0;

                            const atTop = () => (document.scrollingElement?.scrollTop ?? 0) <= 0;

                            const innerScrolled = (el) => {
                                for (; el && el !== document.body; el = el.parentElement) {
                                    if (el.scrollTop > 0) return true;
                                }
                                return false;
                            };

                            document.addEventListener('touchstart', (e) => {
                                startY = (atTop() && ! innerScrolled(e.target)) ? e.touches[0].clientY : null;
                                pulling = false;
                                distance = 0;
                            }, { passive: true });

                            document.addEventListener('touchmove', (e) => {
                                if (startY === null || ! atTop()) return;
                                distance = (e.touches[0].clientY - startY) * 0.45;
                                pulling = distance > 10;
                                if (! pulling) return;
                                const shift = Math.min(distance, THRESHOLD * 1.4);
                                indicator.style.transition = 'none';
                                indicator.style.top = (shift - 56) + 'px';
                                indicator.style.transform = 'rotate(' + shift * 3 + 'deg)';
                            }, { passive: true });

                            document.addEventListener('touchend', () => {
                                if (pulling && distance >= THRESHOLD) {
                                    indicator.style.transition = 'top .15s';
                                    indicator.style.top = '14px';
                                    indicator.firstElementChild.style.animation = 'zz-ptr-spin .7s linear infinite';

                                    if (window.ZamZamAppUpdater) {
                                        void window.ZamZamAppUpdater.reloadIfCurrent().then((didReload) => {
                                            if (didReload) return;

                                            indicator.style.transition = 'top .2s';
                                            indicator.style.top = '-3.5rem';
                                            indicator.firstElementChild.style.animation = '';
                                        });
                                    } else {
                                        // If the updater failed to boot, fail closed:
                                        // never let pull-to-refresh silently cross a
                                        // deployment boundary.
                                        indicator.style.transition = 'top .2s';
                                        indicator.style.top = '-3.5rem';
                                    }
                                } else {
                                    indicator.style.transition = 'top .2s';
                                    indicator.style.top = '-3.5rem';
                                }
                                startY = null;
                                pulling = false;
                            }, { passive: true });

                            const style = document.createElement('style');
                            style.textContent = '@keyframes zz-ptr-spin { to { transform: rotate(360deg); } }';
                            document.head.appendChild(style);
                        })();

                        // Full-screen loading indicator for the mobile app.
                        // The panel is a Livewire SPA (see ->spa() below), so
                        // tapping a nav link/menu item doesn't reload the
                        // WebView -- it fires an AJAX-driven `livewire:navigate`
                        // transition instead. Livewire's own top progress bar
                        // is easy to miss inside the app, leaving a 2-5 second
                        // gap with no feedback that anything happened. Only
                        // shown inside the app's webview -- a real browser
                        // already has its own address-bar loading affordance.
                        (() => {
                            const inAppWebView = !! window.Capacitor
                                || /; wv\)/.test(navigator.userAgent);

                            if (! inAppWebView || window.__zzPageLoader) {
                                return;
                            }
                            window.__zzPageLoader = true;

                            const overlay = document.createElement('div');
                            overlay.style.cssText = 'position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.12);';

                            const bubble = document.createElement('div');
                            bubble.style.cssText = 'width:3.25rem;height:3.25rem;border-radius:50%;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;';
                            bubble.innerHTML = '<svg viewBox="0 0 24 24" style="width:1.75rem;height:1.75rem;fill:none;stroke:rgb(217 119 6);stroke-width:2.5;stroke-linecap:round;animation:zz-page-loader-spin .7s linear infinite;"><path d="M20 11A8 8 0 1 0 18.7 16"/><path d="M20 5v6h-6"/></svg>';
                            overlay.appendChild(bubble);
                            document.body.appendChild(overlay);

                            const style = document.createElement('style');
                            style.textContent = '@keyframes zz-page-loader-spin { to { transform: rotate(360deg); } }';
                            document.head.appendChild(style);

                            let safetyTimer = null;

                            const show = () => {
                                clearTimeout(safetyTimer);
                                overlay.style.pointerEvents = 'auto';
                                overlay.style.display = 'flex';
                                // Safety net: if a navigation silently fails or
                                // is aborted without ever firing
                                // `livewire:navigated`, don't leave the app
                                // stuck behind the overlay forever.
                                safetyTimer = setTimeout(hide, 15000);
                            };

                            const hide = () => {
                                clearTimeout(safetyTimer);
                                overlay.style.display = 'none';
                                overlay.style.pointerEvents = 'none';
                            };

                            document.addEventListener('livewire:navigate', show);
                            document.addEventListener('livewire:navigated', hide);
                        })();
                    </script>
                HTML),
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): HtmlString => new HtmlString(view('filament.partials.category-icon-search')->render()),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): HtmlString => new HtmlString(view('filament.partials.app-updater')->render()),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): HtmlString => new HtmlString(view('filament.partials.sidebar-navigation')->render()),
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): HtmlString => new HtmlString(view('filament.partials.company-switcher')->render()),
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_PROFILE_AFTER,
                fn (): HtmlString => new HtmlString(view('filament.partials.mobile-notifications-menu-item')->render()),
            )
            ->renderHook(
                PanelsRenderHook::CONTENT_BEFORE,
                function (): HtmlString {
                    $setup = app(ProductSetupService::class);

                    if (! $setup->demoMode()) {
                        return new HtmlString('');
                    }

                    $notice = e($setup->demoNotice());

                    return new HtmlString(<<<HTML
                        <div style="margin: 0 1rem 1rem; border: 1px solid rgb(245 158 11 / .35); background: rgb(254 243 199 / .95); color: #92400e; border-radius: 8px; padding: .75rem 1rem; font-size: .875rem; font-weight: 800;">
                            {$notice}
                        </div>
                    HTML);
                },
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                BusinessOverview::class,
                CustomerRiskOverview::class,
                SalesPurchaseTrend::class,
                TopBusinessPerformers::class,
                LowStockProducts::class,
                CustomerDueNotifications::class,
                CustomerRiskAlerts::class,
            ])
            ->middleware([
                // The admin panel doesn't route through the "web" middleware
                // group, so this has to be listed here too -- otherwise a
                // reseller-portal host could still reach /admin. See
                // 13_RESELLER_PORTAL_PLAN.md §6 and RestrictPortalHosts.
                RestrictPortalHosts::class,
                PreventAdminPageCaching::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                SetCurrentCompany::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SyncAppUpdates::class,
            ]);
    }

    protected function brandCompany(): ?Company
    {
        $context = app(CompanyContext::class);

        if ($context->hasCompany() && ! $context->isAllCompanies()) {
            return $context->company();
        }

        return auth()->guest() ? Company::defaultCompany() : null;
    }
}
