<?php

namespace App\Filament\Actions;

use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Services\AuditLogService;
use App\Services\InvestorPortalAccountService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * "Portal access" on an investor's or channel partner's page
 * (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §7.2): invite by SMS (or send a
 * new link), disable, and enable again. Needs investments.portal_manage.
 */
class InvestorPortalAccessAction
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('portalInvite')
                ->label(fn (Investor|ChannelPartner $record): string => (($account = self::account($record)) !== null && ($account->invited_at !== null || $account->status === InvestorPortalAccount::STATUS_ACTIVE))
                    ? __('Send a new portal invite').' ('.self::statusText($record).')'
                    : __('Invite to the investor portal'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (Investor|ChannelPartner $record): bool => self::account($record)?->status !== InvestorPortalAccount::STATUS_DISABLED)
                ->requiresConfirmation()
                ->modalDescription(fn (Investor|ChannelPartner $record): string => __('An SMS with a link to set a password goes to :phone. The link works for :days days. A password already set stays valid until a new one is chosen.', [
                    'phone' => (string) $record->phone,
                    'days' => InvestorPortalAccountService::INVITE_VALID_DAYS,
                ]))
                ->action(function (Investor|ChannelPartner $record): void {
                    abort_unless(auth()->user()?->hasPermission('investments.portal_manage'), 403);

                    try {
                        $link = app(InvestorPortalAccountService::class)->invite($record, auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title(__('Could not send the invite'))->body(collect($exception->errors())->flatten()->first())->send();

                        return;
                    }

                    $smsReady = app(InvestorPortalAccountService::class)->smsAvailable(Company::query()->find($record->company_id));

                    Notification::make()
                        ->success()
                        ->title($smsReady ? __('Invite sent by SMS') : __('Invite created, but SMS is not set up'))
                        ->body(__('Invite link (share it only with this person): :link', ['link' => $link]))
                        ->persistent()
                        ->send();
                }),
            Action::make('portalSetPassword')
                ->label(__('Set a portal password'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->visible(fn (Investor|ChannelPartner $record): bool => self::account($record)?->status !== InvestorPortalAccount::STATUS_DISABLED)
                ->modalDescription(__('Choose a password for this person. They can sign in to the investor portal with their phone number and this password at once; no SMS is needed. Tell it to them yourself, and ask them to change it from Profile.'))
                ->schema([
                    TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule(Password::default())
                        ->confirmed(),
                    TextInput::make('password_confirmation')
                        ->label(__('Confirm password'))
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->action(function (array $data, Investor|ChannelPartner $record): void {
                    abort_unless(auth()->user()?->hasPermission('investments.portal_manage'), 403);

                    try {
                        app(InvestorPortalAccountService::class)->setPasswordByStaff($record, (string) $data['password'], auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title(__('Could not set the password'))->body(collect($exception->errors())->flatten()->first())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('Portal password set'))->body(__('They can sign in with their phone number and this password.'))->send();
                }),
            Action::make('portalDisable')
                ->label(__('Disable portal access'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn (Investor|ChannelPartner $record): bool => in_array(self::account($record)?->status, [InvestorPortalAccount::STATUS_ACTIVE, InvestorPortalAccount::STATUS_INVITED], true))
                ->requiresConfirmation()
                ->modalDescription(__('The person is signed out at once and cannot sign in until access is enabled again. Nothing else changes.'))
                ->action(function (Investor|ChannelPartner $record): void {
                    abort_unless(auth()->user()?->hasPermission('investments.portal_manage'), 403);
                    app(InvestorPortalAccountService::class)->disable(self::account($record));
                    Notification::make()->success()->title(__('Portal access disabled'))->send();
                }),
            Action::make('portalEnable')
                ->label(__('Enable portal access'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (Investor|ChannelPartner $record): bool => self::account($record)?->status === InvestorPortalAccount::STATUS_DISABLED)
                ->requiresConfirmation()
                ->action(function (Investor|ChannelPartner $record): void {
                    abort_unless(auth()->user()?->hasPermission('investments.portal_manage'), 403);
                    app(InvestorPortalAccountService::class)->enable(self::account($record));
                    Notification::make()->success()->title(__('Portal access enabled'))->send();
                }),
            Action::make('partnerInviteOn')
                ->label(__('Turn on invite link'))
                ->icon(Heroicon::OutlinedLink)
                ->visible(fn (Investor|ChannelPartner $record): bool => $record instanceof ChannelPartner && ! $record->invite_enabled)
                ->requiresConfirmation()
                ->modalDescription(__('The partner gets a sign-up link for new investors. Every application still waits for your review in Investor Drafts.'))
                ->action(function (ChannelPartner $record): void {
                    abort_unless(auth()->user()?->hasPermission('investments.portal_manage'), 403);
                    $record->ensureInviteCode();
                    $record->forceFill(['invite_enabled' => true])->save();
                    app(AuditLogService::class)->record('investor_portal.partner_invite_on', $record);
                    Notification::make()->success()->title(__('Invite link is on'))->body((string) ($record->inviteUrl() ?? __('The link works once the investor portal is on.')))->persistent()->send();
                }),
            Action::make('partnerInviteOff')
                ->label(__('Turn off invite link'))
                ->icon(Heroicon::OutlinedLinkSlash)
                ->color('danger')
                ->visible(fn (Investor|ChannelPartner $record): bool => $record instanceof ChannelPartner && (bool) $record->invite_enabled)
                ->requiresConfirmation()
                ->action(function (ChannelPartner $record): void {
                    abort_unless(auth()->user()?->hasPermission('investments.portal_manage'), 403);
                    $record->forceFill(['invite_enabled' => false])->save();
                    app(AuditLogService::class)->record('investor_portal.partner_invite_off', $record);
                    Notification::make()->success()->title(__('Invite link is off'))->send();
                }),
        ])
            ->label(__('Portal access'))
            ->icon(Heroicon::OutlinedKey)
            ->button()
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->hasPermission('investments.portal_manage') ?? false);
    }

    public static function statusText(Investor|ChannelPartner $record): string
    {
        $account = self::account($record);

        return $account ? InvestorPortalAccount::statusLabel($account->status) : __('No access');
    }

    protected static function account(Investor|ChannelPartner $record): ?InvestorPortalAccount
    {
        return app(InvestorPortalAccountService::class)->accountFor($record);
    }
}
