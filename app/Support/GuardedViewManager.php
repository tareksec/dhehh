<?php

namespace App\Support;

use Filament\Support\View\ViewManager;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

/**
 * Filament's render hooks (sidebar, company switcher, banners, toolbars …)
 * run on every page of every panel. This copy of ViewManager::renderHook()
 * renders each hook inside ModuleGuard, so one failing hook outputs nothing
 * (and is reported) instead of turning every page into a 500.
 * Bound in AppServiceProvider::register(); keep in step with the parent
 * method on a Filament upgrade (ModuleGuardTest covers the behaviour).
 */
class GuardedViewManager extends ViewManager
{
    /**
     * @param  string | array<string> | null  $scopes
     * @param  array<string, mixed>  $data
     */
    public function renderHook(string $name, string|array|null $scopes = null, array $data = []): Htmlable
    {
        $renderedHooks = [];

        $scopes = Arr::wrap($scopes);

        $renderHook = function (callable $hook) use (&$renderedHooks, $name, $scopes, $data): ?string {
            $hookId = spl_object_id($hook);

            if (in_array($hookId, $renderedHooks)) {
                return null;
            }

            $renderedHooks[] = $hookId;

            // Cast inside the guard: a hook may return a View that only fails when it renders.
            return ModuleGuard::run(
                "render-hook:{$name}",
                fn (): string => (string) app()->call($hook, ['data' => $data, 'scopes' => $scopes]),
                '',
            );
        };

        $hooks = array_map(
            $renderHook,
            $this->renderHooks[$name][''] ?? [],
        );

        foreach ($scopes as $scopeName) {
            $hooks = [
                ...$hooks,
                ...array_map(
                    $renderHook,
                    $this->renderHooks[$name][$scopeName] ?? [],
                ),
            ];
        }

        return new HtmlString(implode('', $hooks));
    }
}
