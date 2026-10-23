<?php

namespace Tests\Feature;

use App\Filament\Pages\Integrations;
use App\Filament\Pages\SmsDashboard;
use App\Models\Company;
use App\Models\SmsLog;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\SmsBalanceService;
use App\Services\SmsUsageService;
use App\Services\StorefrontNotificationService;
use App\Support\SmsSegments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The SMS dashboard (owner request 2026-10-23): how many SMS went, how many the
 * gateway refused, what that cost and what is left on the BulkSMSBD account,
 * so nobody has to log in to BulkSMSBD to see them.
 */
class SmsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private StorefrontSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Dash Co', 'slug' => 'dash-co', 'invoice_prefix' => 'DSH', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true,
        ]);
        $this->setting = StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => [
                'sms_api_url' => 'http://bulksmsbd.net/api/smsapi?api_key={api_key}&type=text&number={phone}&senderid={sender_id}&message={message}',
                'sms_api_key' => 'real-key',
                'sms_sender_id' => '8809617643387',
                'sms_price_per_sms' => '0.5',
                'sms_low_balance_alert' => '100',
            ],
        ]);
        app(CompanyContext::class)->set($this->company);
        Cache::flush();
    }

    public function test_sms_parts_follow_the_gsm_and_unicode_limits(): void
    {
        $this->assertSame(0, SmsSegments::count(''));
        $this->assertSame(1, SmsSegments::count(str_repeat('a', 160)));
        $this->assertSame(2, SmsSegments::count(str_repeat('a', 161)));
        $this->assertSame(2, SmsSegments::count(str_repeat('a', 306)));
        $this->assertSame(3, SmsSegments::count(str_repeat('a', 307)));
        // A character outside the GSM alphabet (Bangla here) makes the whole text Unicode: 70, then 67 a part.
        $this->assertSame(1, SmsSegments::count(str_repeat('অ', 70)));
        $this->assertSame(2, SmsSegments::count(str_repeat('অ', 71)));
        $this->assertSame(3, SmsSegments::count(str_repeat('অ', 135)));
        $this->assertSame(2, SmsSegments::count('Your order আপনার '.str_repeat('x', 60)));
        // "{" and "[" take two characters each in the GSM alphabet.
        $this->assertSame(2, SmsSegments::count(str_repeat('{', 81)));
    }

    public function test_a_login_code_or_reminder_is_counted_with_its_parts_but_never_stored_as_text(): void
    {
        Http::fake(['bulksmsbd.net/*' => Http::response('{"response_code":202,"success_message":"SMS Submitted Successfully"}')]);

        $this->assertTrue(app(StorefrontNotificationService::class)->sendSms($this->setting, '01712345678', 'Your code is 482913'));

        $row = SmsLog::query()->sole();
        $this->assertSame(SmsLog::KIND_OTHER, $row->kind);
        $this->assertSame(SmsLog::STATUS_SENT, $row->status);
        $this->assertSame('01712345678', $row->phone);
        $this->assertSame(1, $row->segments);
        $this->assertNull($row->message, 'A code message must never be written to the log.');
        $this->assertStringNotContainsString('482913', json_encode($row->getAttributes()));
    }

    public function test_a_refused_code_message_is_counted_as_refused_with_the_gateways_reason(): void
    {
        Http::fake(['bulksmsbd.net/*' => Http::response('{"response_code":1007,"error_message":"Balance Insufficient"}')]);

        $this->assertFalse(app(StorefrontNotificationService::class)->sendSms($this->setting, '01712345678', 'Your code is 111222'));

        $row = SmsLog::query()->sole();
        $this->assertSame(SmsLog::STATUS_FAILED, $row->status);
        $this->assertSame('gateway_refused', $row->reason);
        $this->assertSame(1007, $row->gateway_code);
    }

    public function test_a_site_with_no_gateway_sends_and_counts_nothing_and_detailed_sends_are_left_to_their_caller(): void
    {
        Http::fake(['bulksmsbd.net/*' => Http::response('{"response_code":202}')]);
        $notifications = app(StorefrontNotificationService::class);

        $this->setting->update(['notification_credentials' => []]);
        $this->assertFalse($notifications->sendSms($this->setting->fresh(), '01712345678', 'hello'));
        $this->assertSame(0, SmsLog::query()->count());

        // Order SMS and the test SMS write their own row, so the detailed send must not add a second one.
        $this->setting->update(['notification_credentials' => ['sms_api_url' => 'http://bulksmsbd.net/api/smsapi?api_key={api_key}&number={phone}&message={message}', 'sms_api_key' => 'k']]);
        $result = $notifications->sendSmsDetailed($this->setting->fresh(), '01712345678', str_repeat('অ', 80));
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['segments']);
        $this->assertSame(0, SmsLog::query()->count());
    }

    public function test_the_balance_reply_is_read_in_the_usual_shapes_and_unknown_replies_are_shown_as_they_came(): void
    {
        $balances = app(SmsBalanceService::class);

        $this->assertSame(1234.5, $balances->parse('{"response_code":202,"balance":"1,234.50"}')['balance']);
        $this->assertSame(88.0, $balances->parse('{"data":{"Balance":88}}')['balance']);
        $this->assertSame(42.25, $balances->parse('42.25')['balance']);

        $refused = $balances->parse('{"response_code":1011,"error_message":"user id not found in this x key"}');
        $this->assertFalse($refused['ok']);
        $this->assertSame(1011, $refused['code']);
        $this->assertStringContainsString('User Id not found', (string) $refused['message']);
        $this->assertStringContainsString('API key', (string) $refused['message']);

        $odd = $balances->parse('<html>maintenance</html>');
        $this->assertFalse($odd['ok']);
        $this->assertSame('<html>maintenance</html>', $odd['raw']);
    }

    public function test_the_balance_is_asked_of_the_gateways_own_host_with_the_saved_key_and_kept_for_a_few_minutes(): void
    {
        Http::fake(['bulksmsbd.net/api/getBalanceApi*' => Http::response('{"response_code":202,"balance":"250.00"}')]);
        $balances = app(SmsBalanceService::class);

        $this->assertTrue($balances->supported($this->setting));
        $this->assertSame('https://bulksmsbd.net', $balances->rechargeUrl($this->setting));
        $this->assertSame(250.0, $balances->balance($this->setting)['balance']);
        $this->assertSame(250.0, $balances->balance($this->setting)['balance']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://bulksmsbd.net/api/getBalanceApi') && str_contains($request->url(), 'api_key=real-key'));

        $balances->balance($this->setting, fresh: true);
        Http::assertSentCount(2);
    }

    public function test_another_gateway_or_an_empty_key_is_explained_instead_of_calling_anyone(): void
    {
        Http::fake();
        $balances = app(SmsBalanceService::class);

        $other = StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'site_type' => StorefrontSetting::SITE_NEXTJS,
            'notification_credentials' => ['sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}', 'sms_api_key' => 'k'],
        ]);
        $this->assertFalse($balances->supported($other));
        $this->assertFalse($balances->balance($other)['ok']);

        $this->setting->update(['notification_credentials' => [...$this->setting->notification_credentials, 'sms_api_key' => '']]);
        $this->assertStringContainsString('API key is empty', (string) $balances->balance($this->setting->fresh())['message']);

        Http::assertNothingSent();
    }

    public function test_a_connection_error_never_puts_the_api_key_in_the_log_detail_or_on_the_page(): void
    {
        $error = 'cURL error 35: Connection reset for http://bulksmsbd.net/api/smsapi?api_key=real-key&number=017&message=hello';
        Http::fake(fn () => throw new ConnectionException($error));

        $result = app(StorefrontNotificationService::class)->sendSmsDetailed($this->setting, '01712345678', 'hello');
        $this->assertSame('gateway_unreachable', $result['reason']);
        $this->assertStringNotContainsString('real-key', (string) $result['detail']);
        $this->assertStringContainsString('[gateway URL]', (string) $result['detail']);

        $balance = app(SmsBalanceService::class)->balance($this->setting, fresh: true);
        $this->assertFalse($balance['ok']);
        $this->assertStringNotContainsString('real-key', (string) $balance['message']);
    }

    public function test_the_summary_adds_up_counts_parts_and_cost_for_this_company_only(): void
    {
        $this->row(SmsLog::KIND_ORDER_PLACED, SmsLog::STATUS_SENT, 2);
        $this->row(SmsLog::KIND_OTHER, SmsLog::STATUS_SENT, 1);
        $this->row(SmsLog::KIND_OTHER, SmsLog::STATUS_SENT, 1);
        $this->row(SmsLog::KIND_OTHER, SmsLog::STATUS_FAILED, 1);
        $this->row(SmsLog::KIND_ORDER_STATUS, SmsLog::STATUS_SKIPPED, null);
        $this->row(SmsLog::KIND_ORDER_STATUS, SmsLog::STATUS_QUEUED, null);
        $this->row(SmsLog::KIND_OTHER, SmsLog::STATUS_SENT, 1, createdAt: now()->subMonths(2));

        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        SmsLog::withoutGlobalScopes()->create(['company_id' => $other->id, 'kind' => SmsLog::KIND_OTHER, 'status' => SmsLog::STATUS_SENT, 'segments' => 9]);

        $summary = app(SmsUsageService::class)->summary(now()->startOfMonth(), now(), 0.5);

        $this->assertSame(['sent' => 3, 'failed' => 1, 'skipped' => 1, 'queued' => 1], $summary['counts']);
        $this->assertSame(4, $summary['parts']);
        $this->assertSame(2.0, $summary['cost']);
        $this->assertSame(['sent' => 2, 'failed' => 1, 'skipped' => 0, 'queued' => 0, 'parts' => 2], $summary['by_kind'][SmsLog::KIND_OTHER]);
        $this->assertSame(['sent' => 3, 'failed' => 1], $summary['by_day'][now()->toDateString()]);
        $this->assertNull(app(SmsUsageService::class)->summary(now()->startOfMonth(), now())['cost'], 'No price saved means no cost is claimed.');
    }

    public function test_the_page_shows_the_numbers_the_balance_and_the_recharge_link(): void
    {
        Http::fake(['bulksmsbd.net/api/getBalanceApi*' => Http::response('{"response_code":202,"balance":"60.00"}')]);
        $this->row(SmsLog::KIND_ORDER_PLACED, SmsLog::STATUS_SENT, 2);
        $this->row(SmsLog::KIND_OTHER, SmsLog::STATUS_FAILED, 1);
        $this->actingAs($this->admin());

        Livewire::test(SmsDashboard::class)
            ->call('loadBalance')
            ->assertSee('60')
            ->assertSee(__('The balance is below your alert level of :amount. Recharge soon so messages keep going out.', ['amount' => '৳ 100']))
            ->assertSee('https://bulksmsbd.net')
            ->assertSee(__('Recharge on BulkSMSBD'))
            ->assertSee('৳ 1')
            ->assertSee(__('Refused by the gateway'))
            ->assertSee(__('Other messages (login codes, reminders, broadcasts)'))
            ->set('period', 'today')
            ->assertSee(__('SMS sent'));
    }

    public function test_the_page_asks_for_a_price_when_none_is_saved_and_says_so_when_no_gateway_is_saved(): void
    {
        $this->actingAs($this->admin());
        $this->setting->update(['notification_credentials' => [...$this->setting->notification_credentials, 'sms_price_per_sms' => '']]);

        Livewire::test(SmsDashboard::class)->assertSee(__('Enter your price per SMS to see the estimated cost.'));

        $this->setting->update(['notification_credentials' => []]);

        Livewire::test(SmsDashboard::class)->assertSee(__('No SMS gateway is saved yet.'));
    }

    public function test_only_people_who_manage_settings_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));
        $this->assertFalse(SmsDashboard::canAccess());

        $this->actingAs($this->admin());
        $this->assertTrue(SmsDashboard::canAccess());
    }

    public function test_price_and_balance_alert_are_saved_on_the_sms_gateway_tab(): void
    {
        $this->actingAs($this->admin())
            ->withSession(['current_company_id' => $this->company->getKey(), 'current_company_selection_explicit' => true]);

        Livewire::test(Integrations::class)
            ->set('data.notification_credentials.sms_price_per_sms', '0.35')
            ->set('data.notification_credentials.sms_low_balance_alert', '200')
            ->call('save')
            ->assertHasNoFormErrors();

        $credentials = $this->setting->fresh()->notification_credentials;
        $this->assertEquals(0.35, $credentials['sms_price_per_sms']);
        $this->assertEquals(200, $credentials['sms_low_balance_alert']);
        $this->assertSame('real-key', $credentials['sms_api_key']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function row(string $kind, string $status, ?int $segments, $createdAt = null): SmsLog
    {
        $row = SmsLog::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'kind' => $kind,
            'status' => $status,
            'segments' => $segments,
        ]);

        // created_at is not mass assignable, so an older row is back-dated explicitly.
        if ($createdAt !== null) {
            $row->forceFill(['created_at' => $createdAt])->save();
        }

        return $row;
    }
}
