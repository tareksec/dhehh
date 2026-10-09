<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Turns off the admin pages of a model whose database table is missing, instead
 * of letting them fail (2026-10-08: a half-finished migration left
 * investor_requests missing and took the whole panel down):
 *  - TurnOffPagesWithMissingTables (admin panel middleware): a resource page
 *    whose model's table is missing shows "temporarily unavailable" (503).
 *  - Gate::before (AppServiceProvider): every ability on such a model is
 *    denied for the models in User::MODEL_MODULES, which hides their relation
 *    tabs, actions and permission-checked menu items on other pages.
 * The rest of the module keeps working. Super admins get one report per
 * missing table per hour (Settings → Deploy Error Logs).
 *
 * Off where ModuleGuard does not isolate (local development, tests).
 * The table list is cached and dropped whenever migrations finish
 * (MigrationsEnded listener), so a deploy that creates the table turns the
 * pages back on at once. If the list cannot be read (database down) nothing
 * is turned off: that failure shows up on its own.
 */
class ModuleHealth
{
    public const CACHE_KEY = 'module-health:tables';

    private const CACHE_SECONDS = 600;

    /** @var array<string, true>|null */
    private ?array $tables = null;

    private bool $unreadable = false;

    /** @var array<string, true> */
    private array $reported = [];

    public function modelAvailable(string $modelClass): bool
    {
        // Like ModuleGuard: local development and tests see the real error.
        if (! ModuleGuard::isolates() || ! is_subclass_of($modelClass, Model::class)) {
            return true;
        }

        $tables = $this->tables();

        if ($tables === null) {
            return true;
        }

        $table = (new $modelClass)->getTable();

        if (isset($tables[$table])) {
            return true;
        }

        $this->reportMissing($modelClass, $table);

        return false;
    }

    /**
     * @return array<string, string> missing table => model class
     */
    public function missingTables(): array
    {
        $tables = $this->tables();

        if ($tables === null) {
            return [];
        }

        $missing = [];

        foreach (array_keys(User::MODEL_MODULES) as $modelClass) {
            $table = (new $modelClass)->getTable();

            if (! isset($tables[$table])) {
                $missing[$table] = $modelClass;
            }
        }

        return $missing;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        app()->forgetInstance(self::class);
    }

    /**
     * @return array<string, true>|null
     */
    private function tables(): ?array
    {
        if ($this->tables !== null || $this->unreadable) {
            return $this->tables;
        }

        try {
            $listing = Cache::remember(
                self::CACHE_KEY,
                self::CACHE_SECONDS,
                fn (): array => Schema::getTableListing(schemaQualified: false),
            );
        } catch (Throwable) {
            $this->unreadable = true;

            return null;
        }

        return $this->tables = array_fill_keys($listing, true);
    }

    private function reportMissing(string $modelClass, string $table): void
    {
        if (isset($this->reported[$table])) {
            return;
        }

        $this->reported[$table] = true;

        ModuleGuard::report(
            'health:'.(User::MODEL_MODULES[$modelClass] ?? 'app').'.'.$table,
            new RuntimeException("Table `{$table}` is missing, so the ".class_basename($modelClass).' pages are turned off until it exists. Check `php artisan migrate:status` for a pending or failed migration.'),
        );
    }
}
