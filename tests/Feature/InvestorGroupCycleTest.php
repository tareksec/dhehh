<?php

namespace Tests\Feature;

use App\Events\InvestorGroupProjectCompleted;
use App\Filament\Resources\InvestmentProjects\Pages\ViewInvestmentProject;
use App\Filament\Resources\InvestorGroups\Pages\ViewInvestorGroup;
use App\Models\Account;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\ProjectCostItem;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Investment\InvestmentWithdrawalService;
use App\Services\Investment\InvestorGroupCycleService;
use App\Services\Investment\InvestorGroupService;
use App\Services\Investment\SettlementService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The 2-month cycle of a running group (16_INVESTOR_GROUPS_PLAN.md, phase G2):
 * the right project closes and the next one starts on the right day, never
 * twice, never from a stale date. Dates are pinned to calendar boundaries.
 */
class InvestorGroupCycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private FundSource $fundSource;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-01 10:00:00');

        $this->company = Company::query()->create([
            'name' => 'Cycle Co',
            'slug' => 'cycle-co',
            'invoice_prefix' => 'CYC',
            'currency' => 'BDT',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->admin);

        $account = Account::query()->create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 100000000]);
        $this->fundSource = FundSource::query()->create(['name' => 'Investor money', 'type' => 'owner_investment', 'account_id' => $account->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- deciding

    public function test_nothing_is_due_before_the_project_ends(): void
    {
        $group = $this->startedGroup('2026-01-01');

        foreach (['2026-01-01', '2026-02-14', '2026-02-28'] as $day) {
            $decision = $this->cycles()->decide($group, Carbon::parse($day));
            $this->assertSame(InvestorGroupCycleService::NOT_DUE, $decision['action'], $day);
            $this->assertFalse($decision['close']);
        }

        $result = $this->advanceOn($group, '2026-02-28');
        $this->assertNull($result['created']);
        $this->assertSame('running', $group->projects()->firstOrFail()->status);
        $this->assertSame(1, $group->projects()->count());
    }

    public function test_on_the_end_date_the_project_closes_and_the_next_one_starts_with_the_members(): void
    {
        Event::fake([InvestorGroupProjectCompleted::class]);
        $group = $this->startedGroup('2026-01-01', [200000, 300000]);

        $result = $this->advanceOn($group, '2026-03-01');

        $first = $group->projects()->where('sequence_in_group', 1)->firstOrFail();
        $second = $group->projects()->where('sequence_in_group', 2)->firstOrFail();

        $this->assertSame(InvestorGroupCycleService::ADVANCE, $result['action']);
        $this->assertSame('closed', $first->status, 'project 1 is ready for Calculate & Settle');
        $this->assertSame('running', $second->status);
        $this->assertSame('G1-P02', $second->project_code);
        $this->assertSame('2026-03-01', $second->start_date->toDateString());
        $this->assertSame('2026-05-01', $second->end_date->toDateString());
        $this->assertSame('2_month', $second->duration_type);
        $this->assertSame(60, $second->trade_cycle_days);
        $this->assertEquals(0, $second->company_contribution_amount, 'the contribution is entered per project, never copied');
        $this->assertSame(500000.0, $second->totalInvested());
        $this->assertSame(
            $group->activeMembers()->orderBy('id')->pluck('id')->all(),
            $second->investments()->orderBy('id')->pluck('group_member_id')->all(),
        );
        $this->assertSame(500000.0, $first->totalInvested(), 'project 1 keeps its own rows');

        Event::assertDispatchedTimes(InvestorGroupProjectCompleted::class, 1);
        Event::assertDispatched(InvestorGroupProjectCompleted::class, fn (InvestorGroupProjectCompleted $event): bool => $event->project->is($first) && $event->nextProject?->is($second));
    }

    public function test_running_the_cycle_again_creates_nothing_new(): void
    {
        Event::fake([InvestorGroupProjectCompleted::class]);
        $group = $this->startedGroup('2026-01-01');

        foreach (range(1, 4) as $run) {
            $this->advanceOn($group, '2026-03-01');
        }
        $this->advanceOn($group, '2026-03-20'); // still inside project 2's window

        $this->assertSame(2, $group->projects()->count());
        $this->assertSame(1, InvestmentProject::query()->where('group_id', $group->id)->where('sequence_in_group', 2)->count());
        Event::assertDispatchedTimes(InvestorGroupProjectCompleted::class, 1);
    }

    public function test_a_few_days_late_still_starts_the_next_project_from_the_boundary_date(): void
    {
        $group = $this->startedGroup('2026-01-01');

        $this->advanceOn($group, '2026-03-04');

        $second = $group->projects()->where('sequence_in_group', 2)->firstOrFail();
        $this->assertSame('2026-03-01', $second->start_date->toDateString(), 'dates come from the calendar, not from the day the job ran');
        $this->assertSame('2026-05-01', $second->end_date->toDateString());
    }

    public function test_a_group_anchored_on_the_31st_keeps_to_the_calendar_without_drifting(): void
    {
        $group = $this->startedGroup('2026-08-31');

        $this->assertSame(InvestorGroupCycleService::NOT_DUE, $this->cycles()->decide($group, Carbon::parse('2026-10-30'))['action']);

        $this->advanceOn($group, '2026-10-31');
        $this->assertSame(['2026-10-31', '2026-12-31'], $this->datesOf($group, 2));

        $this->assertSame(InvestorGroupCycleService::NOT_DUE, $this->cycles()->decide($group->fresh(), Carbon::parse('2026-12-30'))['action']);
        $this->advanceOn($group->fresh(), '2026-12-31');
        $this->assertSame(['2026-12-31', '2027-02-28'], $this->datesOf($group, 3), 'February is clamped to its last day');

        $this->assertSame(InvestorGroupCycleService::NOT_DUE, $this->cycles()->decide($group->fresh(), Carbon::parse('2027-02-27'))['action']);
        $this->advanceOn($group->fresh(), '2027-02-28');
        $this->assertSame(['2027-02-28', '2027-04-30'], $this->datesOf($group, 4));

        $this->assertSame(['closed', 'closed', 'closed', 'running'], $group->projects()->pluck('status')->all());
        $this->assertSame(4, $group->projects()->count());
    }

    // -------------------------------------------------------------- who joins

    public function test_a_member_who_withdrew_is_not_copied_into_the_next_project(): void
    {
        $group = $this->startedGroup('2026-01-01', [200000, 300000]);
        $first = $group->projects()->firstOrFail();
        $leaving = $first->investments()->orderBy('id')->get()->last();

        app(InvestmentWithdrawalService::class)->withdraw($leaving, ['withdrawn_at' => '2026-02-20', 'fund_source_id' => $this->fundSource->id, 'payment_method' => 'bank'], $this->admin);

        $this->advanceOn($group, '2026-03-01');

        $second = $group->projects()->where('sequence_in_group', 2)->firstOrFail();
        $this->assertSame(200000.0, $second->totalInvested());
        $this->assertSame(1, $second->investments()->count());
        $this->assertSame(2, $first->investments()->count(), 'project 1 still shows both investors');
    }

    public function test_when_nobody_is_left_the_project_closes_but_no_empty_project_starts(): void
    {
        $group = $this->startedGroup('2026-01-01', [200000]);
        $row = $group->projects()->firstOrFail()->investments()->firstOrFail();
        app(InvestmentWithdrawalService::class)->withdraw($row, ['withdrawn_at' => '2026-02-20', 'fund_source_id' => $this->fundSource->id, 'payment_method' => 'bank'], $this->admin);

        $result = $this->advanceOn($group, '2026-03-01');

        $this->assertSame(InvestorGroupCycleService::NO_MEMBERS, $result['action']);
        $this->assertSame('closed', $group->projects()->firstOrFail()->status);
        $this->assertSame(1, $group->projects()->count());
        $this->assertNull($result['created']);
    }

    // ---------------------------------------------------------- falling behind

    public function test_missed_cycles_are_not_caught_up_silently(): void
    {
        $group = $this->startedGroup('2026-01-01');

        // Project 2 should also have ended (1 May) by 15 May: two cycles are due, only project 1 exists.
        $result = $this->advanceOn($group, '2026-05-15');

        $this->assertSame(InvestorGroupCycleService::BEHIND, $result['action']);
        $this->assertSame(1, $result['behind_by']);
        $this->assertNull($result['created']);
        $this->assertSame('closed', $group->projects()->firstOrFail()->status, 'the finished project is still closed');
        $this->assertSame(1, $group->projects()->count());

        $this->advanceOn($group, '2026-05-16');
        $this->assertSame(1, $group->projects()->count(), 'running again does not catch up on its own');
    }

    public function test_staff_confirming_a_catch_up_starts_exactly_one_project_per_confirmation(): void
    {
        $group = $this->startedGroup('2026-01-01');

        // 15 July: projects 1, 2 and 3 have all ended (Mar 1, May 1, Jul 1).
        $this->advanceOn($group, '2026-07-15');
        $this->assertSame(1, $group->projects()->count());

        $this->advanceOn($group, '2026-07-15', confirm: true);
        $this->assertSame(2, $group->projects()->count());
        $this->assertSame(['2026-03-01', '2026-05-01'], $this->datesOf($group, 2));

        $this->advanceOn($group, '2026-07-15', confirm: true);
        $this->assertSame(3, $group->projects()->count());
        $this->assertSame(['2026-05-01', '2026-07-01'], $this->datesOf($group, 3));

        // Project 3's window (1 Jul – 1 Sep) holds 15 July, so the normal run takes over.
        $this->assertSame(InvestorGroupCycleService::ADVANCE, $this->cycles()->decide($group->fresh(), Carbon::parse('2026-07-15'))['action']);
        $this->advanceOn($group->fresh(), '2026-07-15');
        $this->assertSame(4, $group->projects()->count());
        $this->assertSame(['2026-07-01', '2026-09-01'], $this->datesOf($group, 4));
        $this->assertSame(['closed', 'closed', 'closed', 'running'], $group->projects()->pluck('status')->all());
    }

    public function test_a_confirmed_catch_up_without_members_creates_nothing(): void
    {
        $group = $this->startedGroup('2026-01-01', [200000]);
        $row = $group->projects()->firstOrFail()->investments()->firstOrFail();
        app(InvestmentWithdrawalService::class)->withdraw($row, ['withdrawn_at' => '2026-02-20', 'fund_source_id' => $this->fundSource->id, 'payment_method' => 'bank'], $this->admin);

        $result = $this->advanceOn($group, '2026-07-15', confirm: true);

        $this->assertNull($result['created']);
        $this->assertSame(1, $group->projects()->count());
    }

    // --------------------------------------------------- what the cycle leaves

    public function test_a_project_that_is_already_closed_or_settled_stays_as_it_is(): void
    {
        $group = $this->startedGroup('2026-01-01');
        $first = $group->projects()->firstOrFail();
        $first->forceFill(['status' => 'closed'])->save();
        ProjectCostItem::query()->create(['project_id' => $first->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 100000]);
        app(SettlementService::class)->calculateAndSettle($first->fresh(), 150000, $this->admin->id, acknowledgeUnprovenCosts: true);
        $settledPayouts = $first->fresh()->settlement->payouts()->pluck('total_payout')->all();

        $result = $this->advanceOn($group, '2026-03-01');

        $this->assertNull($result['closed'], 'a settled project is not touched');
        $this->assertSame('settled', $first->fresh()->status);
        $this->assertSame('G1-P02', $result['created']->project_code);
        $this->assertSame($settledPayouts, $first->fresh()->settlement->payouts()->pluck('total_payout')->all());
    }

    public function test_the_finished_project_settles_while_the_next_one_runs(): void
    {
        $group = $this->startedGroup('2026-01-01', [200000, 300000]);
        $this->advanceOn($group, '2026-03-01');
        $first = $group->projects()->where('sequence_in_group', 1)->firstOrFail();
        $second = $group->projects()->where('sequence_in_group', 2)->firstOrFail();
        ProjectCostItem::query()->create(['project_id' => $first->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 400000]);

        $settlement = app(SettlementService::class)->calculateAndSettle($first->fresh(), 600000, $this->admin->id, acknowledgeUnprovenCosts: true);

        $this->assertEquals(200000.00, $settlement->net_profit);
        $this->assertEquals(80000.00, $settlement->investor_pool_amount);
        $this->assertEquals(2, $settlement->payouts()->count());
        $this->assertSame('settled', $first->fresh()->status);
        $this->assertSame('running', $second->fresh()->status);
        $this->assertSame(500000.0, $second->totalInvested(), 'project 2 is not affected by the settlement of project 1');
    }

    public function test_a_listener_that_fails_never_undoes_the_cycle(): void
    {
        $group = $this->startedGroup('2026-01-01');
        Event::listen(InvestorGroupProjectCompleted::class, function (): void {
            throw new \RuntimeException('SMS gateway is down');
        });

        $result = $this->advanceOn($group, '2026-03-01');

        $this->assertSame('G1-P02', $result['created']->project_code);
        $this->assertSame(2, $group->projects()->count());
    }

    // ------------------------------------------------------------- the command

    public function test_the_command_only_advances_running_groups_with_automatic_cycles_on(): void
    {
        $on = $this->startedGroup('2026-01-01');
        $off = $this->startedGroup('2026-01-01', autoCycle: false);
        $open = InvestorGroup::query()->create(['company_id' => $this->company->id]);
        Carbon::setTestNow('2026-03-01 09:00:00');

        $this->artisan('investments:advance-groups', ['--company' => $this->company->id])
            ->expectsOutputToContain('Started project 2 of Cycle Co / '.$on->group_code)
            ->assertSuccessful();

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(2, $on->projects()->count());
        $this->assertSame(1, $off->projects()->count(), 'automatic cycles are off');
        $this->assertSame(0, $open->projects()->count());
    }

    public function test_the_command_dry_run_changes_nothing(): void
    {
        $group = $this->startedGroup('2026-01-01');
        Carbon::setTestNow('2026-03-01 09:00:00');

        $this->artisan('investments:advance-groups', ['--company' => $this->company->id, '--dry-run' => true])
            ->expectsOutputToContain('Would start project 2')
            ->assertSuccessful();

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(1, $group->projects()->count());
        $this->assertSame('running', $group->projects()->firstOrFail()->status);
    }

    public function test_the_command_works_through_every_company_separately(): void
    {
        $mine = $this->startedGroup('2026-01-01');
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $theirs = $this->startedGroup('2026-01-01', company: $other);
        $inactive = Company::query()->create(['name' => 'Paused Co', 'slug' => 'paused-co', 'invoice_prefix' => 'PAU', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => false]);
        app(CompanyContext::class)->set($inactive);
        $paused = $this->startedGroup('2026-01-01', company: $inactive);
        Carbon::setTestNow('2026-03-01 09:00:00');

        $this->artisan('investments:advance-groups')->assertSuccessful();

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(2, $mine->projects()->count());
        $this->assertSame(2, InvestmentProject::withoutGlobalScopes()->where('group_id', $theirs->id)->count());
        $this->assertSame(1, InvestmentProject::withoutGlobalScopes()->where('group_id', $paused->id)->count(), 'an inactive company is left alone');
        $this->assertSame($this->company->id, InvestmentProject::withoutGlobalScopes()->where('group_id', $mine->id)->where('sequence_in_group', 2)->value('company_id'));
        $this->assertSame($other->id, InvestmentProject::withoutGlobalScopes()->where('group_id', $theirs->id)->where('sequence_in_group', 2)->value('company_id'));
    }

    public function test_the_command_follows_the_companys_own_midnight(): void
    {
        $this->company->forceFill(['timezone' => 'Asia/Dhaka'])->save();
        $group = $this->startedGroup('2026-01-01');

        // 20:00 UTC on 28 Feb is already 02:00 on 1 March in Dhaka.
        Carbon::setTestNow('2026-02-28 20:00:00');
        $this->artisan('investments:advance-groups', ['--company' => $this->company->id])->assertSuccessful();
        app(CompanyContext::class)->set($this->company);
        $this->assertSame(2, $group->projects()->count());

        // 17:00 UTC on 28 Feb is still 28 Feb in Dhaka… but 23:00 there.
        $second = $this->startedGroup('2026-01-01');
        Carbon::setTestNow('2026-02-28 17:00:00');
        $this->artisan('investments:advance-groups', ['--company' => $this->company->id])->assertSuccessful();
        app(CompanyContext::class)->set($this->company);
        $this->assertSame(1, $second->projects()->count(), 'not yet March in Dhaka');
    }

    public function test_the_command_is_scheduled_hourly_without_overlap(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('investments:advance-groups')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'investments:advance-groups'));

        $this->assertNotNull($event, 'investments:advance-groups must be on the scheduler');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    // ------------------------------------------------------- switching it on

    public function test_automatic_cycles_can_only_be_on_for_a_running_group(): void
    {
        $open = InvestorGroup::query()->create(['company_id' => $this->company->id]);

        try {
            $open->update(['auto_cycle' => true]);
            $this->fail('An open group must not take automatic cycles.');
        } catch (ValidationException) {
        }

        $running = $this->startedGroup('2026-01-01', autoCycle: false);
        $running->update(['auto_cycle' => true]);
        $this->assertTrue($running->fresh()->auto_cycle);
    }

    // -------------------------------------------------- the admin page tells staff

    public function test_the_group_page_warns_when_behind_and_offers_the_catch_up_action(): void
    {
        $group = $this->startedGroup('2026-01-01');
        Carbon::setTestNow('2026-05-15 09:00:00');

        Livewire::test(ViewInvestorGroup::class, ['record' => $group->getRouteKey()])
            ->assertSee('Behind by 1 cycle(s)')
            ->assertActionVisible('startNextProject')
            ->callAction('startNextProject')
            ->assertHasNoActionErrors();

        $this->assertSame(2, $group->projects()->count());
        $this->assertSame('closed', $group->projects()->where('sequence_in_group', 1)->firstOrFail()->status);

        // On time again: no warning, no action.
        Carbon::setTestNow('2026-05-20 09:00:00');
        Livewire::test(ViewInvestorGroup::class, ['record' => $group->getRouteKey()])
            ->assertDontSee('Behind by')
            ->assertActionHidden('startNextProject');
    }

    // ------------------------------------------- capital base vs landed cost

    public function test_the_capital_base_is_compared_with_the_landed_cost(): void
    {
        $project = $this->plainProject();
        $investor = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Capital', 'phone' => '01720000001']);
        Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => 6200000, 'payment_method' => 'bank', 'invested_at' => '2026-01-01']);

        $this->assertNull($project->capitalBaseGap(), 'no landed cost yet, nothing to compare');

        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 7123987]);
        $this->assertSame(-923987.0, $project->fresh()->capitalBaseGap(), 'investors alone are ৳9,23,987 short of the landed cost');

        $project->forceFill(['company_contribution_amount' => 923987])->save();
        $this->assertNull($project->fresh()->capitalBaseGap(), 'investors + company = landed cost: the owner\'s invoice case');

        $project->forceFill(['company_contribution_amount' => 923987.50])->save();
        $this->assertNull($project->fresh()->capitalBaseGap(), 'a gap of ৳1 or less is rounding');

        $project->forceFill(['company_contribution_amount' => 1000000])->save();
        $this->assertSame(76013.0, $project->fresh()->capitalBaseGap(), 'the base can also be higher');
    }

    public function test_the_project_page_warns_when_the_capital_base_is_not_the_landed_cost_and_settling_still_works(): void
    {
        $project = $this->plainProject(['status' => 'closed']);
        $investor = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Capital', 'phone' => '01720000002']);
        Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => 500000, 'payment_method' => 'bank', 'invested_at' => '2026-01-01']);
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 600000]);

        $this->assertStringContainsString(
            'Investors ৳500,000 + company ৳0 = capital base ৳500,000, but the landed cost is ৳600,000 (the base is lower by ৳100,000).',
            ViewInvestmentProject::capitalGapMessage($project),
        );

        Livewire::test(ViewInvestmentProject::class, ['record' => $project->getRouteKey()])
            ->assertSee('Capital base vs landed cost')
            ->assertSee('the base is lower by ৳100,000');

        // A warning, never a block.
        Livewire::test(ViewInvestmentProject::class, ['record' => $project->getRouteKey()])
            ->callAction('settle', ['total_revenue' => 700000, 'outcome' => 'profit', 'acknowledge_unproven_costs' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('settled', $project->fresh()->status);

        // Once the company contribution closes the gap, the project page has no warning.
        $balanced = $this->plainProject(['status' => 'running', 'name' => 'Balanced', 'company_contribution_amount' => 100000]);
        Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $balanced->id, 'investor_id' => $investor->id, 'amount' => 500000, 'payment_method' => 'bank', 'invested_at' => '2026-01-01']);
        ProjectCostItem::query()->create(['project_id' => $balanced->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 600000]);

        Livewire::test(ViewInvestmentProject::class, ['record' => $balanced->getRouteKey()])
            ->assertDontSee('Capital base vs landed cost');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A group started on `$anchor` with one member per amount.
     *
     * @param  list<float|int>  $amounts
     */
    private function startedGroup(string $anchor, array $amounts = [200000], bool $autoCycle = true, ?Company $company = null): InvestorGroup
    {
        $company ??= $this->company;
        $realNow = Carbon::now();
        Carbon::setTestNow($anchor.' 10:00:00');

        $group = InvestorGroup::query()->create(['company_id' => $company->id]);

        foreach ($amounts as $amount) {
            app(InvestorGroupService::class)->addMember($group, Investor::query()->create([
                'company_id' => $company->id, 'name' => 'Member '.random_int(1000, 999999), 'phone' => '017'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            ]), ['amount' => $amount, 'payment_method' => 'bank', 'invested_at' => $anchor], $this->admin);
        }

        app(InvestorGroupService::class)->startGroup($group, Carbon::parse($anchor), $this->admin);

        if ($autoCycle) {
            $group->fresh()->update(['auto_cycle' => true]);
        }

        Carbon::setTestNow($realNow);

        return $group->fresh();
    }

    private function cycles(): InvestorGroupCycleService
    {
        return app(InvestorGroupCycleService::class);
    }

    /** @return array<string, mixed> */
    private function advanceOn(InvestorGroup $group, string $day, bool $confirm = false): array
    {
        Carbon::setTestNow($day.' 09:00:00');

        return $this->cycles()->advance($group->fresh(), Carbon::parse($day), $confirm);
    }

    /** @return array{string, string} start and end date of project number `$sequence` */
    private function datesOf(InvestorGroup $group, int $sequence): array
    {
        $project = $group->projects()->where('sequence_in_group', $sequence)->firstOrFail();

        return [$project->start_date->toDateString(), $project->end_date->toDateString()];
    }

    private function plainProject(array $overrides = []): InvestmentProject
    {
        return InvestmentProject::query()->create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Plain Project',
            'duration_type' => '2_month',
            'start_date' => '2026-01-01',
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'status' => 'running',
        ], $overrides));
    }
}
