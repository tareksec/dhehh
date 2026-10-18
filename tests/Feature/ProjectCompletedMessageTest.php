<?php

namespace Tests\Feature;

use App\Events\InvestorGroupProjectCompleted;
use App\Filament\Pages\SmsTemplates;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentWithdrawalNotice;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorGroupCycleService;
use App\Services\Investment\InvestorGroupService;
use App\Services\SmsTemplateService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The congratulation SMS when a group project's two months are over
 * (16_INVESTOR_GROUPS_PLAN.md, phase G6). The wording is the owner's, so it
 * starts empty and nothing is sent until it is written.
 */
class ProjectCompletedMessageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->fakeSms(200);

        $this->company = Company::query()->create([
            'name' => 'Message Co', 'slug' => 'message-co', 'invoice_prefix' => 'MSG', 'currency' => 'BDT',
            'timezone' => 'UTC', 'is_active' => true, 'investor_portal_enabled' => true,
        ]);
        $this->configureSms($this->company);
        app(CompanyContext::class)->set($this->company);
        $this->staff = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->staff);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_nothing_is_sent_while_the_message_is_empty_but_the_completion_is_logged(): void
    {
        [$group, $a, $b] = $this->group([200000, 300000]);

        $this->advanceOn($group, '2026-03-01');

        Http::assertNothingSent();
        $log = AuditLog::query()->where('action', 'investor_group.project_completed')->sole();
        $this->assertSame(2, $log->new_values['investors']);
        $this->assertFalse($log->new_values['message_configured']);
        $this->assertSame(0, $log->new_values['sms_sent']);
    }

    public function test_every_investor_of_the_project_gets_the_owners_message_with_their_details(): void
    {
        $this->writeMessage('Congratulations :name! Project :project of :group is complete.');
        [$group, $a, $b] = $this->group([200000, 300000]);

        $this->advanceOn($group, '2026-03-01');

        $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0])->filter(fn (Request $request): bool => str_contains($request->url(), 'sms.example.test'));
        $this->assertCount(2, $sent);

        $messages = $sent->map(fn (Request $request): string => $this->messageOf($request))->sort()->values()->all();
        $this->assertSame([
            "Congratulations {$a->name}! Project 1 of {$group->name} is complete.",
            "Congratulations {$b->name}! Project 1 of {$group->name} is complete.",
        ], collect($messages)->sort()->values()->all());
        $this->assertEqualsCanonicalizing([$a->phone, $b->phone], $sent->map(fn (Request $request): string => $this->phoneOf($request))->all());

        $log = AuditLog::query()->where('action', 'investor_group.project_completed')->sole();
        $this->assertTrue($log->new_values['message_configured']);
        $this->assertSame(2, $log->new_values['sms_sent']);
    }

    public function test_the_investors_of_the_next_project_hear_that_it_started(): void
    {
        app(SmsTemplateService::class)->save($this->company->id, [
            SmsTemplateService::PROJECT_CLOSED => 'Project :project done, :name',
            SmsTemplateService::PROJECT_RUNNING => 'Project :project started, :name, you have :amount in it',
        ]);
        [$group, $a] = $this->group([200000]);

        $result = $this->advanceOn($group, '2026-03-01');

        $this->assertSame('G1-P02', $result['created']->project_code);
        $this->assertEqualsCanonicalizing([
            "Project 1 done, {$a->name}",
            "Project 2 started, {$a->name}, you have 200000 in it",
        ], collect($this->sentMessages())->map(fn (Request $request): string => $this->messageOf($request))->all());
        $this->assertSame(1, AuditLog::query()->where('action', 'investor_group.project_completed')->sole()->new_values['started_sms_sent']);
    }

    public function test_a_second_run_of_the_cycle_never_messages_again(): void
    {
        $this->writeMessage('Project :project done, :name');
        [$group] = $this->group([200000]);

        $this->advanceOn($group, '2026-03-01');
        $this->advanceOn($group, '2026-03-01');
        $this->advanceOn($group, '2026-03-03');

        $this->assertCount(1, $this->sentMessages());
    }

    public function test_the_same_event_cannot_message_an_investor_twice(): void
    {
        $this->writeMessage('Done :name');
        [$group] = $this->group([200000]);
        $result = $this->advanceOn($group, '2026-03-01');

        event(new InvestorGroupProjectCompleted($result['closed'], $group, $result['created']));

        $this->assertCount(1, $this->sentMessages(), 'a repeated event does not send again');
    }

    public function test_a_member_who_left_earlier_is_not_messaged_but_one_leaving_now_is(): void
    {
        $this->writeMessage('Done :name');
        [$group, $stays, $leaves, $gone] = $this->group([200000, 300000, 100000], notices: [1 => '2025-12-01']);
        // $gone left through the old project-level withdrawal before the end of the project.
        $row = $group->projects()->firstOrFail()->investments()->where('investor_id', $gone->id)->firstOrFail();
        $row->forceFill(['status' => 'active'])->save();
        Investment::query()->whereKey($row->id)->update(['status' => 'withdrawn', 'withdrawn_at' => '2026-02-01', 'withdrawal_voucher_id' => null]);

        $this->advanceOn($group, '2026-03-01');

        $phones = collect($this->sentMessages())->map(fn (Request $request): string => $this->phoneOf($request))->all();
        $this->assertEqualsCanonicalizing([$stays->phone, $leaves->phone], $phones, 'the member leaving at this boundary is congratulated too; the one withdrawn earlier is not');
    }

    public function test_an_opted_out_number_is_skipped_and_the_rest_still_get_theirs(): void
    {
        $this->writeMessage('Done :name');
        [$group, $a, $b] = $this->group([200000, 300000]);
        Customer::query()->create(['company_id' => $this->company->id, 'name' => 'Opted out', 'phone' => $a->phone, 'opted_out_at' => now()]);

        $this->advanceOn($group, '2026-03-01');

        $this->assertSame([$b->phone], collect($this->sentMessages())->map(fn (Request $request): string => $this->phoneOf($request))->all());
        $this->assertSame(1, AuditLog::query()->where('action', 'investor_group.project_completed')->sole()->new_values['sms_not_sent']);
    }

    public function test_without_a_gateway_nothing_is_sent_and_nothing_breaks(): void
    {
        $setting = StorefrontSetting::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $setting->notification_credentials = [];
        $setting->save();
        $this->writeMessage('Done :name');
        [$group] = $this->group([200000]);

        $result = $this->advanceOn($group, '2026-03-01');

        Http::assertNothingSent();
        $this->assertSame('G1-P02', $result['created']->project_code);
        $this->assertSame(0, AuditLog::query()->where('action', 'investor_group.project_completed')->sole()->new_values['sms_sent']);
    }

    public function test_a_failing_gateway_never_stops_the_cycle(): void
    {
        $this->fakeSms(500);
        $this->writeMessage('Done :name');
        [$group] = $this->group([200000, 300000]);

        $result = $this->advanceOn($group, '2026-03-01');

        $this->assertSame('closed', $result['closed']->status);
        $this->assertSame('G1-P02', $result['created']->project_code);
        $this->assertSame(0, AuditLog::query()->where('action', 'investor_group.project_completed')->sole()->new_values['sms_sent']);
        $this->assertSame(2, AuditLog::query()->where('action', 'investor_group.project_completed')->sole()->new_values['sms_not_sent']);
    }

    public function test_staff_write_the_message_on_the_sms_templates_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SmsTemplates::class)
            ->assertSet('data.sms_investor_project_closed', null)
            ->set('data.sms_investor_project_closed', '  Congratulations :name!  ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Congratulations :name!', app(SmsTemplateService::class)->body($this->company->id, SmsTemplateService::PROJECT_CLOSED));

        Livewire::test(SmsTemplates::class)
            ->set('data.sms_investor_project_closed', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(app(SmsTemplateService::class)->body($this->company->id, SmsTemplateService::PROJECT_CLOSED), 'an empty message turns the SMS off');
    }

    // ---------------------------------------------------------------- helpers

    private function writeMessage(string $body): void
    {
        app(SmsTemplateService::class)->save($this->company->id, [SmsTemplateService::PROJECT_CLOSED => $body]);
    }

    /** The SMS gateway answering with this status (the latest call wins). */
    private function fakeSms(int $status): void
    {
        Http::swap(new Factory);
        Http::fake(['sms.example.test/*' => Http::response($status === 200 ? 'OK' : 'boom', $status)]);
    }

    private function configureSms(Company $company): void
    {
        StorefrontSetting::query()->withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'site_type' => StorefrontSetting::SITE_BLADE,
            'notification_credentials' => [
                'sms_api_url' => 'http://sms.example.test/send?key={api_key}&to={phone}&msg={message}&from={sender_id}',
                'sms_api_key' => 'key',
                'sms_sender_id' => 'INV',
            ],
        ]);
    }

    /**
     * A group started on 2026-01-01, one investor per amount, automatic cycles on.
     * `$notices` maps an investor position (1-based) to the day a withdrawal notice was given.
     *
     * @param  list<float|int>  $amounts
     * @param  array<int, string>  $notices
     * @return array{0: InvestorGroup, 1: Investor, 2?: Investor, 3?: Investor}
     */
    private function group(array $amounts, array $notices = []): array
    {
        $group = InvestorGroup::query()->create(['company_id' => $this->company->id]);
        $investors = [];

        foreach ($amounts as $index => $amount) {
            $investors[] = $investor = Investor::query()->create([
                'company_id' => $this->company->id, 'name' => 'Investor '.($index + 1), 'phone' => '0171100'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            ]);
            app(InvestorGroupService::class)->addMember($group, $investor, ['amount' => $amount, 'payment_method' => 'bank', 'invested_at' => '2026-01-01'], $this->staff);
        }

        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-01-01'), $this->staff);
        $group->fresh()->update(['auto_cycle' => true]);

        foreach ($notices as $position => $givenOn) {
            InvestmentWithdrawalNotice::query()->create([
                'company_id' => $this->company->id, 'investor_id' => $investors[$position]->id, 'group_id' => $group->id, 'notice_given_at' => $givenOn,
            ]);
        }

        return [$group->fresh(), ...$investors];
    }

    /** @return array<string, mixed> */
    private function advanceOn(InvestorGroup $group, string $day): array
    {
        Carbon::setTestNow($day.' 09:00:00');

        return app(InvestorGroupCycleService::class)->advance($group->fresh(), Carbon::parse($day));
    }

    /** @return list<Request> */
    private function sentMessages(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_contains($request->url(), 'sms.example.test'))
            ->values()
            ->all();
    }

    private function messageOf(Request $request): string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (string) $query['msg'];
    }

    private function phoneOf(Request $request): string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        // The gateway is sent the number with the country code (8801XXXXXXXXX); the stored one is 01XXXXXXXXX.
        return preg_replace('/^88(?=01)/', '', (string) $query['to']);
    }
}
