<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A page of the admin panel or a portal that crashes shows "this part has a
 * problem, the rest keeps working" with links back, instead of the bare
 * "500 | Server Error" (owner request 2026-10-08). The error is reported
 * through ModuleGuard. Only real server errors (500) are covered: 403/404/419
 * and login / validation redirects keep Laravel's and Filament's handling.
 * Livewire updates sent from these pages get the same page, which Livewire
 * shows in its error dialog. Follows ModuleGuard::isolates() (off in local
 * development and tests, so the stack trace stays visible there).
 */
class PanelErrorPages
{
    /** First path segment → panel home. */
    public const PANELS = [
        'admin' => '/admin',
        'investor' => '/investor',
        'portal' => '/portal',
        // Without it a Trade Portal crash got the storefront's bare 500 page and reached no Super Admin (QA 2026-10-23).
        'trade' => '/trade',
    ];

    public static function appliesTo(Request $request, Throwable $e): bool
    {
        if (! ModuleGuard::isolates()) {
            return false;
        }

        if ($e instanceof AuthenticationException || $e instanceof ValidationException || $e instanceof HttpResponseException) {
            return false;
        }

        // Laravel has already turned missing records, denied access and the
        // like into 404/403/419 (Handler::prepareException()) by this point.
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() !== 500) {
            return false;
        }

        return self::panelHome($request) !== null;
    }

    /** The home of the panel the request belongs to, or null when it is not a panel page. */
    public static function panelHome(Request $request): ?string
    {
        if ($request->header('X-Livewire')) {
            $path = trim((string) parse_url((string) $request->headers->get('referer'), PHP_URL_PATH), '/');
        } elseif ($request->expectsJson()) {
            return null;
        } else {
            $path = trim($request->path(), '/');
        }

        return self::PANELS[explode('/', $path)[0]] ?? null;
    }

    /** The page the person was on: for a Livewire update, the page that sent it. */
    public static function pageUrl(Request $request): string
    {
        if ($request->header('X-Livewire')) {
            return (string) ($request->headers->get('referer') ?: url('/'));
        }

        return $request->fullUrl();
    }

    public static function section(Request $request): string
    {
        if ($request->header('X-Livewire')) {
            return 'page:'.trim((string) parse_url((string) $request->headers->get('referer'), PHP_URL_PATH), '/');
        }

        return 'page:'.($request->route()?->getName() ?? $request->path());
    }
}
