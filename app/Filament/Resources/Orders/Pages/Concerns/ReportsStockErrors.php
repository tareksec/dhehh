<?php

namespace App\Filament\Resources\Orders\Pages\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A stock movement rejected while an order saves (StockMovementService::
 * validate()) raises its error under a key like "quantity", which matches no
 * field on the order form, so nothing appeared on screen and the save just
 * seemed to do nothing. Show it as a notification instead.
 */
trait ReportsStockErrors
{
    protected function runReportingStockErrors(Closure $save): void
    {
        try {
            $save();
        } catch (ValidationException $exception) {
            $formErrors = collect($exception->errors())->filter(fn ($messages, string $key): bool => str_starts_with($key, 'data.'));

            if ($formErrors->count() === count($exception->errors())) {
                throw $exception;
            }

            Notification::make()
                ->title(__('The order could not be saved'))
                ->body(collect($exception->errors())->flatten()->unique()->implode(' '))
                ->danger()
                ->persistent()
                ->send();

            if ($formErrors->isNotEmpty()) {
                $this->setErrorBag($formErrors->all());
            }
        }
    }
}
