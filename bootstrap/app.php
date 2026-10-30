<?php

use App\Http\Middleware\InvalidateStaleCustomerSession;
use App\Http\Middleware\PreventDemoModeWrites;
use App\Http\Middleware\RestrictPortalHosts;
use App\Http\Middleware\SetResellerPortalLocale;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentCompany;
use App\Jobs\SocialQueueHeartbeatJob;
use App\Models\BackupRun;
use App\Services\GoogleDriveBackupService;
use App\Services\OffsiteBackupService;
use App\Services\R2BackupService;
use App\Support\ModuleGuard;
use App\Support\PanelErrorPages;
use App\Support\StorefrontErrorPages;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Backups. The overlap locks expire (an hour for a dump, which stops at
        // 15 minutes, see backup.database.timeout, plus its upload; 2 hours for
        // the archive). The default lock lasts 24 hours, so one run killed by a
        // container restart would block every backup for a day. Every run is
        // recorded in backup_runs. --auto-upload sends the copy, encrypted, to
        // Cloudflare R2 and/or Google Drive for each place switched on with "Auto
        // upload" (R2 Backups page, Backups page) when the kind is in backup.offsite;
        // the upload runs in the background with the dump so a slow upload never
        // holds up the other scheduled jobs.
        if (config('backup.hourly.enabled', true)) {
            // At five past, so it never starts together with the daily job.
            $schedule->command('backup:database --kind=hourly --auto-upload')
                ->hourlyAt(5)
                ->withoutOverlapping(60)
                ->runInBackground()
                ->onOneServer();
        }

        if (config('backup.daily.full_app', true)) {
            // Files + a database backup of the same kind. In the background
            // because zipping the uploads can take a while.
            $schedule->command('backup:app --kind=daily --auto-upload')
                ->dailyAt((string) config('backup.schedule_time', '02:00'))
                ->withoutOverlapping(120)
                ->runInBackground()
                ->onOneServer();
        } else {
            $schedule->command('backup:database --kind=daily --auto-upload')
                ->dailyAt((string) config('backup.schedule_time', '02:00'))
                ->withoutOverlapping(60)
                ->runInBackground()
                ->onOneServer();
        }

        // Restore test: every Sunday, the newest dump is loaded into a scratch database and
        // dropped again; for each off-site place with "Auto upload" on, later the newest copy
        // there is downloaded, decrypted and loaded the same way (proves the key and the copy).
        // Only when BACKUP_DRILL_DB_USERNAME is set (config backup.drill).
        if (config('backup.drill.enabled')) {
            $drillTime = (string) config('backup.drill.schedule_time', '03:30');
            $schedule->command('backup:drill')
                ->weeklyOn(0, $drillTime)
                ->withoutOverlapping(120)
                ->runInBackground()
                ->onOneServer();

            foreach ([R2BackupService::KEY => 20, GoogleDriveBackupService::KEY => 40] as $place => $minutes) {
                $schedule->command("backup:drill --{$place}")
                    ->weeklyOn(0, Carbon::createFromFormat('H:i', $drillTime)->addMinutes($minutes)->format('H:i'))
                    ->when(fn (): bool => isset(app(OffsiteBackupService::class)->autoDestinations()[$place])
                        && (app(OffsiteBackupService::class)->shouldAutoUpload(BackupRun::SCOPE_DATABASE, BackupRun::KIND_HOURLY)
                            || app(OffsiteBackupService::class)->shouldAutoUpload(BackupRun::SCOPE_DATABASE, BackupRun::KIND_DAILY)))
                    ->withoutOverlapping(120)
                    ->runInBackground()
                    ->onOneServer();
            }
        }

        // A backup that is overdue tells the Super Admins (once, then a reminder every 12 hours).
        // It runs on the scheduler, so it cannot notice a stopped scheduler: /health/backups can.
        $schedule->command('backup:check')
            ->everyThirtyMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();
        $schedule->command('storefront:send-abandoned-cart-reminders')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('crm:send-follow-up-reminders')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('crm:process-sales-follow-ups')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        $schedule->command('crm:revert-conversations')->everyMinute()->withoutOverlapping()->onOneServer();
        // Social Content Planner: queue due posts every minute; the heartbeat shows the dedicated `social` worker is alive.
        $schedule->command('social:publish-due')->everyMinute()->withoutOverlapping()->onOneServer();
        $schedule->job(new SocialQueueHeartbeatJob)->everyFiveMinutes()->onOneServer();
        $schedule->command('storefront:retry-meta-events')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('couriers:sync-statuses')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->onOneServer();
        $schedule->command('quotations:mark-expired')
            ->dailyAt('00:30')
            ->withoutOverlapping()
            ->onOneServer();
        // Hourly so each company's own midnight is caught; idempotent.
        $schedule->command('investments:advance-groups')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer();
        // IMS v2 (IMS-V2/TRD.md §6): each loops the companies with IMS on, can be
        // switched off in IMS settings, and never sends the same thing twice.
        $schedule->command('ims:profit-proposals')->dailyAt('07:00')->withoutOverlapping()->onOneServer();
        $schedule->command('ims:maturity-reminders')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
        $schedule->command('ims:owner-digest')->dailyAt('08:05')->withoutOverlapping()->onOneServer();
        $schedule->command('ims:monthly-statements')->monthlyOn(1, '09:00')->withoutOverlapping()->onOneServer();
        $schedule->command('ims:shipment-delays')->hourly()->withoutOverlapping()->onOneServer();
        $schedule->command('release:notify-deploy')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware) {
        // Coolify terminates HTTPS at its Traefik proxy. Trust the forwarded
        // scheme/host so Filament lazy-loaded component assets never fall back
        // to insecure http:// URLs on an https:// admin page.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: SymfonyRequest::HEADER_X_FORWARDED_TRAEFIK,
        );
        // Every plain `auth` route (order print, report exports, the legacy
        // /admin/* redirects) is an admin page. Laravel's default sends a guest
        // to route('login'), a name this app never defines -- Filament names it
        // filament.admin.auth.login -- so a logged-out visit to a bookmarked
        // /admin/users threw "Route [login] not defined" instead of showing sign-in.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
        $middleware->validateCsrfTokens(except: ['webhooks/couriers/*', 'webhooks/zinipay/*', 'webhooks/woocommerce/*', 'webhooks/ses/*', 'webhooks/meta', 'webhooks/whatsapp-web/*', 'webhooks/mobile-crash-reports']);
        // Must run before anything else in the group: on a reseller-portal
        // host it redirects every non-portal path to the portal, and on any
        // other host it 404s the portal path -- see 13_RESELLER_PORTAL_PLAN.md
        // §6. Also appended to the admin panel's own middleware stack (it
        // doesn't route through the "web" group) in AdminPanelProvider.
        $middleware->appendToGroup('web', RestrictPortalHosts::class);
        // The language switch of the reseller portal's public pages; needs RestrictPortalHosts to have run.
        $middleware->appendToGroup('web', SetResellerPortalLocale::class);
        $middleware->appendToGroup('web', PreventDemoModeWrites::class);
        $middleware->appendToGroup('web', SetCurrentCompany::class);
        $middleware->appendToGroup('web', SecurityHeaders::class);
        $middleware->appendToGroup('web', InvalidateStaleCustomerSession::class);
        // Company context must be bound before route model binding runs,
        // otherwise CompanyScope cannot constrain implicit bindings and a
        // record from another company could resolve on admin routes.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetCurrentCompany::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Defense-in-depth for a misconfigured production APP_DEBUG=true (see
        // 09_DASHBOARD_WOOCOMMERCE_SECURITY_PLAN.md step 3.2): a storefront
        // visitor must never see a raw stack trace / file paths, even if the
        // server's real .env is wrong. Admin panel, Livewire, and API/webhook
        // requests are left completely alone — Filament and Laravel's own
        // JSON error handling for those must not be touched.
        $exceptions->render(function (Throwable $e, Request $request) {
            // A crashed admin / portal page: "this part has a problem, the rest
            // keeps working" with links back, instead of the bare 500 page.
            if (PanelErrorPages::appliesTo($request, $e)) {
                ModuleGuard::report(PanelErrorPages::section($request), $e);

                $home = PanelErrorPages::panelHome($request);
                $pageUrl = PanelErrorPages::pageUrl($request);

                return response()->view('panel-errors.module', [
                    'home' => $home,
                    'onHome' => rtrim((string) parse_url($pageUrl, PHP_URL_PATH), '/') === $home,
                    'retryUrl' => $pageUrl,
                ], 500);
            }

            if (app()->environment('local', 'testing')) {
                return null;
            }

            // These are not errors: Laravel turns them into a redirect to the
            // login page, a redirect back with validation errors, or the
            // response the code threw. Showing the "500" page for them broke
            // every guest visit to a login-protected page and every failed
            // form validation outside the admin panel.
            if ($e instanceof AuthenticationException || $e instanceof ValidationException || $e instanceof HttpResponseException) {
                return null;
            }

            if (! StorefrontErrorPages::appliesTo($request)) {
                return null;
            }

            $status = StorefrontErrorPages::statusFor($e);

            // Deliberately NOT resources/views/errors/{status}.blade.php:
            // Laravel's own exception handler auto-discovers views at that
            // exact conventional path (the "errors::" view namespace) for
            // ANY HTML-rendering exception app-wide, completely bypassing
            // the appliesTo() exclusion above — a webhook/admin/API request
            // that doesn't send `Accept: application/json` would still get
            // this branded page even though appliesTo() said no. Keeping
            // these views under storefront.errors.* instead means they're
            // only ever reachable through this explicit render() call.
            if (! view()->exists("storefront.errors.{$status}")) {
                return null;
            }

            return response()->view("storefront.errors.{$status}", [], $status);
        });
    })->create();
