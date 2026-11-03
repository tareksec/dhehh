<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Services\Email\EmailUsageService;
use App\Services\StorefrontNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What Settings → Email delivery shows about email use (owner request 2026-10-24, provider-neutral since
 * 2026-11-02): the Email Log counted for one company, only the emails that went through the given mail service
 * (the log's "via"), by status, kind, week and month, with the free allowance and an estimated cost.
 */
class EmailUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SES = 'smtp:email-smtp.ap-southeast-1.amazonaws.com';

    private Company $company;

    private Company $other;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['name' => 'Usage Co', 'slug' => 'usage-co', 'invoice_prefix' => 'USE', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        $this->other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        $this->now = CarbonImmutable::parse('2026-10-24 12:00:00');

        // October 2026 for the company: 9 accepted by Amazon, 2 failed.
        $this->log('delivered', '2026-10-02 10:00', 'order', times: 3);
        $this->log('sent', '2026-10-03 10:00', 'order');
        $this->log('bounced', '2026-10-09 10:00', 'login_code');
        $this->log('complained', '2026-10-10 10:00', 'ims_code');
        $this->log('failed', '2026-10-16 10:00', 'order', times: 2);
        $this->log('delivered', '2026-10-23 13:00', 'reseller_password_reset');
        $this->log('sent', '2026-10-24 08:00', 'owner_digest');
        $this->log('delivered', '2026-10-24 08:00', 'test');

        // Earlier months.
        $this->log('delivered', '2026-09-12 10:00', 'order', times: 4);
        $this->log('sent', '2026-08-30 10:00', 'order', times: 2);

        // Never counted: a skipped email, another SMTP host, another company's email.
        $this->log('skipped', '2026-10-05 10:00', 'order', via: null);
        $this->log('sent', '2026-10-05 10:00', 'order', via: 'smtp:smtp.gmail.com');
        $this->log('sent', '2026-10-05 10:00', 'order', company: $this->other);
    }

    public function test_the_month_is_counted_by_status_for_this_company_and_this_service_only(): void
    {
        $totals = $this->report()['totals'];

        $this->assertSame(9, $totals['sent'], 'Sent counts what Amazon accepted, whatever became of it.');
        $this->assertSame(5, $totals['delivered']);
        $this->assertSame(2, $totals['waiting'], 'Sent but no report yet.');
        $this->assertSame(1, $totals['bounced']);
        $this->assertSame(1, $totals['complained']);
        $this->assertSame(2, $totals['problems']);
        $this->assertSame(2, $totals['failed'], 'A failure is not counted as sent.');
        $this->assertSame(11, $totals['attempts']);
    }

    public function test_the_last_24_hours_are_counted_and_an_empty_company_reads_zero(): void
    {
        $this->assertSame(3, $this->report()['last_24h']);

        $empty = (new EmailUsageService)->report($this->other->getKey(), self::SES, 0, EmailUsageService::settingsFor(null), $this->now);
        $this->assertSame(1, $empty['totals']['sent'], 'Another company sees only its own email.');

        $nobody = Company::query()->create(['name' => 'Empty Co', 'slug' => 'empty-co', 'invoice_prefix' => 'EMP', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        $none = (new EmailUsageService)->report($nobody->getKey(), self::SES, 0, EmailUsageService::settingsFor(null), $this->now);
        $this->assertSame(0, $none['totals']['sent']);
        $this->assertSame([], $none['kinds']);
        $this->assertSame(0, $none['weeks']['rows'][0]['sent']);
    }

    public function test_the_kinds_are_grouped_and_the_biggest_comes_first(): void
    {
        $kinds = collect($this->report()['kinds'])->keyBy('name');

        $this->assertSame(['Order emails', 'Login and reset codes'], collect($this->report()['kinds'])->take(2)->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Order emails', 'Login and reset codes', 'IMS investor emails', 'Owner digest', 'Test emails'], $kinds->keys()->all());

        $orders = $kinds['Order emails'];
        $this->assertSame([4, 3, 1, 0, 2], [$orders['sent'], $orders['delivered'], $orders['waiting'], $orders['problems'], $orders['failed']]);
        $this->assertEqualsWithDelta(44.4, $orders['share'], 0.1);

        $codes = $kinds['Login and reset codes'];
        $this->assertSame(2, $codes['sent'], 'A login code and a password reset code are one group.');
        $this->assertSame(1, $codes['problems']);
        $this->assertSame(1, $kinds['IMS investor emails']['problems']);
    }

    public function test_the_month_is_split_into_weeks_up_to_today(): void
    {
        $weeks = $this->report()['weeks'];

        $this->assertSame(['Oct 1–7', 'Oct 8–14', 'Oct 15–21', 'Oct 22–24'], array_column($weeks['rows'], 'range'));
        $this->assertSame([4, 2, 0, 3], array_column($weeks['rows'], 'sent'));
        $this->assertSame([3, 0, 0, 2], array_column($weeks['rows'], 'delivered'));
        $this->assertSame(4, $weeks['top'], 'The top of the chart is a tidy even number above the biggest week.');
    }

    public function test_last_month_is_a_whole_month_with_no_reset_or_forecast(): void
    {
        $report = $this->report(monthsBack: 1);

        $this->assertSame(4, $report['totals']['sent']);
        $this->assertFalse($report['month']['is_current']);
        $this->assertSame('Sep 22–30', $report['weeks']['rows'][3]['range']);
        $this->assertNull($report['free']['resets_on']);
        $this->assertNull($report['free']['projected']);
    }

    public function test_the_four_months_up_to_the_current_one_carry_their_cost(): void
    {
        $settings = ['free_limit' => 3, 'price' => 100.0];
        $months = (new EmailUsageService)->report($this->company->getKey(), self::SES, 0, $settings, $this->now)['months'];

        $this->assertSame(['Oct 2026', 'Sep 2026', 'Aug 2026', 'Jul 2026'], array_column($months, 'label'));
        $this->assertSame([9, 4, 2, 0], array_column($months, 'sent'));
        $this->assertSame([2, 0, 0, 0], array_column($months, 'failed'));
        $this->assertSame([6, 1, 0, 0], array_column($months, 'over_by'));
        $this->assertEqualsWithDelta(0.6, $months[0]['cost'], 0.0001, '6 over the free 3, at 100 dollars per 1,000.');
        $this->assertEqualsWithDelta(0.0, $months[2]['cost'], 0.0001);
        $this->assertTrue($months[0]['is_current']);
    }

    public function test_the_free_allowance_is_unset_ok_near_or_over(): void
    {
        $free = fn (?int $limit): array => (new EmailUsageService)->report($this->company->getKey(), self::SES, 0, ['free_limit' => $limit, 'price' => null], $this->now)['free'];

        $this->assertSame('unset', $free(null)['state']);
        $this->assertNull($free(null)['left']);

        $ok = $free(100);
        $this->assertSame('ok', $ok['state']);
        $this->assertSame(91, $ok['left']);
        $this->assertSame('Nov 1', $ok['resets_on']);
        $this->assertSame(8, $ok['resets_in_days']);
        $this->assertSame(12, $ok['projected'], '9 emails in 24 days is about 12 in 31.');

        $this->assertSame('near', $free(10)['state'], '90% is close to the limit.');
        $used = $free(9);
        $this->assertSame('near', $used['state'], 'Exactly all of it is used but not over.');
        $this->assertSame(0, $used['left']);

        $over = $free(5);
        $this->assertSame('over', $over['state']);
        $this->assertSame(4, $over['over_by']);
        $this->assertSame(0, $over['left']);
        $this->assertTrue($over['projected_over']);
    }

    public function test_the_cost_is_only_what_passes_the_free_emails(): void
    {
        $service = new EmailUsageService;

        $cost = $service->cost(12000, ['free_limit' => 3000, 'price' => 0.10]);
        $this->assertSame(9000, $cost['billable']);
        $this->assertEqualsWithDelta(0.9, $cost['amount'], 0.0001);
        $this->assertEqualsWithDelta(1.2, $cost['without_free'], 0.0001);

        $this->assertEqualsWithDelta(0.0, $service->cost(2000, ['free_limit' => 3000, 'price' => 0.10])['amount'], 0.0001);
        $this->assertEqualsWithDelta(0.2, $service->cost(2000, ['free_limit' => null, 'price' => 0.10])['amount'], 0.0001, 'No free allowance typed in: everything is paid for.');
        $this->assertNull($service->cost(2000, ['free_limit' => 3000, 'price' => null])['amount'], 'Without a price there is no cost to show.');
    }

    public function test_money_never_shows_a_small_cost_as_free(): void
    {
        $this->assertSame('—', EmailUsageService::money(null));
        $this->assertSame('$0.00', EmailUsageService::money(0.0));
        $this->assertSame('$0.90', EmailUsageService::money(0.9));
        $this->assertSame('less than $0.01', EmailUsageService::money(0.0004));
        $this->assertSame('$0.01', EmailUsageService::money(0.0146));
    }

    public function test_the_two_cost_numbers_are_read_from_the_site_settings(): void
    {
        $this->assertSame(['free_limit' => null, 'price' => null], EmailUsageService::settingsFor(null));

        $setting = new StorefrontSetting(['notification_credentials' => ['ses_free_limit' => '3000', 'ses_price_per_1000' => '0.1']]);
        $this->assertSame(['free_limit' => 3000, 'price' => 0.1], EmailUsageService::settingsFor($setting));

        $zero = new StorefrontSetting(['notification_credentials' => ['ses_free_limit' => 0, 'ses_price_per_1000' => '0']]);
        $this->assertSame(['free_limit' => 0, 'price' => 0.0], EmailUsageService::settingsFor($zero), 'Zero is a number the owner typed, not "not set".');

        $this->assertContains('ses_free_limit', StorefrontNotificationService::SES_CREDENTIAL_KEYS);
        $this->assertContains('ses_price_per_1000', StorefrontNotificationService::SES_CREDENTIAL_KEYS);
    }

    public function test_the_new_cost_keys_win_over_the_ones_typed_on_the_amazon_page(): void
    {
        $both = new StorefrontSetting(['notification_credentials' => [
            'ses_free_limit' => '200', 'ses_price_per_1000' => '0.1',
            'email_free_limit' => '3000', 'email_price_per_1000' => '0.35',
        ]]);

        $this->assertSame(['free_limit' => 3000, 'price' => 0.35], EmailUsageService::settingsFor($both));
        $this->assertContains('email_free_limit', StorefrontNotificationService::EMAIL_COST_KEYS);
        $this->assertContains('email_price_per_1000', StorefrontNotificationService::EMAIL_COST_KEYS);
    }

    public function test_each_mail_service_is_counted_on_its_own(): void
    {
        $cloudflare = 'smtp:smtp.mx.cloudflare.net';
        $this->log('sent', '2026-10-06 10:00', 'staff_reply', times: 2, via: $cloudflare);
        $this->log('failed', '2026-10-07 10:00', 'order', via: $cloudflare);
        $this->log('sent', '2026-10-06 10:00', 'order', via: $cloudflare, company: $this->other);

        $report = (new EmailUsageService)->report($this->company->getKey(), $cloudflare, 0, ['free_limit' => null, 'price' => null], $this->now);

        $this->assertSame(2, $report['totals']['sent'], 'Only the emails that went through this host, of this company.');
        $this->assertSame(1, $report['totals']['failed']);
        $this->assertSame(2, $report['totals']['waiting'], 'A service that never reports back leaves every accepted email as sent.');
        $this->assertSame(0, $report['totals']['delivered']);
        $this->assertSame(['Replies from the Email inbox'], collect($report['kinds'])->pluck('name')->diff(['Order emails'])->values()->all());

        $this->assertSame(9, $this->report()['totals']['sent'], 'The Amazon SES numbers do not change.');
    }

    /** @return array<string, mixed> */
    private function report(int $monthsBack = 0): array
    {
        return (new EmailUsageService)->report($this->company->getKey(), self::SES, $monthsBack, ['free_limit' => null, 'price' => null], $this->now);
    }

    private function log(string $status, string $when, string $kind, int $times = 1, ?string $via = self::SES, ?Company $company = null): void
    {
        for ($i = 0; $i < $times; $i++) {
            DB::table('email_logs')->insert([
                'company_id' => ($company ?? $this->company)->getKey(),
                'kind' => $kind,
                'recipient' => 'someone@example.test',
                'via' => $via,
                'status' => $status,
                'created_at' => $when.':00',
                'updated_at' => $when.':00',
            ]);
        }
    }
}
