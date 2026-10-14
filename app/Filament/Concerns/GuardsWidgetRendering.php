<?php

namespace App\Filament\Concerns;

use App\Support\ModuleGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Drawer\Utils;

/**
 * A widget that fails shows a small "could not load" card instead of breaking
 * the dashboard or page around it; the error is reported through ModuleGuard.
 * The widget's own view is rendered inside the wrapper view, while Livewire
 * is rendering the widget (so `$this` is still the widget).
 * ModuleGuardTest makes every widget in app/ use this trait.
 */
trait GuardsWidgetRendering
{
    public function render(): View
    {
        $section = 'widget:'.Str::kebab(class_basename(static::class));

        $view = ModuleGuard::run(
            $section,
            fn (): View => Utils::generateBladeView(parent::render(), Utils::getPublicPropertiesDefinedOnSubclass($this)),
        );

        // A closure, because Laravel renders any View passed as view data
        // straight away, which would be outside the guard.
        return view('filament.widgets.guarded', [
            'guardedView' => $view === null ? null : fn (): View => $view,
            'guardedSection' => $section,
        ]);
    }
}
