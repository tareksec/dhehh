<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Services\AuditLogService;
use App\Services\InvestorPortalAccountService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Page 8 of §6.1: the details the company holds (read-only; a change goes
 * through the company office, since the deed and the NID are the record),
 * and a password change that needs the current password.
 */
class Profile extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.investor.pages.profile';

    /** Wrong current-password guesses allowed per account before a 15-minute lock. */
    protected const PASSWORD_ATTEMPTS = 5;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Profile');
    }

    public function getTitle(): string
    {
        return __('Profile');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function details(Schema $schema): Schema
    {
        $account = $this->account();
        $investor = $account->isInvestor() ? $account->investor : null;
        $partner = $account->isChannelPartner() ? $account->channelPartner : null;

        return $schema->record($investor ?? $partner)->components([
            Section::make(__('Your details'))
                ->description(__('To change any of these, please contact the company office.'))
                ->schema([
                    TextEntry::make('name')->label(__('Name'))->state($account->name),
                    TextEntry::make('phone')->label(__('Phone (sign-in)'))->state($account->phone),
                    TextEntry::make('investor_id')->label(__('Investor ID (NID / Passport)'))->state($investor?->investorId())->visible($investor !== null)->placeholder('-'),
                    TextEntry::make('email')->label(__('Email'))->state($investor?->email ?? $partner?->email)->placeholder('-'),
                    TextEntry::make('address')->label(__('Address'))->state($investor?->address ?? $partner?->address)->placeholder('-')->columnSpanFull(),
                ])->columns(2),
            Section::make(__('Nominee'))
                ->visible($investor !== null)
                ->schema([
                    TextEntry::make('nominee_name')->label(__('Name'))->state($investor?->nominee_name)->placeholder('-'),
                    TextEntry::make('nominee_relation')->label(__('Relation'))->state($investor?->nominee_relation)->placeholder('-'),
                    TextEntry::make('nominee_phone')->label(__('Phone'))->state($investor?->nominee_phone)->placeholder('-'),
                ])->columns(3),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Change password'))
                    ->schema([
                        TextInput::make('current_password')
                            ->label(__('Current password'))
                            ->password()
                            ->revealable()
                            ->autocomplete('current-password')
                            ->required(),
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
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $account = $this->account();
        $key = 'investor-portal-password:'.$account->getKey();

        if (RateLimiter::tooManyAttempts($key, self::PASSWORD_ATTEMPTS)) {
            throw ValidationException::withMessages(['data.current_password' => __('Too many attempts. Please try again later.')]);
        }

        if (! Hash::check((string) $data['current_password'], (string) $account->password)) {
            RateLimiter::hit($key, 15 * 60);

            throw ValidationException::withMessages(['data.current_password' => __('Your current password is incorrect.')]);
        }

        RateLimiter::clear($key);

        app(InvestorPortalAccountService::class)->updatePassword($account, (string) $data['password']);
        app(AuditLogService::class)->record('investor_portal.password_changed', $account);

        // Keep this session signed in after the hash changed (AuthenticateSession).
        session()->put('password_hash_'.Filament::getAuthGuard(), $account->getAuthPassword());

        $this->form->fill();

        Notification::make()->title(__('Your password has been changed.'))->success()->send();
    }
}
