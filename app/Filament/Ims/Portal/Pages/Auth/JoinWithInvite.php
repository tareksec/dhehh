<?php

namespace App\Filament\Ims\Portal\Pages\Auth;

use App\Filament\Ims\Portal\Pages\Kyc;
use App\Filament\Resources\Investors\RelationManagers\NomineesRelationManager;
use App\Ims\Onboarding\InviteService;
use App\Ims\Onboarding\OtpService;
use App\Models\InvestorInvite;
use App\Models\InvestorOtpCode;
use App\Services\CompanyContext;
use App\Services\InvestorPortalAccountService;
use App\Support\ImsPassword;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * Joining IMS with an office invite (IMS-V2/PRD.md FR-04, DESIGN §8.1):
 * one step a screen — (1) get a code on the invited phone or e-mail,
 * (2) type the code, (3) your details, nominees and a password. The account
 * opens at once; KYC comes next. A wrong, used or expired link — or joining
 * switched off — all get the same plain answer.
 */
class JoinWithInvite extends SimplePage
{
    use WithRateLimiting;

    #[Locked]
    public string $token = '';

    /** 1 = send code, 2 = type code, 3 = details. Moves only through the server. */
    #[Locked]
    public int $step = 1;

    public ?string $code = '';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(#[SensitiveParameter] string $token): void
    {
        if (Filament::auth()->check()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        $this->token = $token;
        $invite = $this->invite();

        if ($invite && session()->get($this->sessionKey($invite)) === true) {
            $this->step = 3;
        }

        $this->form->fill([
            'name' => $invite?->name,
            'phone' => $invite?->contact_type === 'phone' ? $invite->contact : null,
            'email' => $invite?->contact_type === 'email' ? $invite->contact : null,
            'nominees' => [['name' => null, 'share_percent' => 100]],
        ]);
    }

    public function invite(): ?InvestorInvite
    {
        $company = app(CompanyContext::class)->company();

        return $company ? app(InviteService::class)->findUsable($company, $this->token) : null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Join');
    }

    public function getHeading(): string|Htmlable|null
    {
        return match ($this->step) {
            1 => __('Step 1 of 3: get your code'),
            2 => __('Step 2 of 3: type your code'),
            default => __('Step 3 of 3: your details'),
        };
    }

    public function getSubheading(): string|Htmlable|null
    {
        $invite = $this->invite();

        if (! $invite) {
            return null;
        }

        return match ($this->step) {
            1 => __('We will send a 6-digit code to :contact.', ['contact' => $this->masked($invite)]),
            2 => __('We sent a code to :contact. It works for :minutes minutes.', ['contact' => $this->masked($invite), 'minutes' => InvestorOtpCode::VALID_MINUTES]),
            default => __('Your details, your nominee and a password. Your NID pictures come next.'),
        };
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('name')->label(__('Full name (as on the NID)'))->required()->maxLength(255),
                TextInput::make('phone')->label(__('Mobile number'))->tel()->required()
                    ->disabled(fn (): bool => $this->invite()?->contact_type === 'phone')
                    ->dehydrated(),
                TextInput::make('email')->label(__('E-mail (optional)'))->email()
                    ->disabled(fn (): bool => $this->invite()?->contact_type === 'email')
                    ->dehydrated(),
                DatePicker::make('date_of_birth')->label(__('Date of birth'))->required()->maxDate(now()->subYears(18))
                    ->helperText(__('You must be 18 or older.')),
                TextInput::make('nid_number')->label(__('NID number'))->required()->maxLength(30),
                TextInput::make('guardian_name')->label(__('Father\'s or husband\'s name'))->maxLength(255),
                TextInput::make('address')->label(__('Address'))->maxLength(500),
                NomineesRelationManager::nomineesRepeater(),
                TextInput::make('password')->label(__('Password'))->password()->revealable()->autocomplete('new-password')->required()
                    ->rule(ImsPassword::rule())->same('passwordConfirmation')
                    ->helperText(__('At least 10 characters, with letters and numbers.')),
                TextInput::make('passwordConfirmation')->label(__('Password again'))->password()->revealable()->autocomplete('new-password')->required()->dehydrated(false),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->invite()) {
            return $schema->components([
                Text::make(__('This invite link is not valid any more. Please ask the office for a new one.')),
                Actions::make([Action::make('login')->label(__('Go to sign in'))->url(Filament::getLoginUrl())->link()]),
            ]);
        }

        return match ($this->step) {
            1 => $schema->components([
                Actions::make([Action::make('sendCode')->label(__('Send me a code'))->action(fn () => $this->sendCode())])->fullWidth(),
            ]),
            2 => $schema->components([
                TextInput::make('code')->label(__('6-digit code'))->required()->length(6)->numeric()->autocomplete('one-time-code'),
                Actions::make([
                    Action::make('verify')->label(__('Next'))->action(fn () => $this->verifyCode()),
                    Action::make('resend')->label(__('Send the code again'))->link()->action(fn () => $this->sendCode()),
                ])->fullWidth(),
            ]),
            default => $schema->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('join')
                    ->footer([Actions::make([Action::make('join')->label(__('Open my account'))->submit('join')])->fullWidth()]),
            ]),
        };
    }

    public function sendCode(): void
    {
        if (! $this->throttle(5)) {
            return;
        }

        $invite = $this->invite();

        if (! $invite) {
            return;
        }

        $sent = app(OtpService::class)->send(app(CompanyContext::class)->company(), $invite->contact, $invite->contact_type);

        if (! $sent) {
            Notification::make()->title(__('The code could not be sent now. Please try again later or ask the office.'))->danger()->send();

            return;
        }

        $this->step = 2;
    }

    public function verifyCode(): void
    {
        if (! $this->throttle(10)) {
            return;
        }

        $invite = $this->invite();

        if (! $invite || ! app(OtpService::class)->verify(app(CompanyContext::class)->company(), $invite->contact, (string) $this->code)) {
            Notification::make()->title(__('That code is not right or has expired.'))->danger()->send();

            return;
        }

        session()->put($this->sessionKey($invite), true);
        $this->step = 3;
    }

    public function join(): void
    {
        if (! $this->throttle(5)) {
            return;
        }

        $invite = $this->invite();

        if (! $invite || session()->get($this->sessionKey($invite)) !== true) {
            $this->step = 1;

            return;
        }

        $data = $this->form->getState();
        $account = app(InviteService::class)->complete($invite, $data);
        session()->forget($this->sessionKey($invite));

        if ($account->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            Filament::auth()->login($account);
            session()->regenerate();
            app(InvestorPortalAccountService::class)->recordLogin($account, request()->ip(), 'invite');
        }

        Notification::make()->title(__('Welcome! Your account is open. Next, send your NID pictures.'))->success()->send();

        $this->redirect(Kyc::getUrl());
    }

    private function throttle(int $perMinute): bool
    {
        try {
            $this->rateLimit($perMinute);

            return true;
        } catch (TooManyRequestsException $exception) {
            Notification::make()->title(__('filament-panels::auth/pages/login.notifications.throttled.title', [
                'seconds' => $exception->secondsUntilAvailable,
                'minutes' => $exception->minutesUntilAvailable,
            ]))->danger()->send();

            return false;
        }
    }

    private function sessionKey(InvestorInvite $invite): string
    {
        return 'ims_invite_verified_'.$invite->getKey();
    }

    private function masked(InvestorInvite $invite): string
    {
        if ($invite->contact_type === 'email') {
            [$user, $domain] = array_pad(explode('@', $invite->contact, 2), 2, '');

            return mb_substr($user, 0, 2).'•••@'.$domain;
        }

        return substr($invite->contact, 0, 3).'•••••'.substr($invite->contact, -3);
    }
}
