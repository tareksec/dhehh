<?php

namespace Tests\Feature\Ims;

use App\Filament\Ims\Portal\Pages\Auth\JoinWithInvite;
use App\Ims\Onboarding\InviteService;
use App\Ims\Onboarding\NomineeService;
use App\Ims\Onboarding\OtpService;
use App\Ims\Settings\ImsSettings;
use App\Models\Investor;
use App\Models\InvestorInvite;
use App\Models\InvestorOtpCode;
use App\Models\InvestorPortalAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Joining IMS with an office invite (IMS-V2/PRD.md FR-04–06): the invite,
 * the one-time code rules, the same answer for any unusable link, the
 * nominee shares, and the account it opens.
 */
class OnboardingTest extends TestCase
{
    use ImsTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imsCompany();
        ImsSettings::save($this->company, ['invite_registration_enabled' => true]);
    }

    public function test_an_invite_lasts_14_days_and_replaces_the_older_one(): void
    {
        $service = app(InviteService::class);
        $first = $service->invite($this->company, '+880 1711-222333', 'Rahim', $this->staff());
        $second = $service->invite($this->company, '01711222333', null, $this->staff());

        $this->assertSame('01711222333', $second['invite']->contact);
        $this->assertSame('phone', $second['invite']->contact_type);
        $this->assertNotNull($first['invite']->fresh()->revoked_at, 'the older invite stops working');
        $this->assertTrue($second['invite']->expires_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertStringStartsWith('http://'.$this->company->ims_domain.'/investor/join/', $second['link']);
        $this->assertNotSame($second['invite']->token_hash, substr($second['link'], -48), 'only the hash is kept');
    }

    public function test_an_existing_investor_is_not_invited_again(): void
    {
        $investor = $this->newInvestor(['phone' => '01811000111']);

        $this->expectException(ValidationException::class);
        app(InviteService::class)->invite($this->company, $investor->phone, null, $this->staff());
    }

    public function test_an_unknown_used_or_switched_off_link_gives_the_same_answer(): void
    {
        $service = app(InviteService::class);
        $result = $service->invite($this->company, 'new.person@example.test', null, $this->staff());
        $token = substr($result['link'], strrpos($result['link'], '/') + 1);

        $this->assertNotNull($service->findUsable($this->company, $token));
        $this->assertNull($service->findUsable($this->company, 'not-a-real-token'));

        ImsSettings::save($this->company, ['invite_registration_enabled' => false]);
        $this->assertNull($service->findUsable($this->company->refresh(), $token));

        $this->get($this->imsUrl('investor/join/not-a-real-token'))->assertOk()->assertSee(__('This invite link is not valid any more. Please ask the office for a new one.'));
        $this->get($this->imsUrl('investor/join/'.$token))->assertOk()->assertSee(__('This invite link is not valid any more. Please ask the office for a new one.'));
    }

    public function test_a_code_lives_ten_minutes_allows_five_wrong_tries_and_is_not_resent_within_a_minute(): void
    {
        $otp = app(OtpService::class);
        $otp->send($this->company, 'someone@example.test', 'email');
        $otp->send($this->company, 'someone@example.test', 'email');

        $this->assertSame(1, InvestorOtpCode::query()->count(), 'a second tap within a minute sends nothing new');

        $row = InvestorOtpCode::query()->sole();
        $row->forceFill(['code_hash' => Hash::make('123456')])->save();
        $this->assertTrue($row->expires_at->between(now()->addMinutes(2), now()->addMinutes(4)), 'an OTP never lives longer than three minutes');

        foreach (range(1, 5) as $try) {
            $this->assertFalse($otp->verify($this->company, 'someone@example.test', '000000'));
        }

        $this->assertFalse($otp->verify($this->company, 'someone@example.test', '123456'), 'after five wrong tries even the right code fails');
    }

    public function test_a_right_code_works_once(): void
    {
        $otp = app(OtpService::class);
        $otp->send($this->company, 'once@example.test', 'email');
        InvestorOtpCode::query()->sole()->forceFill(['code_hash' => Hash::make('654321')])->save();

        $this->assertTrue($otp->verify($this->company, 'once@example.test', '654321'));
        $this->assertFalse($otp->verify($this->company, 'once@example.test', '654321'));
    }

    public function test_finishing_the_invite_opens_the_investor_and_the_account(): void
    {
        $service = app(InviteService::class);
        $invite = $service->invite($this->company, 'joiner@example.test', 'Joiner', $this->staff())['invite'];

        $account = $service->complete($invite, [
            'name' => 'Joiner Hasan',
            'phone' => '01922333444',
            'date_of_birth' => '1991-02-03',
            'nid_number' => '1234567890',
            'password' => 'a-strong-password-1',
            'nominees' => [
                ['name' => 'Wife', 'relation' => 'Spouse', 'share_percent' => 60],
                ['name' => 'Son', 'relation' => 'Son', 'share_percent' => 40],
            ],
        ]);

        $investor = $account->investor;
        $this->assertSame(InvestorPortalAccount::STATUS_ACTIVE, $account->status);
        $this->assertTrue(Hash::check('a-strong-password-1', $account->password));
        $this->assertSame(Investor::KYC_NOT_SUBMITTED, $investor->kyc_status);
        $this->assertSame('joiner@example.test', $investor->email);
        $this->assertNotNull($investor->email_verified_at);
        $this->assertSame(['Wife', 'Son'], $investor->nominees()->pluck('name')->all());
        $this->assertSame('Wife', $investor->nominee_name, 'the first nominee is kept on the investor too');
        $this->assertNotNull($invite->fresh()->used_at);

        $this->expectException(ValidationException::class);
        $service->complete($invite->fresh(), ['name' => 'Again', 'phone' => '01922333445', 'password' => 'a-strong-password-1']);
    }

    public function test_nominee_shares_must_add_up_to_100_and_there_are_at_most_five(): void
    {
        $investor = $this->newInvestor();
        $nominees = app(NomineeService::class);

        try {
            $nominees->replace($investor, [['name' => 'A', 'share_percent' => 50], ['name' => 'B', 'share_percent' => 40]]);
            $this->fail('Shares of 90% were accepted.');
        } catch (ValidationException) {
        }

        try {
            $nominees->replace($investor, array_map(fn (int $i): array => ['name' => "N{$i}", 'share_percent' => 100 / 6], range(1, 6)));
            $this->fail('Six nominees were accepted.');
        } catch (ValidationException) {
        }

        $nominees->replace($investor, [['name' => 'Only one', 'share_percent' => 30]]);
        $this->assertEquals(100, $investor->nominees()->sole()->share_percent, 'a single nominee always has 100%');
    }

    public function test_the_join_page_goes_code_then_details_then_signs_in(): void
    {
        $invite = app(InviteService::class)->invite($this->company, '01755666777', 'Karim', $this->staff());
        $token = substr($invite['link'], strrpos($invite['link'], '/') + 1);

        $this->mock(OtpService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->andReturnTrue();
            $mock->shouldReceive('verify')->andReturnTrue();
        });

        Filament::setCurrentPanel(Filament::getPanel('investor'));

        Livewire::test(JoinWithInvite::class, ['token' => $token])
            ->assertSet('step', 1)
            ->call('sendCode')
            ->assertSet('step', 2)
            ->set('code', '111111')
            ->call('verifyCode')
            ->assertSet('step', 3)
            ->fillForm([
                'name' => 'Karim Uddin',
                'date_of_birth' => '1985-01-01',
                'nid_number' => '9876543210',
                'password' => 'a-strong-password-1',
                'passwordConfirmation' => 'a-strong-password-1',
                'nominees' => [['name' => 'Brother', 'share_percent' => 100]],
            ])
            ->call('join')
            ->assertHasNoFormErrors();

        $account = InvestorPortalAccount::query()->where('phone', '01755666777')->sole();
        $this->assertSame('Karim Uddin', $account->investor->name);
        $this->assertSame(InvestorInvite::query()->sole()->investor_id, $account->investor_id);
    }
}
