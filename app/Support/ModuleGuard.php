<?php

namespace App\Support;

use App\Services\DeploymentErrorReporter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\Factory;
use Throwable;

/**
 * Keeps one module's failure inside that module.
 *
 * Parts of the admin panel that are drawn on every page (navigation badges,
 * render hooks, dashboard widgets) run through ModuleGuard::run(): if one of
 * them throws, it shows its fallback (nothing, or a small "could not load"
 * note) instead of turning every page into a 500. That is what happened on
 * 2026-10-08, when a missing table behind the Investor Requests badge took
 * the whole panel down.
 *
 * A caught error is never silent: it is logged, and reported to super admins
 * through DeploymentErrorReporter (Settings → Deploy Error Logs + the bell),
 * at most once per section and error per hour so a broken badge cannot flood
 * them on every page view.
 *
 * Local development and the test suite rethrow by default, so a bug shows up
 * where it is written; production isolates. Override with
 * ISOLATE_MODULE_ERRORS (config app.isolate_module_errors).
 */
final class ModuleGuard
{
    public const SOURCE_PREFIX = 'module:';

    private const REPORT_EVERY_SECONDS = 3600;

    /**
     * @template T
     *
     * @param  string  $section  stable name of the part, e.g. "investor-requests.badge"
     * @param  callable(): T  $callback
     * @param  mixed  $fallback  value (or callable returning it) used when the callback throws
     * @return T|mixed
     */
    public static function run(string $section, callable $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            if (! self::isolates()) {
                throw $exception;
            }

            self::report($section, $exception);

            return is_callable($fallback) ? $fallback($exception) : $fallback;
        }
    }

    /**
     * Render a view inside the guard, for parts drawn inside another view
     * (a widget inside a page). A failed render makes Laravel flush the shared
     * view state (component, section and stack bookkeeping) that the page
     * around it is still using, so that state is put back before carrying on.
     */
    public static function renderView(string $section, View $view): ?string
    {
        if (! self::isolates()) {
            return $view->render();
        }

        $factory = app('view');
        $snapshot = $factory instanceof Factory ? self::viewState($factory) : null;

        try {
            return $view->render();
        } catch (Throwable $exception) {
            if ($snapshot !== null) {
                self::restoreViewState($factory, $snapshot);
            }

            self::report($section, $exception);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function viewState(Factory $factory): array
    {
        return (fn (): array => array_filter(
            get_object_vars($this),
            fn (mixed $value): bool => is_array($value) || is_int($value) || is_bool($value),
        ))->call($factory);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function restoreViewState(Factory $factory, array $state): void
    {
        (function () use ($state): void {
            foreach ($state as $property => $value) {
                $this->{$property} = $value;
            }
        })->call($factory);
    }

    public static function isolates(): bool
    {
        $configured = config('app.isolate_module_errors');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return ! app()->environment('local', 'testing');
    }

    public static function report(string $section, Throwable $exception): void
    {
        // Logging must never itself break the page it is protecting.
        try {
            Log::error("Module error [{$section}]: {$exception->getMessage()}", [
                'exception' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            $key = 'module-guard:'.sha1($section.'|'.$exception::class.'|'.$exception->getMessage());

            if (! Cache::add($key, true, self::REPORT_EVERY_SECONDS)) {
                return;
            }

            app(DeploymentErrorReporter::class)->report(self::SOURCE_PREFIX.$section, $exception, [
                'url' => request()?->fullUrl(),
            ]);
        } catch (Throwable) {
            // The database or cache may be the broken thing; the log line above is enough.
        }
    }
}
