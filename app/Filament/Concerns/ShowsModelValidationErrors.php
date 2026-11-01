<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A model hook (stock would go negative, short > received...) throws a
 * ValidationException whose keys are not form fields, so Livewire would show
 * nothing. Show it as a notification instead; form-field errors (keys that
 * start with "data.") are left to Filament.
 */
trait ShowsModelValidationErrors
{
    protected function reportModelValidation(ValidationException $exception): bool
    {
        foreach (array_keys($exception->errors()) as $key) {
            if (str_starts_with((string) $key, 'data.')) {
                return false;
            }
        }

        Notification::make()
            ->danger()
            ->title(__('Could not save'))
            ->body((string) collect($exception->errors())->flatten()->first())
            ->persistent()
            ->send();

        return true;
    }
}
