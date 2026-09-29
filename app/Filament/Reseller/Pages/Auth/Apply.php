<?php

namespace App\Filament\Reseller\Pages\Auth;

use App\Models\Customer;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

/**
 * "Become a reseller" -- same rule as the pre-portal /reseller apply form
 * (ResellerController::apply()): a match on phone is reused rather than
 * duplicated, so someone with past storefront orders isn't asked to start
 * over, and re-applying never downgrades an already-approved reseller. The
 * one difference from the old form: this one also sets a password, so
 * approval alone is enough to log into the portal (owner's answer, §2.1 Q4).
 */
class Apply extends BaseRegister
{
    public function getTitle(): string
    {
        return __('Become a reseller');
    }

    public function getHeading(): string
    {
        return __('Become a reseller');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getPhoneFormComponent(),
                $this->getBusinessNameFormComponent(),
                $this->getNoteFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    protected function getPhoneFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label(__('Phone'))
            ->required()
            ->maxLength(40);
    }

    protected function getBusinessNameFormComponent(): Component
    {
        return TextInput::make('business_name')
            ->label(__('Business name'))
            ->required()
            ->maxLength(255);
    }

    protected function getNoteFormComponent(): Component
    {
        return Textarea::make('note')
            ->label(__('Note (optional)'))
            ->maxLength(1000);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('Password'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(Password::default())
            ->same('passwordConfirmation')
            ->validationAttribute(__('password'));
    }

    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $customer = Customer::query()->firstOrNew(['phone' => $data['phone']]);

        $customer->fill([
            'name' => $data['name'],
            'business_name' => $data['business_name'],
            'reseller_note' => $data['note'] ?? null,
            'reseller_status' => $customer->reseller_status === 'approved' ? 'approved' : 'pending',
            'customer_type' => $customer->customer_type ?: 'reseller',
            'customer_source' => $customer->customer_source ?: 'website',
            'opening_balance' => $customer->opening_balance ?? 0,
            'is_active' => true,
        ]);
        $customer->password = Hash::make($data['password']);
        $customer->save();

        return $customer;
    }
}
