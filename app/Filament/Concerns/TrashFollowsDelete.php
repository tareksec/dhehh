<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * For a Resource that has a Trash page (see App\Filament\Resources\Pages\TrashedRecords):
 * whoever may delete a record may also restore it or delete it permanently from
 * the Trash. Without this, Filament falls back to the model policy, and a model
 * with no policy would let anyone who can open the list use the Trash.
 *
 * The resource keeps defining who may delete (`canDelete()`, `canDeleteAny()`);
 * `use TrashFollowsDelete;` makes the four Trash checks follow it.
 */
trait TrashFollowsDelete
{
    public static function canRestore(Model $record): bool
    {
        return static::canDelete($record);
    }

    public static function canRestoreAny(): bool
    {
        return static::canDeleteAny();
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::canDelete($record);
    }

    public static function canForceDeleteAny(): bool
    {
        return static::canDeleteAny();
    }
}
