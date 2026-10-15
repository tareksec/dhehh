<?php

namespace App\Filament\Resources\Resellers\Actions;

use App\Models\Customer;
use App\Services\CustomerAccountService;
use App\Services\ResellerPasswordResetService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * "Set a new password" and "Email a password reset link" for a reseller
 * (owner request 2026-10-15), shared by the Resellers list and the reseller's
 * View / Edit pages. Both need the permission to edit customers and a
 * reseller who can use the portal (pending or approved).
 */
class ResellerPasswordActions
{
    public static function setPassword(): Action
    {
        return Action::make('setPassword')
            ->label(__('Set a new password'))
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->visible(fn (Customer $record): bool => static::allowed($record))
            ->modalHeading(fn (Customer $record): string => __('Set a new password for :name', ['name' => $record->business_name ?: $record->name]))
            ->modalDescription(__('The reseller is signed out on every device and must use this password next time. The password is not emailed: give it to the reseller yourself. They can change it later from their Profile.'))
            ->modalSubmitActionLabel(__('Set password'))
            ->schema([
                TextInput::make('password')
                    ->label(__('New password'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->rule(Password::default())
                    ->same('passwordConfirmation'),
                TextInput::make('passwordConfirmation')
                    ->label(__('Confirm new password'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->dehydrated(false),
            ])
            ->action(function (Customer $record, array $data): void {
                abort_unless(static::allowed($record), 403);

                app(ResellerPasswordResetService::class)->setPassword($record, (string) $data['password'], Auth::user());

                Notification::make()
                    ->title(__('The password was changed. The reseller is signed out everywhere.'))
                    ->success()
                    ->send();
            });
    }

    public static function emailResetLink(): Action
    {
        return Action::make('emailResetLink')
            ->label(__('Email a password reset link'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (Customer $record): bool => static::allowed($record))
            ->disabled(fn (Customer $record): bool => blank($record->email))
            ->tooltip(fn (Customer $record): ?string => blank($record->email)
                ? __('This reseller has no email address. Add one on the Edit page, or set a new password instead.')
                : null)
            ->requiresConfirmation()
            ->modalHeading(__('Email a password reset link'))
            ->modalDescription(fn (Customer $record): string => __('A link that works once, for :minutes minutes, will be emailed to :email.', [
                'minutes' => CustomerAccountService::RESET_LINK_MINUTES,
                'email' => (string) $record->email,
            ]))
            ->modalSubmitActionLabel(__('Send link'))
            ->action(function (Customer $record): void {
                abort_unless(static::allowed($record), 403);

                $status = app(ResellerPasswordResetService::class)->sendLink($record, Auth::user());

                if ($status === ResellerPasswordResetService::SENT) {
                    Notification::make()
                        ->title(__('A reset link was emailed to :email.', ['email' => (string) $record->email]))
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(match ($status) {
                        ResellerPasswordResetService::NO_EMAIL => __('This reseller has no email address. Add one on the Edit page, or set a new password instead.'),
                        ResellerPasswordResetService::MAIL_NOT_SET_UP => __('Email is not set up for this company yet (Storefront Settings → Customer Notifications & Reminders), so no link can be sent. Set a new password instead.'),
                        ResellerPasswordResetService::NO_PORTAL => __('This company has no reseller portal address yet, so there is no page to send the reseller to.'),
                        ResellerPasswordResetService::NOT_A_RESELLER => __('This reseller cannot use the portal (the application is not pending or approved).'),
                        default => __('The link could not be sent. Please try again, or set a new password instead.'),
                    })
                    ->danger()
                    ->send();
            });
    }

    /** Staff who may edit customers, for a reseller who can use the portal. */
    protected static function allowed(Customer $reseller): bool
    {
        return (Auth::user()?->canPerformModelAbility('update', Customer::class) ?? false)
            && app(ResellerPasswordResetService::class)->canReset($reseller);
    }
}
