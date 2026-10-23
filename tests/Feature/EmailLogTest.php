<?php

namespace Tests\Feature;

use App\Filament\Pages\Integrations;
use App\Filament\Resources\EmailLogs\EmailLogResource;
use App\Filament\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Ims\Onboarding\OtpService;
use App\Livewire\LogTable;
use App\Mail\ImsMessage;
use App\Models\Company;
use App\Models\EmailLog;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\EmailLogService;
use App\Services\StorefrontNotificationService;
use App\Support\LogCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The email log (owner request 2026-10-23): was the email sent, did it fail, and if it did not go, why.
 * One row per email with who it went to, the kind of message and the mail server's own answer, never the
 * text of the message.
 */
class EmailLogTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Mail Log Co', 'slug' => 'mail-log-co', 'invoice_prefix' => 'MLC', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        Auth::login(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
    }

    public function test_an_accepted_email_is_logged_as_sent_through_the_sites_smtp_and_never_keeps_the_code(): void
    {
        Mail::fake();
        $setting = $this->site(['mail_host' => 'email-smtp.ap-southeast-1.amazonaws.com', 'mail_username' => 'AKIAEXAMPLEKEY', 'mail_from_address' => 'hello@shop.test']);

        $this->assertTrue(app(StorefrontNotificationService::class)->sendLoginOtpEmail($setting, 'buyer@example.test', 'Mail Log Co', '482915'));

        $log = EmailLog::query()->sole();
        $this->assertSame($this->company->getKey(), $log->company_id);
        $this->assertSame(EmailLog::KIND_LOGIN_CODE, $log->kind);
        $this->assertSame('buyer@example.test', $log->recipient);
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertSame('smtp:email-smtp.ap-southeast-1.amazonaws.com', $log->via);
        $this->assertNull($log->reason);
        $this->assertStringNotContainsString('482915', json_encode($log->getAttributes()), 'A login code must never be stored in the log.');
    }

    public function test_a_failed_send_is_logged_with_the_mail_servers_answer_and_a_reason(): void
    {
        $setting = $this->site(['mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_username' => 'AKIAEXAMPLEKEY', 'mail_password' => 'x', 'mail_from_address' => 'hello@shop.test']);

        $sent = app(StorefrontNotificationService::class)->sendMailable($setting, 'investor@example.test', new ImsMessage('Mail Log Co', 'Hello', ['Line']), EmailLog::KIND_IMS_INVITATION);

        $this->assertFalse($sent);
        $log = EmailLog::query()->sole();
        $this->assertSame(EmailLog::KIND_IMS_INVITATION, $log->kind);
        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertSame(EmailLogService::REASON_UNREACHABLE, $log->reason);
        $this->assertNotEmpty($log->detail);
    }

    public function test_a_send_through_a_server_mailer_that_only_logs_is_not_called_sent(): void
    {
        // phpunit uses the "array" mailer, which only keeps the message in memory; the site has no SMTP of its own.
        $setting = $this->site([]);

        $this->assertTrue(app(StorefrontNotificationService::class)->sendLoginOtpEmail($setting, 'buyer@example.test', 'Mail Log Co', '111222'));

        $log = EmailLog::query()->sole();
        $this->assertSame(EmailLog::STATUS_SKIPPED, $log->status);
        $this->assertSame(EmailLogService::REASON_LOG_ONLY, $log->reason);
        $this->assertSame('server:array', $log->via);
    }

    public function test_the_test_email_button_leaves_a_log_row_for_success_and_failure(): void
    {
        $setting = $this->site(['mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_username' => 'AKIAEXAMPLEKEY', 'mail_password' => 'x', 'mail_from_address' => 'hello@shop.test']);
        $service = app(StorefrontNotificationService::class);

        $this->assertNotNull($service->sendTestEmail($setting, 'me@shop.test'));
        Mail::fake();
        $this->assertNull($service->sendTestEmail($setting, 'me@shop.test'));

        $this->assertSame(
            [EmailLog::STATUS_FAILED, EmailLog::STATUS_SENT],
            EmailLog::query()->where('kind', EmailLog::KIND_TEST)->orderBy('id')->pluck('status')->all(),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function serverAnswers(): array
    {
        return [
            'wrong smtp password' => ['Expected response code "235" but got code "535", with message "535 Authentication Credentials Invalid".', EmailLogService::REASON_AUTH_FAILED],
            'unverified sender or sandbox recipient' => ['Expected response code "250" but got code "554", with message "554 Message rejected: Email address is not verified. The following identities failed the check in region AP-SOUTHEAST-1: a@b.test".', EmailLogService::REASON_ADDRESS_NOT_VERIFIED],
            'sending rate' => ['Expected response code "250" but got code "454", with message "454 Throttling failure: Maximum sending rate exceeded.".', EmailLogService::REASON_LIMIT_REACHED],
            'daily quota' => ['Expected response code "250" but got code "554", with message "554 Daily message quota exceeded".', EmailLogService::REASON_LIMIT_REACHED],
            'cannot connect' => ['Connection could not be established with host "email-smtp.ap-southeast-1.amazonaws.com:587": stream_socket_client(): Unable to connect', EmailLogService::REASON_UNREACHABLE],
            'timeout' => ['Connection to "email-smtp.ap-south-1.amazonaws.com:587" timed out.', EmailLogService::REASON_UNREACHABLE],
            'bad address' => ['Email "not-an-email" does not comply with addressing specification.', EmailLogService::REASON_INVALID_ADDRESS],
            'anything else' => ['Expected response code "250" but got code "550", with message "550 mailbox unavailable".', EmailLogService::REASON_SEND_ERROR],
        ];
    }

    #[DataProvider('serverAnswers')]
    public function test_the_mail_servers_answer_is_turned_into_a_plain_reason(string $answer, string $reason): void
    {
        $this->assertSame($reason, app(EmailLogService::class)->classify($answer));
    }

    public function test_a_logging_failure_never_stops_the_email(): void
    {
        Mail::fake();
        $setting = $this->site(['mail_host' => 'smtp.example.test', 'mail_username' => 'AKIAEXAMPLEKEY']);

        Schema::drop('email_logs');

        $this->assertTrue(app(StorefrontNotificationService::class)->sendLoginOtpEmail($setting, 'buyer@example.test', 'Mail Log Co', '123456'));
    }

    public function test_the_log_page_lists_the_result_and_why_and_other_companies_never_see_it(): void
    {
        app(EmailLogService::class)->sent($this->site(['mail_host' => 'smtp.example.test']), EmailLog::KIND_OWNER_DIGEST, 'owner@example.test', 'smtp:smtp.example.test');
        app(EmailLogService::class)->failed($this->site(['mail_host' => 'smtp.example.test']), EmailLog::KIND_LOGIN_CODE, 'buyer@example.test', 'smtp:smtp.example.test', '554 Message rejected: Email address is not verified.');
        app(EmailLogService::class)->skipped((int) $this->company->getKey(), EmailLog::KIND_RESELLER_PASSWORD_RESET, 'reseller@example.test', EmailLogService::REASON_NOT_CONFIGURED);

        Livewire::test(ListEmailLogs::class)
            ->assertSee('Email Log')
            ->assertSee('bounces and spam-folder delivery are not tracked yet')
            ->assertSee('owner@example.test')
            ->assertSee('Owner daily digest')
            ->assertSee('buyer@example.test')
            ->assertSee('an email address is not verified')
            ->assertSee('554 Message rejected: Email address is not verified.')
            ->assertSee('SMTP smtp.example.test')
            ->assertSee('reseller@example.test')
            ->assertSee('No email account is set up for this site');

        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        app(CompanyContext::class)->set($other);

        $this->assertSame(0, EmailLog::query()->count());
        Livewire::test(ListEmailLogs::class)->assertDontSee('owner@example.test');
    }

    public function test_the_integrations_test_email_action_shows_up_in_the_log(): void
    {
        $this->site(['mail_host' => 'smtp.example.test', 'mail_username' => 'AKIAEXAMPLEKEY', 'mail_from_address' => 'hello@shop.test']);
        Mail::fake();

        Livewire::test(Integrations::class)
            ->callAction('testEmail', data: ['email' => 'me@shop.test'])
            ->assertNotified('Test email sent');

        $this->assertSame('me@shop.test', EmailLog::query()->where('kind', EmailLog::KIND_TEST)->sole()->recipient);
    }

    public function test_an_investor_code_that_has_no_email_account_to_go_through_is_logged_as_not_sent(): void
    {
        // No site, hence no email account, and no code in the log.
        $this->assertFalse(app(OtpService::class)->send($this->company, 'investor@example.test', 'email'));

        $log = EmailLog::query()->sole();
        $this->assertSame(EmailLog::KIND_IMS_CODE, $log->kind);
        $this->assertSame(EmailLog::STATUS_SKIPPED, $log->status);
        $this->assertSame(EmailLogService::REASON_NOT_CONFIGURED, $log->reason);
        $this->assertSame('investor@example.test', $log->recipient);
    }

    public function test_an_investor_code_by_email_is_logged_as_sent_without_the_code(): void
    {
        Mail::fake();
        $this->site(['mail_host' => 'smtp.example.test', 'mail_username' => 'AKIAEXAMPLEKEY', 'mail_from_address' => 'hello@shop.test']);

        $this->assertTrue(app(OtpService::class)->send($this->company, 'investor@example.test', 'email'));

        $log = EmailLog::query()->sole();
        $this->assertSame(EmailLog::KIND_IMS_CODE, $log->kind);
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertNull($log->detail);
        $this->assertDoesNotMatchRegularExpression('/\d{6}/', json_encode($log->getAttributes()), 'No code may be stored in the log.');
    }

    public function test_the_email_log_is_a_tab_on_the_logs_page_and_not_a_sidebar_entry(): void
    {
        $this->assertArrayHasKey('email', LogCatalog::visible());
        $this->assertFalse(EmailLogResource::shouldRegisterNavigation());

        app(EmailLogService::class)->failed($this->site(['mail_host' => 'smtp.example.test']), EmailLog::KIND_LOGIN_CODE, 'buyer@example.test', 'smtp:smtp.example.test', '535 Authentication Credentials Invalid');

        Livewire::test(LogTable::class, ['log' => 'email'])
            ->assertOk()
            ->assertSee('buyer@example.test')
            ->assertSee('refused the SMTP user name or password')
            ->assertSee('bounces and spam-folder delivery are not tracked yet');
    }

    public function test_the_email_tab_is_not_shown_to_staff_without_settings_access(): void
    {
        Auth::login(User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));

        $this->assertArrayNotHasKey('email', LogCatalog::visible());
        Livewire::test(LogTable::class, ['log' => 'email'])->assertForbidden();
    }

    public function test_the_phone_layout_of_an_email_row_escapes_what_the_mail_server_said(): void
    {
        $row = new EmailLog([
            'kind' => EmailLog::KIND_LOGIN_CODE, 'status' => EmailLog::STATUS_FAILED, 'recipient' => 'buyer@example.test',
            'reason' => EmailLogService::REASON_SEND_ERROR, 'detail' => '<script>alert(1)</script>',
        ]);

        $html = EmailLogResource::summaryHtml($row);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('buyer@example.test', $html);
        $this->assertStringContainsString('Failed', $html);
    }

    public function test_the_log_page_opens_from_settings_for_a_super_admin(): void
    {
        $this->actingAs(Auth::user())
            ->withSession(['current_company_id' => $this->company->getKey(), 'current_company_selection_explicit' => true])
            ->get('/admin/settings/email-logs')
            ->assertOk()
            ->assertSee('Email Log');
    }

    /** @param  array<string, mixed>  $credentials */
    private function site(array $credentials): StorefrontSetting
    {
        return StorefrontSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_BLADE],
            ['notification_credentials' => $credentials],
        );
    }
}
