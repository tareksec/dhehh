<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\CourierBooking;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Runs a "Book courier" popup's booking and tells staff what happened.
 * CourierService reports a refused booking (customer risk review, courier
 * API error, wrong provider, ...) as a validation error under a key like
 * "courier" or "risk", which matches no field in the popup — so a failed
 * booking used to look like a click that did nothing.
 */
class CourierBookingFeedback
{
    /**
     * @param  Closure(): CourierBooking  $book
     */
    public static function book(Action $action, Closure $book): void
    {
        try {
            $booking = $book();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title(__('Courier booking failed'))
                ->body(collect($exception->errors())->flatten()->unique()->implode(' '))
                ->danger()
                ->persistent()
                ->send();

            // Keep the popup open so the courier or details can be changed.
            $action->halt();
        }

        Notification::make()
            ->title(__('Courier booked'))
            ->body(filled($booking->tracking_id) ? __('Tracking ID: :tracking', ['tracking' => $booking->tracking_id]) : null)
            ->success()
            ->send();
    }

    /** One failed order's line for a bulk booking summary. */
    public static function failureLine(ValidationException $exception, string $orderNumber): string
    {
        return $orderNumber.': '.collect($exception->errors())->flatten()->unique()->implode(' ');
    }
}
