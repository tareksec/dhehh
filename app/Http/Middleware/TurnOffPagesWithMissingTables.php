<?php

namespace App\Http\Middleware;

use App\Support\ModuleHealth;
use Closure;
use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An admin page of a resource whose database table is missing (e.g. a migration
 * that did not finish) shows "temporarily unavailable" instead of crashing.
 * Other pages are untouched. See App\Support\ModuleHealth.
 */
class TurnOffPagesWithMissingTables
{
    public function handle(Request $request, Closure $next): Response
    {
        $page = $request->route()?->getControllerClass();

        if (is_string($page) && is_subclass_of($page, ResourcePage::class)) {
            $model = $page::getResource()::getModel();

            if (! app(ModuleHealth::class)->modelAvailable($model)) {
                return response()->view('panel-errors.module', [
                    'title' => __('This section is temporarily unavailable'),
                    'message' => __('Part of its data is not ready yet (an update did not finish). The other sections keep working, so you can carry on with them.'),
                    'home' => '/'.explode('/', trim($request->path(), '/'))[0],
                    'onHome' => false,
                    'retryUrl' => $request->fullUrl(),
                ], 503);
            }
        }

        return $next($request);
    }
}
