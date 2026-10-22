<?php

namespace App\Filament\Investor\Pages\Auth;

use App\Models\InvestorPortalAccount;
use App\Services\InvestorPortalAccountService;
use App\Support\ImsPassword;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * The link staff send by SMS ("set your password"). A valid, unexpired link
 * lets the person choose a password, which activates the account and signs
 * them in; the link then stops working. An expired or used link only says
 * to ask the company office for a new one.
 */
class AcceptInvite extends SimplePage
{
    use WithRateLimiting;

    #[Locked]
    public string $token = '';

    public ?string $password = '';

    public ?string $passwordConfirmation = '';

    public function mount(#[SensitiveParameter] string $token): void
    {
        if (Filament::auth()->check()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        $this->token = $token;
        $this->form->fill();
    }

    public function account(): ?InvestorPortalAccount
    {
        return app(InvestorPortalAccountService::class)->findByInviteToken($this->token);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Set your password');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Set your password');
    }

    public function getSubheading(): string|Htmlable|null
    {
        $account = $this->account();

        return $account
            ? __('Welcome, :name. Choose a password to sign in to the investor portal with your phone :phone.', ['name' => $account->name, 'phone' => $account->phone])
            : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('password')
                ->label(__('New password'))
                ->password()
                ->autocomplete('new-password')
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->rule(ImsPassword::rule())
                ->same('passwordConfirmation'),
            TextInput::make('passwordConfirmation')
                ->label(__('Confirm new password'))
                ->password()
                ->autocomplete('new-password')
                ->revealable(filament()->arePasswordsRevealable())
                ->required()
                ->dehydrated(false),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->account()) {
            return $schema->components([
                Text::make(__('This link has expired or was already used. Please ask the company office to send you a new invitation.')),
                Actions::make([
                    Action::make('login')->label(__('Go to sign in'))->url(Filament::getLoginUrl())->link(),
                ]),
            ]);
        }

        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('accept')
            ->footer([
                Actions::make([
                    Action::make('accept')->label(__('Save password and sign in'))->submit('accept'),
                ])->fullWidth()->key('form-actions'),
            ]);
    }

    public function accept(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title(__('filament-panels::auth/pages/login.notifications.throttled.title', [
                    'seconds' => $exception->secondsUntilAvailable,
                    'minutes' => $exception->minutesUntilAvailable,
                ]))
                ->danger()
                ->send();

            return;
        }

        $account = $this->account();

        if (! $account) {
            Notification::make()->title(__('This link has expired or was already used. Please ask the company office to send you a new invitation.'))->danger()->send();

            return;
        }

        $data = $this->form->getState();
        $service = app(InvestorPortalAccountService::class);
        $service->acceptInvite($account, (string) $data['password']);

        if ($account->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            Filament::auth()->login($account);
            session()->regenerate();
            $service->recordLogin($account, request()->ip(), 'invite');
        }

        Notification::make()->title(__('Your password is saved.'))->success()->send();

        $this->redirect(Filament::getUrl());
    }
}
