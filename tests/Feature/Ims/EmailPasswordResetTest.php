<?php

namespace Tests\Feature\Ims;

use App\Filament\Investor\Pages\Auth\ForgotPassword;
use App\Mail\ImsMessage;
use App\Models\InvestorPortalAccount;
use App\Models\StorefrontSetting;
use App\Services\InvestorPortalAccountService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Forgot password?" by e-mail on the investor portal (IMS-V2/PLAN.md §4ক:
 * SMS + e-mail, owner 2026-10-19): the code goes to the e-mail on the
 * investor's record, lives 10 minutes, and the reply never tells whether an
 * address belongs to an account.
 */
class EmailPasswordResetTest extends TestCase
{
    use ImsTestHelpers;
    use RefreshDatabase;

    private function withEmail(): void
    {
        StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => ['mail_host' => 'smtp.example.test', 'mail_username' => 'office@example.test', 'mail_password' => 'x'],
        ]);
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('investor'));
    }

    private function sentCode(): string
    {
        $code = null;
        Mail::assertSent(ImsMessage::class, function (ImsMessage $mail) use (&$code): bool {
            preg_match('/\b(\d{6})\b/', implode(' ', $mail->lines), $match);
            $code = $match[1] ?? null;

            return true;
        });

        return (string) $code;
    }

    public function test_a_code_is_sent_by_email_and_resets_the_password(): void
    {
        $this->imsCompany();
        $this->withEmail();
        $account = $this->portalAccount($this->newInvestor(['email' => 'Rahim@Example.test']));

        // Only e-mail is set up here, so it is chosen without asking.
        Livewire::test(ForgotPassword::class)
            ->assertFormFieldVisible('email')
            ->assertFormFieldHidden('channel')
            ->fillForm(['channel' => ForgotPassword::BY_EMAIL, 'email' => 'rahim@example.test'])
            ->call('request')
            ->assertHasNoFormErrors()
            ->assertSet('sentBy', ForgotPassword::BY_EMAIL)
            ->assertNotified(__('If an investor portal account uses that e-mail address, a reset code has been sent by e-mail.'))
            ->fillForm(['code' => $this->sentCode(), 'password' => 'new-password-22', 'passwordConfirmation' => 'new-password-22'])
            ->call('request')
            ->assertHasNoFormErrors();

        Mail::assertSent(ImsMessage::class, fn (ImsMessage $mail): bool => $mail->hasTo('Rahim@Example.test'));
        $this->assertTrue(Hash::check('new-password-22', (string) $account->fresh()->password));
    }

    public function test_the_email_code_lives_ten_minutes(): void
    {
        $this->imsCompany();
        $this->withEmail();
        $account = $this->portalAccount($this->newInvestor(['email' => 'karim@example.test']));

        $this->assertTrue(app(InvestorPortalAccountService::class)->sendResetCodeByEmail($account));

        $expires = $account->fresh()->otp_expires_at;
        $this->assertTrue($expires->between(now()->addMinutes(9), now()->addMinutes(11)));
    }

    public function test_an_unknown_or_shared_email_gets_the_same_reply_and_no_mail(): void
    {
        $this->imsCompany();
        $this->withEmail();
        $this->portalAccount($this->newInvestor(['email' => 'family@example.test']));
        $this->portalAccount($this->newInvestor(['email' => 'family@example.test']));

        foreach (['family@example.test', 'nobody@example.test'] as $email) {
            cache()->flush(); // the page allows two code requests a minute
            Livewire::test(ForgotPassword::class)
                ->fillForm(['channel' => ForgotPassword::BY_EMAIL, 'email' => $email])
                ->call('request')
                ->assertSet('sentTo', $email)
                ->assertNotified(__('If an investor portal account uses that e-mail address, a reset code has been sent by e-mail.'));
        }

        Mail::assertNothingSent();
        $this->assertSame(0, InvestorPortalAccount::query()->whereNotNull('otp_code_hash')->count());
    }

    public function test_the_email_choice_shows_only_when_the_company_can_send_email(): void
    {
        $this->imsCompany();
        Filament::setCurrentPanel(Filament::getPanel('investor'));

        Livewire::test(ForgotPassword::class)->assertDontSee(__('By e-mail'))->assertFormFieldHidden('email');
    }

    public function test_with_sms_and_email_both_set_up_the_person_chooses(): void
    {
        $this->imsCompany();
        StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => [
                'mail_host' => 'smtp.example.test', 'mail_username' => 'office@example.test', 'mail_password' => 'x',
                'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}',
                'sms_api_key' => 'key', 'sms_sender_id' => 'IMS',
            ],
        ]);
        Filament::setCurrentPanel(Filament::getPanel('investor'));

        Livewire::test(ForgotPassword::class)
            ->assertSee(__('By SMS'))
            ->assertSee(__('By e-mail'))
            ->assertFormFieldVisible('phone')
            ->fillForm(['channel' => ForgotPassword::BY_EMAIL])
            ->assertFormFieldVisible('email')
            ->assertFormFieldHidden('phone');
    }
}
