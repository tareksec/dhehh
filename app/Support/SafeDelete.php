<?php

namespace App\Support;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Deleting a record that books stock takes that stock back out, which is
 * refused when it was already sold. Show the reason instead of failing silently.
 */
final class SafeDelete
{
    public static function delete(Model $record, string $title): bool
    {
        try {
            return (bool) $record->delete();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title($title)
                ->body((string) collect($exception->errors())->flatten()->first())
                ->persistent()
                ->send();

            return false;
        }
    }
}
