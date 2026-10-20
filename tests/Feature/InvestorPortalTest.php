<?php

namespace Tests\Feature;

use App\Filament\Investor\Pages\Auth\AcceptInvite;
use App\Filament\Investor\Pages\Auth\Login;
use App\Filament\Pages\InvestorPortalSettings;
use App\Filament\Resources\Investors\Pages\ViewInvestor;
use App\Models\AuditLog;
use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorPortalAccount;
use App\Models\ProjectCostItem;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Investment\SettlementService;
use App\Services\InvestorPortalAccountService;
use App\Services\InvestorPortalService;
use App\Services\SmsTemplateService;
use App\Support\StorefrontApi\Phone;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phases 1–2 of 15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md: the investor
 * portal (a third Filament panel, guard "investor") at
 * investor.<domain>/investor, staff invites by SMS, password and SMS-code
 * sign-in, and read-only pages that show an investor or channel partner only
 * their own records.
 */
class InvestorPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['sms.example.test/*' => Http::response('OK', 200)]);
        $this->staff = User::factory()->create(['role' => 'super_admin']);
    }

    // ---- Host boundary -------------------------------------------------

    public function test_the_portal_opens_only_on_its_own_host(): void
    {
        $this->company('host-a.example.test');

        $this->get('http://host-a.example.test/investor')->assertNotFound();
        $this->get('http://investor.host-a.example.test/')->assertRedirect('/investor');
        $this->get('http://investor.host-a.example.test/admin')->assertRedirect('/investor');
        $this->followingRedirects()->get('http://investor.host-a.example.test/investor')->assertOk()->assertSee(__('Phone'));
    }

    public function test_the_portal_is_a_404_while_it_is_switched_off(): void
    {
        $this->company('off.example.test', enabled: false);

        $this->get('http://investor.off.example.test/investor')->assertNotFound();
    }

    public function test_a_custom_portal_domain_replaces_the_investor_prefix(): void
    {
        $company = $this->company('custom.example.test');
        $company->update(['investor_portal_domain' => 'invest.example.test']);

        $this->followingRedirects()->get('http://invest.example.test/investor')->assertOk();
        $this->get('http://investor.custom.example.test/investor')->assertNotFound();
    }

    public function test_the_reseller_portal_is_unaffected(): void
    {
        $company = $this->company('both.example.test');
        $company->update(['reseller_module_enabled' => true]);

        $this->followingRedirects()->get('http://reseller.both.example.test/portal')->assertOk();
        $this->get('http://reseller.both.example.test/investor')->assertRedirect('/portal');
        $this->get('http://investor.both.example.test/portal')->assertRedirect('/investor');
    }

    // ---- Invite and sign-in ---------------------------------------------

    public function test_staff_invite_sends_an_sms_link_that_sets_the_password_once(): void
    {
        $company = $this->company('invite.example.test', withSms: true);
        $investor = $this->investor($company, 'Rahim', '+880 1711-000001');

        Auth::login($this->staff);
        $link = app(InvestorPortalAccountService::class)->invite($investor, $this->staff);
        Auth::logout();

        $this->assertStringStartsWith('http://investor.invite.example.test/investor/invite/', $link);
        Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), '/investor/invite/') && str_contains($request->url(), 'to=8801711000001'));

        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->where('investor_id', $investor->id)->firstOrFail();
        $this->assertSame('01711000001', $account->phone);
        $this->assertSame(InvestorPortalAccount::STATUS_INVITED, $account->status);
        $this->assertNull($account->password);

        $token = Str::afterLast($link, '/');
        $this->onPortal($company);

        Livewire::test(AcceptInvite::class, ['token' => $token])
            ->fillForm(['password' => 'a-strong-password-1', 'passwordConfirmation' => 'a-strong-password-1'])
            ->call('accept')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame(InvestorPortalAccount::STATUS_ACTIVE, $account->status);
        $this->assertNull($account->invite_token_hash);
        $this->assertTrue(auth('investor')->check());

        // The link works once.
        $this->assertNull(app(InvestorPortalAccountService::class)->findByInviteToken($token));
    }

    public function test_an_expired_invite_link_cannot_set_a_password(): void
    {
        $company = $this->company('expired.example.test');
        $investor = $this->investor($company, 'Late', '01711000002');
        $link = app(InvestorPortalAccountService::class)->invite($investor);

        $this->travel(InvestorPortalAccountService::INVITE_VALID_DAYS + 1)->days();
        $this->onPortal($company);

        Livewire::test(AcceptInvite::class, ['token' => Str::afterLast($link, '/')])
            ->assertSee(__('This link has expired or was already used. Please ask the company office to send you a new invitation.'));
    }

    public function test_one_person_who_is_investor_and_partner_gets_one_account(): void
    {
        $company = $this->company('both-roles.example.test');
        $investor = $this->investor($company, 'Karim', '01711000003');
        $partner = ChannelPartner::query()->create(['company_id' => $company->id, 'name' => 'Karim', 'phone' => '+8801711000003']);

        app(InvestorPortalAccountService::class)->invite($investor);
        app(InvestorPortalAccountService::class)->invite($partner);

        $accounts = InvestorPortalAccount::query()->withoutGlobalScopes()->get();
        $this->assertCount(1, $accounts);
        $this->assertSame($investor->id, $accounts->first()->investor_id);
        $this->assertSame($partner->id, $accounts->first()->channel_partner_id);
    }

    public function test_an_invite_needs_the_portal_switched_on(): void
    {
        $company = $this->company('needs-on.example.test', enabled: false);
        $investor = $this->investor($company, 'Off', '01711000004');

        $this->expectException(ValidationException::class);
        app(InvestorPortalAccountService::class)->invite($investor);
    }

    public function test_password_sign_in_works_and_a_wrong_password_or_disabled_account_does_not(): void
    {
        $company = $this->company('login.example.test');
        $account = $this->activeAccount($company, $this->investor($company, 'Login', '01711000005'));
        $this->onPortal($company);

        Livewire::test(Login::class)
            ->fillForm(['phone' => '01711000005', 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['phone']);
        $this->assertFalse(auth('investor')->check());

        // Any spelling of the number signs in.
        Livewire::test(Login::class)
            ->fillForm(['phone' => '+880 1711-000005', 'password' => 'a-strong-password-1'])
            ->call('authenticate')
            ->assertHasNoFormErrors();
        $this->assertTrue(auth('investor')->check());
        $this->assertNotNull($account->fresh()->last_login_at);

        auth('investor')->logout();
        app(InvestorPortalAccountService::class)->disable($account);

        Livewire::test(Login::class)
            ->fillForm(['phone' => '01711000005', 'password' => 'a-strong-password-1'])
            ->call('authenticate')
            ->assertHasFormErrors(['phone']);
    }

    public function test_a_disabled_account_is_locked_out_of_an_open_session(): void
    {
        $company = $this->company('lockout.example.test');
        $account = $this->activeAccount($company, $this->investor($company, 'Locked', '01711000006'));

        $this->actingAs($account, 'investor');
        $this->followingRedirects()->get('http://investor.lockout.example.test/investor')->assertOk()->assertSee(__('Capital at work'));

        app(InvestorPortalAccountService::class)->disable($account);

        $this->actingAs($account->fresh(), 'investor');
        $this->get('http://investor.lockout.example.test/investor/dashboard')->assertForbidden();
    }

    public function test_an_account_of_one_company_cannot_open_another_companys_portal(): void
    {
        $companyA = $this->company('company-a.example.test');
        $companyB = $this->company('company-b.example.test');
        $account = $this->activeAccount($companyA, $this->investor($companyA, 'A', '01711000007'));

        $this->actingAs($account, 'investor');

        $this->followingRedirects()->get('http://investor.company-a.example.test/investor')->assertOk();
        $this->get('http://investor.company-b.example.test/investor/dashboard')->assertForbidden();
    }

    public function test_sms_code_sign_in_works_and_five_wrong_codes_burn_the_code(): void
    {
        $company = $this->company('otp.example.test', withSms: true);
        $account = $this->activeAccount($company, $this->investor($company, 'Otp', '01711000008'));
        $service = app(InvestorPortalAccountService::class);
        $this->onPortal($company);

        $code = null;
        Http::fake(['sms.example.test/*' => function ($request) use (&$code) {
            preg_match('/\b(\d{6})\b/', urldecode($request->url()), $matches);
            $code = $matches[1] ?? null;

            return Http::response('OK', 200);
        }]);

        Livewire::test(Login::class)
            ->call('useCode')
            ->fillForm(['phone' => '01711000008'])
            ->call('authenticate')
            ->assertSet('codePhone', '01711000008')
            ->fillForm(['code' => (string) $code])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertNotNull($code);
        $this->assertTrue(auth('investor')->check());
        auth('investor')->logout();

        $this->travel(2)->minutes();
        $this->assertTrue($service->sendCode($account->fresh()));
        $realCode = $code;

        foreach (range(1, InvestorPortalAccountService::CODE_MAX_ATTEMPTS) as $try) {
            $this->assertFalse($service->verifyCode($account->fresh(), $realCode === '000000' ? '111111' : '000000'));
        }

        $this->assertFalse($service->verifyCode($account->fresh(), (string) $realCode));
    }

    public function test_the_investor_code_sms_names_the_portal_and_the_reason_and_lives_one_minute(): void
    {
        $company = $this->company('wording.example.test', withSms: true);
        $account = $this->activeAccount($company, $this->investor($company, 'Wording', '01711000009'));
        $service = app(InvestorPortalAccountService::class);
        $texts = [];

        Http::fake(['sms.example.test/*' => function ($request) use (&$texts) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $texts[] = $query['msg'];

            return Http::response('OK', 200);
        }]);

        $this->assertTrue($service->sendCode($account->fresh(), 'login'));
        $this->assertMatchesRegularExpression(
            '/^Your '.preg_quote($company->name, '/').' Investment Portal OTP is \d{6}\.$/',
            $texts[0],
        );
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($account->fresh()->otp_expires_at, false), 2);

        $this->travel(2)->minutes();
        $this->assertTrue($service->sendCode($account->fresh(), 'reset'));
        $this->assertMatchesRegularExpression(
            '/^Your '.preg_quote($company->name, '/').' Investment Portal OTP is \d{6}\.$/',
            $texts[1],
        );

        // Past the minute the code no longer works.
        preg_match('/(\d{6})/', $texts[1], $matches);
        $this->travel(61)->seconds();
        $this->assertFalse($service->verifyCode($account->fresh(), $matches[1]));
    }

    public function test_the_company_can_word_the_investor_code_sms_itself(): void
    {
        $company = $this->company('own-words.example.test', withSms: true);
        $account = $this->activeAccount($company, $this->investor($company, 'Own Words', '01711000012'));
        $service = app(InvestorPortalAccountService::class);
        $templates = app(SmsTemplateService::class);
        $texts = [];

        Http::fake(['sms.example.test/*' => function ($request) use (&$texts) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $texts[] = $query['msg'];

            return Http::response('OK', 200);
        }]);

        $templates->save($company->id, [
            SmsTemplateService::OTP_INVESTOR_LOGIN => 'Your :company Investors OTP is :code',
            SmsTemplateService::RESET_INVESTOR_PASSWORD => 'Your Tasneem Reset OTP is :code.',
        ]);

        $this->assertTrue($service->sendCode($account->fresh(), 'login'));
        $this->assertMatchesRegularExpression('/^Your '.preg_quote($company->name, '/').' Investors OTP is \d{6}$/', $texts[0]);

        $this->travel(2)->minutes();
        $this->assertTrue($service->sendCode($account->fresh(), 'reset'));
        $this->assertMatchesRegularExpression('/^Your Tasneem Reset OTP is \d{6}\.$/', $texts[1]);

        // Blank wording goes back to the built-in text.
        $templates->save($company->id, [SmsTemplateService::OTP_INVESTOR_LOGIN => '']);
        $this->travel(2)->minutes();
        $this->assertTrue($service->sendCode($account->fresh(), 'login'));
        $this->assertMatchesRegularExpression('/^Your '.preg_quote($company->name, '/').' Investment Portal OTP is \d{6}\.$/', $texts[2]);
    }

    public function test_sms_code_sign_in_is_not_offered_without_an_sms_gateway(): void
    {
        $company = $this->company('no-sms.example.test');
        $this->onPortal($company);

        Livewire::test(Login::class)->assertDontSee(__('Sign in with an SMS code instead'));
    }

    // ---- What an investor sees -------------------------------------------

    public function test_the_investor_sees_only_their_own_confirmed_figures(): void
    {
        $company = $this->company('figures.example.test');
        [$project, $investorA, $investorB, $settlement] = $this->settledProject($company);
        $accountA = $this->activeAccount($company, $investorA);
        $portal = app(InvestorPortalService::class);
        app(CompanyContext::class)->set($company);

        // A draft settlement is not the investor's result yet.
        $this->assertSame(0.0, $portal->investorSummary($accountA)['profit_pending']);
        $this->assertSame(0, $portal->payouts($accountA)->count());

        app(SettlementService::class)->confirmSettlement($settlement, $this->staff->id);

        $summary = $portal->investorSummary($accountA);
        $this->assertSame(24000.0, $summary['profit_pending']);
        $this->assertSame(0.0, $summary['capital_at_work']);
        $this->assertSame(60000.0, $summary['total_invested']);
        $this->assertSame([$investorA->id], $portal->payouts($accountA)->pluck('investor_id')->unique()->all());

        $statement = $portal->statement($accountA);
        $this->assertSame(['profit', 'deposit'], $statement->pluck('type')->all());
        $this->assertSame([24000.0, 60000.0], $statement->pluck('amount')->all());
    }

    public function test_every_portal_page_opens_for_an_investor_and_partner_pages_stay_hidden(): void
    {
        $company = $this->company('pages.example.test');
        [$project, $investorA] = $this->settledProject($company, confirm: true);
        $account = $this->activeAccount($company, $investorA);
        $this->actingAs($account, 'investor');

        foreach (['dashboard', 'investments', 'statement', 'payouts', 'documents', 'profile'] as $page) {
            $this->get('http://investor.pages.example.test/investor/'.$page)->assertOk();
        }

        $this->get('http://investor.pages.example.test/investor/my-investors')->assertForbidden();
        $this->get('http://investor.pages.example.test/investor/commissions')->assertForbidden();
        $this->get('http://investor.pages.example.test/investor/statement/print')->assertOk()->assertSee($investorA->name);
    }

    public function test_the_user_menu_name_links_to_the_profile_page(): void
    {
        $company = $this->company('menu.example.test');
        [$project, $investorA] = $this->settledProject($company, confirm: true);
        $account = $this->activeAccount($company, $investorA);
        $this->actingAs($account, 'investor');

        $this->get('http://investor.menu.example.test/investor/dashboard')
            ->assertOk()
            ->assertSee('http://investor.menu.example.test/investor/profile', false);
    }

    public function test_the_menu_has_the_main_pages_on_top_and_phones_get_a_bottom_menu(): void
    {
        $company = $this->company('groups.example.test');
        [$project, $investorA] = $this->settledProject($company, confirm: true);
        $this->actingAs($this->activeAccount($company, $investorA), 'investor');

        // IMS v2 look (IMS-V2/DESIGN.md §6.1): the main pages as top-bar pills, the rest under "More".
        $this->get('http://investor.groups.example.test/investor/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Home', 'Investments', 'Profit', 'Statement', 'More', 'Requests', 'Documents', 'Agreements', 'Profile'])
            ->assertSee('ims-rail', false)
            ->assertSee('fi-investor-mobile-nav', false)
            ->assertSee('Quick menu');
    }

    public function test_the_language_switch_turns_the_portal_bangla_and_remembers_it_on_the_account(): void
    {
        $company = $this->company('lang.example.test');
        [$project, $investorA] = $this->settledProject($company, confirm: true);
        $account = $this->activeAccount($company, $investorA);
        $this->actingAs($account, 'investor');
        $base = 'http://investor.lang.example.test/investor';

        $this->get($base.'/dashboard')->assertOk()->assertSee('Capital at work');

        $this->from($base.'/payouts')->get($base.'/language/bn')->assertRedirect($base.'/payouts');
        $this->assertSame('bn', $account->fresh()->locale);
        $this->get($base.'/dashboard')->assertOk()->assertSee('খাটছে এমন মূলধন');

        // A fresh browser of the same person keeps Bangla from the account.
        $this->flushSession();
        $this->actingAs($account->fresh(), 'investor');
        $this->get($base.'/dashboard')->assertOk()->assertSee('খাটছে এমন মূলধন');

        $this->get($base.'/language/fr')->assertNotFound();
        $this->from('https://elsewhere.example.test/')->get($base.'/language/en')->assertRedirect($base.'/dashboard');
        $this->assertSame('en', $account->fresh()->locale);
    }

    public function test_the_sign_in_page_has_the_language_switch_and_a_guest_choice_stays_in_the_browser(): void
    {
        $this->company('guest.example.test');
        $base = 'http://investor.guest.example.test/investor';

        $this->followingRedirects()->get($base)->assertOk()->assertSee($base.'/language/bn', false);
        $this->get($base.'/language/bn')->assertRedirect();
        $this->assertSame('bn', session('investor_portal_locale'));
    }

    public function test_another_investors_payout_report_and_documents_are_a_404(): void
    {
        $company = $this->company('scope.example.test');
        [$project, $investorA, $investorB, $settlement] = $this->settledProject($company, confirm: true);
        $accountA = $this->activeAccount($company, $investorA);

        Storage::fake('local');
        $directory = app(CompanyStorageService::class)->privateDirectory($company, 'investment-documents');
        Storage::disk('local')->put($directory.'/a.pdf', 'A');
        Storage::disk('local')->put($directory.'/b.pdf', 'B');
        Storage::disk('local')->put($directory.'/contract.pdf', 'C');
        Storage::disk('local')->put($directory.'/sheet.pdf', 'S');
        app(CompanyContext::class)->set($company);
        $own = $investorA->documents()->create(['company_id' => $company->id, 'category' => InvestmentDocument::CATEGORY_INVESTOR_NID, 'file_path' => $directory.'/a.pdf']);
        $other = $investorB->documents()->create(['company_id' => $company->id, 'category' => InvestmentDocument::CATEGORY_INVESTOR_NID, 'file_path' => $directory.'/b.pdf']);
        $contract = $project->documents()->create(['company_id' => $company->id, 'category' => InvestmentDocument::CATEGORY_PROJECT_CONTRACT, 'file_path' => $directory.'/contract.pdf']);
        $sheet = $project->documents()->create(['company_id' => $company->id, 'category' => 'settlement_sheet', 'file_path' => $directory.'/sheet.pdf']);

        $ownPayout = $settlement->payouts()->where('investor_id', $investorA->id)->value('id');
        $otherPayout = $settlement->payouts()->where('investor_id', $investorB->id)->value('id');

        $this->actingAs($accountA, 'investor');
        $base = 'http://investor.scope.example.test/investor';

        $this->get("{$base}/documents/{$own->id}/download")->assertOk();
        $this->get("{$base}/documents/{$contract->id}/download")->assertOk();
        $this->get("{$base}/documents/{$other->id}/download")->assertNotFound();
        $this->get("{$base}/documents/{$sheet->id}/download")->assertNotFound();

        $this->get("{$base}/payouts/{$ownPayout}/report")->assertOk()->assertSee($investorA->name);
        $this->get("{$base}/payouts/{$otherPayout}/report")->assertNotFound();

        // The download was attributed to the portal user, not to staff.
        $log = AuditLog::query()->withoutGlobalScopes()->where('action', 'investor_portal.document_downloaded')->firstOrFail();
        $this->assertNull($log->user_id);
        $this->assertSame($accountA->getMorphClass(), $log->actor_type);
        $this->assertSame($accountA->id, (int) $log->actor_id);
    }

    public function test_a_partner_sees_their_investors_by_display_name_but_no_contact_details(): void
    {
        $company = $this->company('partner.example.test');
        $partner = ChannelPartner::query()->create(['company_id' => $company->id, 'name' => 'Partner P', 'phone' => '01711000020', 'is_active' => true]);
        $investor = Investor::query()->create(['company_id' => $company->id, 'name' => 'Real Name', 'display_name' => 'Pseudonym X', 'phone' => '01711000021', 'channel_partner_id' => $partner->id]);
        $this->investor($company, 'Not Mine', '01711000022');
        $account = $this->activeAccount($company, $partner);

        $this->actingAs($account, 'investor');

        $this->get('http://investor.partner.example.test/investor/my-investors')
            ->assertOk()
            ->assertSee('Pseudonym X')
            ->assertDontSee('Real Name')
            ->assertDontSee('01711000021')
            ->assertDontSee('Not Mine');
        $this->get('http://investor.partner.example.test/investor/commissions')->assertOk();
        $this->get('http://investor.partner.example.test/investor/investments')->assertForbidden();
    }

    // ---- Admin side --------------------------------------------------------

    public function test_staff_turn_the_portal_on_from_the_settings_page_and_invite_from_the_investor_page(): void
    {
        $company = $this->company('admin.example.test', enabled: false, withSms: true);
        $investor = $this->investor($company, 'Invitee', '01711000030');
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->staff);

        Livewire::test(InvestorPortalSettings::class)
            ->set('data.investor_portal_enabled', true)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertTrue($company->fresh()->investor_portal_enabled);

        Livewire::test(ViewInvestor::class, ['record' => $investor->getKey()])
            ->callAction('portalInvite')
            ->assertHasNoErrors();

        $this->assertSame(1, InvestorPortalAccount::query()->withoutGlobalScopes()->where('investor_id', $investor->id)->count());
        $this->assertSame($this->staff->id, (int) AuditLog::query()->withoutGlobalScopes()->where('action', 'investor_portal.invited')->value('user_id'));
    }

    public function test_staff_set_a_portal_password_and_the_investor_signs_in_with_it(): void
    {
        $company = $this->company('setpw.example.test');
        $investor = $this->investor($company, 'No Sms', '01711000040');
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->staff);

        Livewire::test(ViewInvestor::class, ['record' => $investor->getKey()])
            ->callAction('portalSetPassword', ['password' => 'a-strong-password-1', 'password_confirmation' => 'a-strong-password-1'])
            ->assertHasNoErrors();

        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->where('investor_id', $investor->id)->firstOrFail();
        $this->assertSame(InvestorPortalAccount::STATUS_ACTIVE, $account->status);
        $this->assertNotNull(app(InvestorPortalAccountService::class)->attemptPasswordLogin('01711000040', 'a-strong-password-1'));
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()->where('action', 'investor_portal.password_set_by_staff')->count());

        // Not for a disabled account.
        app(InvestorPortalAccountService::class)->disable($account);
        Livewire::test(ViewInvestor::class, ['record' => $investor->getKey()])
            ->assertActionHidden('portalSetPassword');
    }

    public function test_the_login_page_has_a_forgot_password_link(): void
    {
        $company = $this->company('forgot.example.test');
        $this->onPortal($company);

        $this->get('http://investor.forgot.example.test/investor/login')
            ->assertOk()
            ->assertSee(__('Investor Dashboard'))
            ->assertSee(__('Forgot your password?'))
            ->assertSee('/investor/password-reset/request', false);
    }

    public function test_staff_without_the_portal_permission_cannot_open_the_settings_page(): void
    {
        $company = $this->company('no-permission.example.test');
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // An accountant has no investments.portal_manage (a manager now does, IMS-V2/ROLES.md §3.3).
        $this->actingAs(User::factory()->create(['role' => 'accountant']));

        $this->assertFalse(InvestorPortalSettings::canAccess());
    }

    // ---- Helpers ---------------------------------------------------------

    private function company(string $domain, bool $enabled = true, bool $withSms = false): Company
    {
        $company = Company::query()->create([
            'name' => 'Investor Co '.$domain,
            'slug' => Str::slug($domain),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(Str::random(4)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'investor_portal_enabled' => $enabled,
        ]);

        StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => $withSms ? [
                'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}',
                'sms_api_key' => 'key',
                'sms_sender_id' => 'INV',
            ] : [],
        ]);

        return $company;
    }

    private function onPortal(Company $company): void
    {
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('investor'));
    }

    private function investor(Company $company, string $name, string $phone): Investor
    {
        return Investor::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => $name, 'phone' => $phone]);
    }

    private function activeAccount(Company $company, Investor|ChannelPartner $holder): InvestorPortalAccount
    {
        $account = InvestorPortalAccount::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            $holder instanceof Investor ? 'investor_id' : 'channel_partner_id' => $holder->id,
            'name' => $holder->name,
            'phone' => Phone::normalize((string) $holder->phone),
            'status' => InvestorPortalAccount::STATUS_ACTIVE,
        ]);
        $account->forceFill(['password' => 'a-strong-password-1'])->save();

        return $account;
    }

    /** Two investors (60k / 40k) in a project settled at 100k net profit, as InvestmentSettlementTest. */
    private function settledProject(Company $company, bool $confirm = false): array
    {
        app(CompanyContext::class)->set($company);
        Auth::login($this->staff);

        $project = InvestmentProject::query()->create([
            'company_id' => $company->id,
            'name' => 'Portal Project',
            'duration_type' => 'custom_days',
            'trade_cycle_days' => 60,
            'start_date' => now()->toDateString(),
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'status' => 'closed',
        ]);
        $investorA = $this->investor($company, 'Investor Alpha', '01711000010');
        $investorB = $this->investor($company, 'Investor Beta', '01711000011');

        foreach ([[$investorA, 60000], [$investorB, 40000]] as [$investor, $amount]) {
            Investment::query()->create(['company_id' => $company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => $amount, 'payment_method' => 'bank', 'invested_at' => now()->toDateString(), 'received_by' => $this->staff->id]);
        }

        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Machine', 'amount' => 100000]);

        $settlement = app(SettlementService::class)->calculateAndSettle($project, 200000, $this->staff->id, acknowledgeUnprovenCosts: true);

        if ($confirm) {
            app(SettlementService::class)->confirmSettlement($settlement, $this->staff->id);
        }

        Auth::logout();
        app(CompanyContext::class)->clear();

        return [$project, $investorA, $investorB, $settlement->fresh()];
    }
}
