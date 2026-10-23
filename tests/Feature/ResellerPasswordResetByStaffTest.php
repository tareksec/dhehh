<?php

namespace Tests\Feature;

use App\Filament\Reseller\Pages\Auth\ResetPasswordByLink;
use App\Filament\Resources\Resellers\Pages\EditReseller;
use App\Filament\Resources\Resellers\Pages\ListResellers;
use App\Filament\Resources\Resellers\Pages\ViewReseller;
use App\Mail\ResellerPasswordResetLink;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\CustomerAccountService;
use App\Services\ResellerPasswordResetService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

/**
 * Owner request (2026-10-15): staff can reset a reseller's password from the
 * admin -- set a new one, or email the reseller a link (to the email the
 * reseller set) where they choose their own.
 */
class ResellerPasswordResetByStaffTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Company $other;

    protected Customer $reseller;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Reset Co', 'reset-co');
        $this->other = $this->makeCompany('Other Co', 'other-co');
        app(CompanyContext::class)->set($this->company);
        $this->reseller = $this->makeReseller($this->company, '01711000001');
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public function test_staff_set_a_new_password_and_only_the_new_one_works(): void
    {
        $this->asAdmin();
        $before = $this->reseller->remember_token;

        Livewire::test(ViewReseller::class, ['record' => $this->reseller->getKey()])
            ->callAction('setPassword', data: ['password' => 'N3w-Strong-Pass!', 'passwordConfirmation' => 'N3w-Strong-Pass!'])
            ->assertHasNoActionErrors()
            ->assertNotified('The password was changed. The reseller is signed out everywhere.');

        $reseller = $this->reseller->fresh();
        $this->assertTrue(Hash::check('N3w-Strong-Pass!', $reseller->password));
        $this->assertFalse(Hash::check('old-Passw0rd!xyz', $reseller->password));
        $this->assertNotSame($before, $reseller->remember_token); // signed out on every other device
        $this->assertNotNull(app(CustomerAccountService::class)->attemptLogin('01711000001', 'N3w-Strong-Pass!'));
        $this->assertNull(app(CustomerAccountService::class)->attemptLogin('01711000001', 'old-Passw0rd!xyz'));
    }

    public function test_the_audit_log_names_the_staff_member_and_never_holds_a_password(): void
    {
        $this->asAdmin();

        Livewire::test(ViewReseller::class, ['record' => $this->reseller->getKey()])
            ->callAction('setPassword', data: ['password' => 'N3w-Strong-Pass!', 'passwordConfirmation' => 'N3w-Strong-Pass!']);

        $log = AuditLog::query()->where('action', 'reseller.password_set_by_staff')->sole();
        $this->assertSame($this->reseller->getKey(), (int) $log->auditable_id);
        $this->assertSame($this->admin->getKey(), (int) $log->user_id);
        $this->assertSame($this->company->getKey(), (int) $log->company_id);

        foreach (AuditLog::query()->get() as $row) {
            $this->assertStringNotContainsString('N3w-Strong-Pass!', json_encode([$row->old_values, $row->new_values]));
            $this->assertStringNotContainsString((string) $this->reseller->fresh()->password, json_encode([$row->old_values, $row->new_values]));
        }
    }

    public function test_a_weak_or_mismatched_password_is_refused_and_nothing_changes(): void
    {
        $this->asAdmin();
        $hash = $this->reseller->password;

        foreach ([
            ['password' => 'short', 'passwordConfirmation' => 'short'],
            ['password' => 'N3w-Strong-Pass!', 'passwordConfirmation' => 'Different-Pass1!'],
            ['password' => '', 'passwordConfirmation' => ''],
        ] as $data) {
            Livewire::test(ViewReseller::class, ['record' => $this->reseller->getKey()])
                ->callAction('setPassword', data: $data)
                ->assertHasActionErrors(['password']);
        }

        $this->assertSame($hash, $this->reseller->fresh()->password);
        $this->assertSame(0, AuditLog::query()->where('action', 'reseller.password_set_by_staff')->count());
    }

    public function test_setting_a_password_also_cancels_a_pending_reset_link_or_code(): void
    {
        $accounts = app(CustomerAccountService::class);
        $token = $accounts->issueResetLink($this->reseller);
        $this->assertTrue($accounts->resetCodeIsValid($this->reseller->fresh(), $token));

        app(ResellerPasswordResetService::class)->setPassword($this->reseller->fresh(), 'N3w-Strong-Pass!', $this->admin);

        $this->assertFalse($accounts->resetCodeIsValid($this->reseller->fresh(), $token));
        $this->assertNull($this->reseller->fresh()->password_reset_code);
    }

    public function test_the_reset_link_is_emailed_to_the_resellers_own_address_on_the_portal_host(): void
    {
        Mail::fake();
        $this->asAdmin();

        Livewire::test(ViewReseller::class, ['record' => $this->reseller->getKey()])
            ->callAction('emailResetLink')
            ->assertNotified('A reset link was emailed to shop@example.test.');

        Mail::assertSent(ResellerPasswordResetLink::class, 1);
        Mail::assertSent(ResellerPasswordResetLink::class, function (ResellerPasswordResetLink $mail): bool {
            $url = parse_url($mail->url);

            return $mail->hasTo('shop@example.test')
                && $mail->companyName === 'Reset Co'
                && $mail->minutes === 60
                && $url['host'] === 'reseller.reset-co.example.test'
                && $url['path'] === '/portal/password-reset/reset'
                && URL::hasValidSignature(Request::create($mail->url));
        });

        $this->assertNotNull($this->reseller->fresh()->password_reset_code);
        $this->assertTrue($this->reseller->fresh()->password_reset_expires_at->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertSame(1, AuditLog::query()->where('action', 'reseller.password_reset_link_sent')->count());
    }

    public function test_the_email_never_carries_a_password(): void
    {
        Mail::fake();

        app(ResellerPasswordResetService::class)->sendLink($this->reseller, $this->admin);

        Mail::assertSent(ResellerPasswordResetLink::class, function (ResellerPasswordResetLink $mail): bool {
            $text = $mail->render();

            return str_contains($text, $mail->url) && ! str_contains(strtolower($text), 'old-passw0rd');
        });
    }

    public function test_a_reseller_without_an_email_cannot_be_sent_a_link(): void
    {
        Mail::fake();
        $this->asAdmin();
        $this->reseller->forceFill(['email' => null])->save();

        Livewire::test(ViewReseller::class, ['record' => $this->reseller->getKey()])
            ->assertActionDisabled('emailResetLink');

        $this->assertSame(
            ResellerPasswordResetService::NO_EMAIL,
            app(ResellerPasswordResetService::class)->sendLink($this->reseller->fresh(), $this->admin),
        );
        Mail::assertNothingSent();
        $this->assertNull($this->reseller->fresh()->password_reset_code);
    }

    public function test_without_working_email_for_the_company_nothing_is_sent_and_no_link_is_left_valid(): void
    {
        Mail::fake();
        $this->asAdmin();
        // No SMTP of its own, and the server's default mailer only writes messages down.
        $this->updateCredentials($this->company, null);

        Livewire::test(ViewReseller::class, ['record' => $this->reseller->getKey()])
            ->callAction('emailResetLink')
            ->assertNotified('Email is not set up for this company yet (Settings → Integrations → Email Setup), so no link can be sent. Set a new password instead.');

        Mail::assertNothingSent();
        $this->assertNull($this->reseller->fresh()->password_reset_code);

        // The email log says why nothing went out.
        $log = \App\Models\EmailLog::query()->sole();
        $this->assertSame('reseller_password_reset', $log->kind);
        $this->assertSame('skipped', $log->status);
        $this->assertSame('not_configured', $log->reason);
    }

    public function test_a_send_that_fails_leaves_no_working_link(): void
    {
        $this->asAdmin();
        // A mail server that cannot be reached.
        $this->updateCredentials($this->company, ['mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_username' => 'x', 'mail_password' => 'x', 'mail_encryption' => '']);

        $status = app(ResellerPasswordResetService::class)->sendLink($this->reseller->fresh(), $this->admin);

        $this->assertSame(ResellerPasswordResetService::SEND_FAILED, $status);
        $this->assertNull($this->reseller->fresh()->password_reset_code);
        $this->assertSame(0, AuditLog::query()->where('action', 'reseller.password_reset_link_sent')->count());
    }

    public function test_the_link_lets_the_reseller_choose_a_new_password_once(): void
    {
        $url = $this->sentLink();
        $this->onPortal($this->company);

        // The signed link opens the portal's own page on the portal host.
        $this->get($url)->assertOk()->assertSee('Choose a new password');

        $params = $this->linkQuery($url);
        Livewire::withQueryParams($params)
            ->test(ResetPasswordByLink::class)
            ->assertNoRedirect()
            ->fillForm(['password' => 'Chosen-By-Reseller1!', 'passwordConfirmation' => 'Chosen-By-Reseller1!'])
            ->call('resetPassword')
            ->assertHasNoFormErrors()
            ->assertNotified('Your password was changed. Sign in with the new one.');

        $reseller = $this->reseller->fresh();
        $this->assertTrue(Hash::check('Chosen-By-Reseller1!', $reseller->password));
        $this->assertNull($reseller->password_reset_code);

        // The same link does nothing a second time.
        Livewire::withQueryParams($params)
            ->test(ResetPasswordByLink::class)
            ->assertRedirect();
        $this->assertTrue(Hash::check('Chosen-By-Reseller1!', $this->reseller->fresh()->password));
    }

    public function test_the_emailed_link_works_through_the_real_portal_livewire_endpoint(): void
    {
        $url = $this->sentLink();

        $page = $this->get($url)->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $this->assertNotEmpty($matches, 'The reset page did not render a Livewire component.');

        $this->withSession(['_token' => 'tok'])
            ->withHeaders(['X-Livewire' => 'true', 'X-CSRF-TOKEN' => 'tok'])
            ->postJson('https://reseller.reset-co.example.test'.EndpointResolver::updatePath(), [
                '_token' => 'tok',
                'components' => [[
                    'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                    'updates' => ['password' => 'Chosen-By-Reseller1!', 'passwordConfirmation' => 'Chosen-By-Reseller1!'],
                    'calls' => [['path' => '', 'method' => 'resetPassword', 'params' => []]],
                ]],
            ])->assertOk();

        $this->assertTrue(Hash::check('Chosen-By-Reseller1!', $this->reseller->fresh()->password), 'The password was not changed: the company was not resolved on the update request.');
        $this->assertNull($this->reseller->fresh()->password_reset_code);
    }

    public function test_a_weak_password_on_the_link_page_is_refused_and_the_link_stays_usable(): void
    {
        $url = $this->sentLink();
        $this->onPortal($this->company);
        $params = $this->linkQuery($url);

        Livewire::withQueryParams($params)
            ->test(ResetPasswordByLink::class)
            ->fillForm(['password' => 'short', 'passwordConfirmation' => 'short'])
            ->call('resetPassword')
            ->assertHasFormErrors(['password']);

        $this->assertNotNull($this->reseller->fresh()->password_reset_code);
        $this->assertTrue(Hash::check('old-Passw0rd!xyz', $this->reseller->fresh()->password));
    }

    public function test_a_tampered_or_expired_link_is_refused(): void
    {
        $url = $this->sentLink();

        $this->get($url.'x')->assertForbidden(); // signature broken
        $this->get(str_replace('customer='.$this->reseller->getKey(), 'customer='.($this->reseller->getKey() + 1), $url))->assertForbidden();

        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden(); // signature expired
        $this->assertFalse(app(CustomerAccountService::class)->resetCodeIsValid($this->reseller->fresh(), $this->linkQuery($url)['token']));
    }

    public function test_a_link_does_not_work_on_another_companys_portal(): void
    {
        $url = $this->sentLink();
        $otherHostUrl = str_replace('reseller.reset-co.example.test', 'reseller.other-co.example.test', $url);

        $this->get($otherHostUrl)->assertForbidden(); // the signature covers the host

        // Even past the signature, the other company cannot see this reseller.
        $this->onPortal($this->other);
        Livewire::withQueryParams($this->linkQuery($url))
            ->test(ResetPasswordByLink::class)
            ->assertRedirect();
        $this->assertTrue(Hash::check('old-Passw0rd!xyz', $this->reseller->fresh()->password));
    }

    public function test_a_wrong_token_in_a_valid_looking_link_changes_nothing(): void
    {
        $this->sentLink();
        $this->onPortal($this->company);

        Livewire::withQueryParams(['customer' => $this->reseller->getKey(), 'token' => 'not-the-token'])
            ->test(ResetPasswordByLink::class)
            ->assertRedirect();

        $this->assertTrue(Hash::check('old-Passw0rd!xyz', $this->reseller->fresh()->password));
    }

    public function test_a_rejected_reseller_gets_no_actions_and_no_link(): void
    {
        Mail::fake();
        $this->asAdmin();
        $rejected = $this->makeReseller($this->company, '01711000002', 'rejected');

        Livewire::test(ViewReseller::class, ['record' => $rejected->getKey()])
            ->assertActionHidden('setPassword')
            ->assertActionHidden('emailResetLink');

        $this->assertSame(ResellerPasswordResetService::NOT_A_RESELLER, app(ResellerPasswordResetService::class)->sendLink($rejected, $this->admin));
        Mail::assertNothingSent();
    }

    public function test_the_actions_are_on_the_list_and_the_edit_page_too(): void
    {
        $this->asAdmin();

        Livewire::test(ListResellers::class)
            ->assertTableActionVisible('setPassword', $this->reseller)
            ->assertTableActionVisible('emailResetLink', $this->reseller);

        Livewire::test(EditReseller::class, ['record' => $this->reseller->getKey()])
            ->assertActionVisible('setPassword')
            ->assertActionVisible('emailResetLink');
    }

    public function test_staff_who_may_not_edit_customers_do_not_see_the_actions_and_cannot_force_them(): void
    {
        UserRole::query()->create(['name' => 'Sales viewer', 'slug' => 'sales-viewer', 'permissions' => ['sales.view'], 'is_active' => true]);
        $viewer = User::factory()->create(['role' => 'sales-viewer', 'is_active' => true]);
        $this->actingAs($viewer)->withSession(['current_company_id' => $this->company->getKey()]);

        Livewire::test(ViewReseller::class, ['record' => $this->reseller->getKey()])
            ->assertActionHidden('setPassword')
            ->assertActionHidden('emailResetLink');

        UserRole::query()->create(['name' => 'Sales manager', 'slug' => 'sales-manager', 'permissions' => ['sales.view', 'sales.update'], 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'sales-manager', 'is_active' => true]);
        $this->actingAs($manager)->withSession(['current_company_id' => $this->company->getKey()]);

        Livewire::test(ViewReseller::class, ['record' => $this->reseller->getKey()])
            ->assertActionVisible('setPassword')
            ->assertActionVisible('emailResetLink');
    }

    public function test_a_reseller_of_another_company_cannot_be_reset_from_this_company(): void
    {
        $this->asAdmin();
        $foreign = $this->makeReseller($this->other, '01799000001');

        // The admin only ever loads this company's resellers.
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ViewReseller::class, ['record' => $foreign->getKey()]);
    }

    /** Sends the link as staff would, and returns the URL the reseller gets. */
    protected function sentLink(): string
    {
        Mail::fake();
        $this->assertSame(ResellerPasswordResetService::SENT, app(ResellerPasswordResetService::class)->sendLink($this->reseller, $this->admin));

        $url = null;
        Mail::assertSent(ResellerPasswordResetLink::class, function (ResellerPasswordResetLink $mail) use (&$url): bool {
            $url = $mail->url;

            return true;
        });

        return (string) $url;
    }

    /** @return array<string, string> */
    protected function linkQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return $params;
    }

    /** @param  array<string, mixed>|null  $credentials */
    protected function updateCredentials(Company $company, ?array $credentials): void
    {
        $setting = StorefrontSetting::withoutGlobalScopes()->where('company_id', $company->getKey())->firstOrFail();
        $setting->notification_credentials = $credentials;
        $setting->save();
    }

    protected function asAdmin(): void
    {
        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->getKey()]);
        app(CompanyContext::class)->set($this->company);
    }

    protected function onPortal(Company $company): void
    {
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('reseller'));
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        $company = Company::query()->create([
            'name' => $name, 'slug' => $slug, 'domain' => $slug.'.example.test', 'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)), 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka',
            'is_active' => true, 'reseller_module_enabled' => true,
        ])->fresh();
        StorefrontSetting::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(), 'theme_color' => '#0F766E', 'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
            'notification_credentials' => ['mail_host' => 'smtp.example.test', 'mail_port' => 587, 'mail_username' => 'sender@example.test', 'mail_password' => 'secret'],
        ]);

        return $company;
    }

    protected function makeReseller(Company $company, string $phone, string $status = 'approved'): Customer
    {
        $customer = new Customer([
            'name' => 'Reseller '.$phone, 'phone' => $phone, 'email' => $phone === '01711000001' ? 'shop@example.test' : $phone.'@example.test',
            'reseller_status' => $status, 'reseller_slug' => 'r-'.substr($phone, -4), 'business_name' => 'Shop '.$phone, 'is_active' => true,
        ]);
        $customer->company_id = $company->getKey();
        $customer->forceFill(['password' => Hash::make('old-Passw0rd!xyz'), 'remember_token' => 'before-token'])->save();

        return $customer;
    }
}
