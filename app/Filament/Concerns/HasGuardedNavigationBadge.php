<?php

namespace App\Filament\Concerns;

use App\Support\ModuleGuard;
use Illuminate\Support\Str;

/**
 * A sidebar badge is computed on every admin page, so one failing count must
 * not break them all: write the count in navigationBadge() and this trait runs
 * it through ModuleGuard (no badge + a report on error).
 * NavigationBadgeGuardTest makes every badge in app/Filament use it.
 */
trait HasGuardedNavigationBadge
{
    abstract protected static function navigationBadge(): ?string;

    public static function getNavigationBadge(): ?string
    {
        return ModuleGuard::run(
            Str::kebab(class_basename(static::class)).'.badge',
            fn (): ?string => static::navigationBadge(),
        );
    }
}
