<?php

namespace App\Filament\Trade;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The Trade Portal's services refuse with the ERP's own rules (not enough
 * stock, an account would go below zero, more than the due…). Those messages
 * are keyed to the service's fields, which a portal form or button does not
 * show, so the owner would see nothing. This runs the work and turns such a
 * refusal into a red notice with every reason; the caller then stops (the
 * form or the button's window stays open with what was typed).
 */
final class TradeErrors
{
    /** Runs $work; on a refusal shows it and returns false. */
    public static function attempt(Closure $work): bool
    {
        try {
            $work();

            return true;
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('Not saved'))
                ->body(collect($exception->errors())->flatten()->unique()->implode("\n"))
                ->danger()
                ->send();

            return false;
        }
    }
}
