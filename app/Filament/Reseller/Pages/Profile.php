<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Reseller\Concerns\ForApprovedResellers;
use App\Models\StorefrontCustomerActivity;
use App\Services\CustomerAccountService;
use App\Services\StorefrontCustomerActivityService;
use App\Support\EmailFormat;
use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Reseller portal Phase 5 (13_RESELLER_PORTAL_PLAN.md §3 row 7): name, email
 * and password change. The phone number is read-only -- it is the login, and
 * every record (orders, commissions, payouts) is tied to it. Same rules as the
 * storefront's own profile page: a new password needs the current one, and an
 * email already used by another login account is refused.
 */
class Profile extends Page
{
    use ForApprovedResellers;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.reseller.pages.profile';

    /** Wrong current-password guesses allowed per reseller before a 15-minute lock. */
    protected const PASSWORD_ATTEMPTS = 5;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function getTitle(): string
    {
        return __('Profile');
    }

    public static function getNavigationLabel(): string
    {
        return __('Profile');
    }

    public function mount(): void
    {
        $reseller = $this->reseller();

        $this->form->fill([
            'name' => $reseller->name,
            'phone' => $reseller->phone,
            'email' => $reseller->email,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $reseller = $this->reseller();

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Profile'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->label(__('Phone'))
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(__('You log in with this number, so it cannot be changed here.')),

                        TextInput::make('email')
                            ->label(__('Email'))
                            ->maxLength(255)
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (filled($value) && ! preg_match(EmailFormat::PATTERN, (string) $value)) {
                                    $fail(EmailFormat::MESSAGE);
                                }
                            })
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($reseller): void {
                                if (filled($value)
                                    && $value !== $reseller->email
                                    && ! app(CustomerAccountService::class)->emailAvailable($value, $reseller->getKey())) {
                                    $fail(__('That email address is already in use by another account.'));
                                }
                            }),
                    ]),

                Section::make(__('Change password'))
                    ->description(__('Leave these empty to keep your current password.'))
                    ->schema([
                        TextInput::make('current_password')
                            ->label(__('Current password'))
                            ->password()
                            ->revealable()
                            ->autocomplete('current-password')
                            ->required(fn (Get $get): bool => filled($get('password')))
                            ->dehydrated(false),

                        TextInput::make('password')
                            ->label(__('New password'))
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->rule(Password::default())
                            ->same('passwordConfirmation')
                            ->validationAttribute(__('password')),

                        TextInput::make('passwordConfirmation')
                            ->label(__('Confirm new password'))
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (Get $get): bool => filled($get('password')))
                            ->dehydrated(false),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $reseller = $this->reseller();
        $activities = app(StorefrontCustomerActivityService::class);

        $changingPassword = filled($data['password'] ?? null);

        if ($changingPassword) {
            $this->ensureCurrentPasswordMatches((string) ($this->data['current_password'] ?? ''));
        }

        $profile = [
            'name' => trim($data['name']),
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
        ];

        $changedFields = collect($profile)
            ->filter(fn ($value, string $field): bool => $reseller->getAttribute($field) !== $value)
            ->keys()
            ->values()
            ->all();

        $reseller->update($profile);

        if ($changedFields !== []) {
            $activities->record(
                $reseller,
                StorefrontCustomerActivity::TYPE_PROFILE_UPDATED,
                'Profile updated',
                'Your customer information was updated.',
                ['fields' => $changedFields],
            );
        }

        if ($changingPassword) {
            app(CustomerAccountService::class)->updatePassword($reseller, $data['password']);

            // The panel's AuthenticateSession middleware signs a session out
            // when the stored password hash stops matching -- without this the
            // reseller would be logged out by their own password change
            // (same step Filament's own EditProfile page takes).
            session()->put('password_hash_'.Filament::getAuthGuard(), $reseller->getAuthPassword());

            $activities->record(
                $reseller,
                StorefrontCustomerActivity::TYPE_PASSWORD_CHANGED,
                'Password changed',
                'Your account password was changed successfully.',
            );
        }

        $this->data['current_password'] = null;
        $this->data['password'] = null;
        $this->data['passwordConfirmation'] = null;

        Notification::make()
            ->title(__('Your profile has been updated.'))
            ->success()
            ->send();
    }

    /**
     * A wrong current password is throttled per reseller, so a hijacked
     * session can't be used to guess it.
     */
    protected function ensureCurrentPasswordMatches(string $current): void
    {
        $reseller = $this->reseller();
        $key = 'reseller-portal-password:'.$reseller->getKey();

        if (RateLimiter::tooManyAttempts($key, self::PASSWORD_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'data.current_password' => __('Too many attempts. Please try again later.'),
            ]);
        }

        if (! Hash::check($current, (string) $reseller->password)) {
            RateLimiter::hit($key, 15 * 60);

            throw ValidationException::withMessages([
                'data.current_password' => __('Your current password is incorrect.'),
            ]);
        }

        RateLimiter::clear($key);
    }
}
