<?php

namespace Tests\Feature;

use App\Filament\Resources\InvestmentProjects\Pages\ViewInvestmentProject;
use App\Models\Account;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestmentWithdrawalNotice;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\ProjectCostItem;
use App\Models\ProjectSettlement;
use App\Models\SettlementPayout;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorGroupCycleService;
use App\Services\Investment\InvestorGroupService;
use App\Services\Investment\PayoutCompletionService;
use App\Services\Investment\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Leaving a group and what a group's payout contains (16_INVESTOR_GROUPS_PLAN.md,
 * phase G3). A member whose 60-day notice has run out leaves at the first
 * project end after that; the principal of everyone who stays carries on, so a
 * group project pays only the profit, and the leaver gets principal + profit.
 * These are the money rules, so every figure is pinned.
 */
class InvestorGroupExitTest extends TestCase
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
            'name' => 'Exit Co',
            'slug' => 'exit-co',
            'invoice_prefix' => 'EXT',
            'currency' => 'BDT',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->admin);

        $account = Account::query()->create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 100000000]);
        $this->fundSource = FundSource::query()->create(['name' => 'Payouts', 'type' => 'owner_investment', 'account_id' => $account->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ when they leave

    public function test_a_notice_that_ends_on_the_boundary_leaves_then_and_a_later_one_waits_for_the_next(): void
    {
        [$group, $a, $b, $c] = $this->group([200000, 300000, 500000]);
        $this->notice($group, $b, '2025-12-31'); // 60 days later: 1 March, the project's end date
        $this->notice($group, $c, '2026-01-01'); // 60 days later: 2 March, one day too late

        $this->assertSame('2026-03-01', InvestmentWithdrawalNotice::query()->where('investor_id', $b->id)->sole()->effective_at->toDateString());
        $this->assertSame('2026-03-02', InvestmentWithdrawalNotice::query()->where('investor_id', $c->id)->sole()->effective_at->toDateString());

        $this->advanceOn($group, '2026-02-28');
        $this->assertSame(['active', 'active', 'active'], $group->members()->orderBy('id')->pluck('status')->all(), 'nothing happens before the boundary');

        $result = $this->advanceOn($group, '2026-03-01');

        $this->assertSame(1, $result['left']);
        $bMember = $group->members()->where('investor_id', $b->id)->sole();
        $this->assertSame(InvestorGroupMember::STATUS_WITHDRAWN, $bMember->status);
        $this->assertSame('2026-03-01', $bMember->withdrawn_at->toDateString());
        $this->assertSame(1, $bMember->leaves_after_sequence);
        $this->assertSame('honored', InvestmentWithdrawalNotice::query()->where('investor_id', $b->id)->sole()->status);
        $this->assertSame('pending', InvestmentWithdrawalNotice::query()->where('investor_id', $c->id)->sole()->status);

        $second = $group->projects()->where('sequence_in_group', 2)->firstOrFail();
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $second->investments()->pluck('investor_id')->all(), 'the leaver is not in project 2');
        $this->assertSame(700000.0, $second->totalInvested());
        $this->assertSame(3, $group->projects()->where('sequence_in_group', 1)->firstOrFail()->investments()->count(), 'project 1 still shows all three');

        // The one-day-late notice leaves at the next boundary.
        $this->advanceOn($group, '2026-05-01');
        $cMember = $group->members()->where('investor_id', $c->id)->sole();
        $this->assertSame(2, $cMember->leaves_after_sequence);
        $this->assertSame('2026-05-01', $cMember->withdrawn_at->toDateString());
        $this->assertSame([$a->id], $group->projects()->where('sequence_in_group', 3)->firstOrFail()->investments()->pluck('investor_id')->all());
    }

    public function test_cancelled_and_foreign_notices_never_make_anyone_leave(): void
    {
        [$group, $a, $b] = $this->group([200000, 300000]);
        [$otherGroup, $x] = $this->group([100000]);
        $cancelled = $this->notice($group, $a, '2025-12-01');
        $cancelled->update(['status' => 'cancelled']);
        $this->notice($otherGroup, $b, '2025-12-01'); // b has no money in that group

        $this->advanceOn($group, '2026-03-01');

        $this->assertSame(['active', 'active'], $group->members()->pluck('status')->all());
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame(2, $group->projects()->where('sequence_in_group', 2)->firstOrFail()->investments()->count());
        $this->assertSame(1, $otherGroup->members()->where('status', 'active')->count());
        $this->assertNotNull($x);
    }

    public function test_every_deposit_of_a_leaving_investor_in_that_group_leaves_together(): void
    {
        $group = $this->openGroup();
        $twice = $this->investor('Twice', '01840000001');
        $first = $this->deposit($group, 100000, $twice);
        $second = $this->deposit($group, 150000, $twice);
        $stays = $this->deposit($group, 400000, $this->investor('Stays', '01840000002'));
        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-01-01'), $this->admin);
        $group->fresh()->update(['auto_cycle' => true]);
        $this->notice($group, $twice, '2025-12-01');

        $this->advanceOn($group, '2026-03-01');

        $this->assertSame([1, 1], [$first->fresh()->leaves_after_sequence, $second->fresh()->leaves_after_sequence]);
        $this->assertNull($stays->fresh()->leaves_after_sequence);
        $this->assertSame(400000.0, $group->projects()->where('sequence_in_group', 2)->firstOrFail()->totalInvested());
    }

    public function test_when_everyone_leaves_the_project_closes_and_the_group_can_be_closed_after_settling(): void
    {
        [$group, $a, $b] = $this->group([200000, 300000]);
        $this->notice($group, $a, '2025-12-01');
        $this->notice($group, $b, '2025-12-01');

        $decision = app(InvestorGroupCycleService::class)->decide($group, Carbon::parse('2026-03-01'));
        $this->assertSame(InvestorGroupCycleService::NO_MEMBERS, $decision['action'], 'the dry run already knows nobody stays');

        $result = $this->advanceOn($group, '2026-03-01');

        $this->assertSame(InvestorGroupCycleService::NO_MEMBERS, $result['action']);
        $this->assertSame(2, $result['left']);
        $this->assertNull($result['created']);
        $this->assertSame(1, $group->projects()->count());
        $first = $group->projects()->firstOrFail();
        $this->assertSame('closed', $first->status);

        ProjectCostItem::query()->create(['project_id' => $first->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 400000]);
        $settlement = app(SettlementService::class)->calculateAndSettle($first->fresh(), 600000, $this->admin->id, acknowledgeUnprovenCosts: true);

        // Both leave: each gets the principal back with the profit.
        foreach ($settlement->payouts as $payout) {
            $this->assertEquals((float) $payout->investment_amount, $payout->principalReturned());
            $this->assertEquals(round((float) $payout->investment_amount + (float) $payout->profit_share_amount, 2), (float) $payout->total_payout);
        }

        $closed = app(InvestorGroupService::class)->closeGroup($group->fresh(), 'Everyone left', $this->admin);
        $this->assertTrue($closed->isClosed());
    }

    // ------------------------------------- profit paid every cycle, sales or not

    public function test_a_group_cycle_can_be_paid_from_a_profit_entered_by_hand_without_sales(): void
    {
        // Owner (2026-10-18): the group is paid its profit share every cycle, sales or not.
        [$group, $a, $b] = $this->group([200000, 300000]);
        $this->advanceOn($group, '2026-03-01');
        $first = $group->projects()->where('sequence_in_group', 1)->firstOrFail();
        $this->assertSame('closed', $first->status);

        $settlement = app(SettlementService::class)->calculateAndSettle($first->fresh(), 0, $this->admin->id, manualNetProfit: 100000);

        $this->assertSame('manual', $settlement->basis);
        $this->assertEquals(40000, $settlement->investor_pool_amount); // 40% of ৳1,00,000
        // Nobody leaves, so only the profit is paid and the principal stays in the group: 2 : 3 of the pool.
        $this->assertEquals(16000, (float) $settlement->payouts()->where('investor_id', $a->id)->value('total_payout'));
        $this->assertEquals(24000, (float) $settlement->payouts()->where('investor_id', $b->id)->value('total_payout'));
        $this->assertEquals(0, (float) $settlement->payouts()->sum('principal_returned_amount'));
        $this->assertSame('settled', $first->fresh()->status);
    }

    // ----------------------------------------------------- what the payout holds

    public function test_a_leaver_is_paid_principal_and_profit_while_everyone_else_is_paid_only_profit(): void
    {
        // The owner's invoice: ৳2 lakh, ৳20 lakh and ৳40 lakh with ৳9,23,987 of company money.
        [$group, $small, $leaver, $large] = $this->group([200000, 2000000, 4000000], payoutFund: true);
        $this->notice($group, $leaver, '2025-12-01');
        $this->advanceOn($group, '2026-03-01');
        $first = $group->projects()->where('sequence_in_group', 1)->firstOrFail();
        $first->forceFill(['company_contribution_amount' => 923987])->save();
        ProjectCostItem::query()->create(['project_id' => $first->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 7123987]);

        $settlement = app(SettlementService::class)->calculateAndSettle($first->fresh(), 7814000, $this->admin->id, acknowledgeUnprovenCosts: true);

        // The profit maths are exactly the signed-invoice figures: grouping and leaving change who is paid what, not the profit.
        $this->assertEquals(276005.20, $settlement->investor_pool_amount);
        $this->assertEquals(3874.31, $settlement->rate_per_lac);
        $this->assertEquals(23.25, $settlement->annualized_return_percent);

        $payout = fn (Investor $investor): SettlementPayout => $settlement->payouts()->where('investor_id', $investor->id)->firstOrFail();

        $this->assertEquals(0.00, $payout($small)->principal_returned_amount);
        $this->assertEquals(200000.00, $payout($small)->investment_amount, 'the stake is still recorded');
        $this->assertEquals(7748.62, $payout($small)->profit_share_amount);
        $this->assertEquals(7748.62, $payout($small)->total_payout, 'someone who stays is paid the profit only');

        $this->assertEquals(2000000.00, $payout($leaver)->principal_returned_amount);
        $this->assertEquals(77486.16, $payout($leaver)->profit_share_amount);
        $this->assertEquals(2077486.16, $payout($leaver)->total_payout, 'the leaver gets the principal back with the profit');

        $this->assertEquals(0.00, $payout($large)->principal_returned_amount);
        $this->assertEquals(154972.32, $payout($large)->total_payout);

        // The cash that actually leaves the company is booked as exactly that.
        app(SettlementService::class)->confirmSettlement($settlement, $this->admin->id);
        app(PayoutCompletionService::class)->markPaid($payout($leaver), $this->admin);
        app(PayoutCompletionService::class)->markPaid($payout($small), $this->admin);

        $this->assertEquals(2077486.16, (float) $payout($leaver)->fresh()->voucher->amount);
        $this->assertEquals(7748.62, (float) $payout($small)->fresh()->voucher->amount);
    }

    public function test_the_next_project_pays_profit_only_to_those_who_stayed(): void
    {
        [$group, $a, $b] = $this->group([200000, 300000]);
        $this->advanceOn($group, '2026-03-01'); // nobody leaves
        $second = $group->projects()->where('sequence_in_group', 2)->firstOrFail();
        $second->forceFill(['status' => 'closed'])->save();
        ProjectCostItem::query()->create(['project_id' => $second->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 400000]);

        $settlement = app(SettlementService::class)->calculateAndSettle($second->fresh(), 600000, $this->admin->id, acknowledgeUnprovenCosts: true);

        $this->assertEquals(80000.00, $settlement->investor_pool_amount);
        foreach ($settlement->payouts as $payout) {
            $this->assertEquals(0.00, $payout->principal_returned_amount);
            $this->assertEquals((float) $payout->profit_share_amount, (float) $payout->total_payout);
        }
        $this->assertEquals(80000.00, (float) $settlement->payouts()->sum('total_payout'), 'the whole pool goes to the two members, in proportion');
        $this->assertEquals(32000.00, (float) $settlement->payouts()->where('investor_id', $a->id)->value('total_payout'));
        $this->assertEquals(48000.00, (float) $settlement->payouts()->where('investor_id', $b->id)->value('total_payout'));
    }

    public function test_a_project_outside_any_group_still_pays_principal_and_profit(): void
    {
        $project = InvestmentProject::query()->create([
            'company_id' => $this->company->id, 'name' => 'Old Style', 'duration_type' => '2_month', 'start_date' => '2026-01-01',
            'investor_share_percent' => 40, 'channel_partner_share_percent' => 10, 'company_share_percent' => 50, 'status' => 'closed',
        ]);
        $investor = $this->investor('Old', '01840000003');
        Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => 500000, 'payment_method' => 'bank', 'invested_at' => '2026-01-01']);
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 400000]);

        $settlement = app(SettlementService::class)->calculateAndSettle($project, 600000, $this->admin->id, acknowledgeUnprovenCosts: true);
        $payout = $settlement->payouts()->sole();

        $this->assertNull($payout->principal_returned_amount, 'old payouts carry no extra field');
        $this->assertEquals(500000.00, $payout->principalReturned());
        $this->assertEquals(580000.00, $payout->total_payout, '৳5,00,000 principal + ৳80,000 profit, exactly as before');
    }

    // ---------------------------------------------------------------- losses

    public function test_a_loss_the_company_bears_returns_principal_only_to_leavers(): void
    {
        [$group, $a, $b] = $this->group([200000, 300000]);
        $this->notice($group, $b, '2025-12-01');
        $this->advanceOn($group, '2026-03-01');
        $first = $group->projects()->where('sequence_in_group', 1)->firstOrFail();
        ProjectCostItem::query()->create(['project_id' => $first->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 600000]);

        $settlement = app(SettlementService::class)->calculateAndSettle($first->fresh(), 500000, $this->admin->id, acknowledgeUnprovenCosts: true, outcome: 'loss_manager_borne', lossReason: 'Negligence');

        $this->assertEquals(0.00, $settlement->payouts()->where('investor_id', $a->id)->value('total_payout'), 'the stake of someone who stays is untouched');
        $this->assertEquals(300000.00, $settlement->payouts()->where('investor_id', $b->id)->value('total_payout'), 'the leaver gets the whole principal back');
    }

    public function test_a_loss_the_investors_bear_is_refused_on_a_group_project_until_the_owner_decides(): void
    {
        [$group] = $this->group([200000, 300000]);
        $this->advanceOn($group, '2026-03-01');
        $first = $group->projects()->where('sequence_in_group', 1)->firstOrFail();
        ProjectCostItem::query()->create(['project_id' => $first->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 600000]);

        try {
            app(SettlementService::class)->calculateAndSettle($first->fresh(), 500000, $this->admin->id, acknowledgeUnprovenCosts: true, outcome: 'loss_investor_borne', lossReason: 'Disaster');
            $this->fail('An investor-borne loss was settled on a group project.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be settled on a group project yet', $exception->getMessage());
        }

        $this->assertSame(0, ProjectSettlement::query()->count());
        $this->assertSame('closed', $first->fresh()->status);

        // The settle dialog does not even offer that outcome for a group project.
        Livewire::test(ViewInvestmentProject::class, ['record' => $first->getRouteKey()])
            ->callAction('settle', ['total_revenue' => 500000, 'outcome' => 'loss_investor_borne', 'loss_reason' => 'Disaster', 'acknowledge_unproven_costs' => true])
            ->assertHasActionErrors(['outcome']);
        $this->assertSame(0, ProjectSettlement::query()->count());

        Livewire::test(ViewInvestmentProject::class, ['record' => $first->getRouteKey()])
            ->callAction('settle', ['total_revenue' => 500000, 'outcome' => 'loss_manager_borne', 'loss_reason' => 'Negligence', 'acknowledge_unproven_costs' => true])
            ->assertHasNoActionErrors();
        $this->assertSame(1, ProjectSettlement::query()->count());
    }

    // ----------------------------------------------------------- the catch-up

    public function test_a_confirmed_catch_up_lets_a_matured_notice_leave_at_the_right_boundary(): void
    {
        [$group, $a, $b] = $this->group([200000, 300000]);
        $this->notice($group, $b, '2025-12-01');

        // Project 2 has also ended (1 May) by 15 May, so this run only closes project 1.
        $result = $this->advanceOn($group, '2026-05-15');
        $this->assertSame(InvestorGroupCycleService::BEHIND, $result['action']);
        $this->assertSame(0, $result['left'], 'nobody leaves until staff confirm the catch-up');
        $this->assertSame('active', $group->members()->where('investor_id', $b->id)->sole()->status);

        $this->advanceOn($group, '2026-05-15', confirm: true);

        $member = $group->members()->where('investor_id', $b->id)->sole();
        $this->assertSame(1, $member->leaves_after_sequence, 'the notice matured on 1 March: they leave after project 1, not later');
        $this->assertSame('2026-03-01', $member->withdrawn_at->toDateString());
        $this->assertSame([$a->id], $group->projects()->where('sequence_in_group', 2)->firstOrFail()->investments()->pluck('investor_id')->all());
    }

    public function test_the_command_applies_notices_too(): void
    {
        [$group, $a, $b] = $this->group([200000, 300000]);
        $this->notice($group, $b, '2025-12-01');
        Carbon::setTestNow('2026-03-01 09:00:00');

        $this->artisan('investments:advance-groups', ['--company' => $this->company->id])->assertSuccessful();

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(1, $group->members()->where('status', InvestorGroupMember::STATUS_WITHDRAWN)->count());
        $this->assertSame(1, $group->projects()->where('sequence_in_group', 2)->firstOrFail()->investments()->count());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A group started on 2026-01-01 with one investor per amount and automatic cycles on.
     *
     * @param  list<float|int>  $amounts
     * @return array{0: InvestorGroup, 1: Investor, 2?: Investor, 3?: Investor}
     */
    private function group(array $amounts, bool $payoutFund = false): array
    {
        $group = $this->openGroup($payoutFund ? ['payout_fund_source_id' => $this->fundSource->id] : []);
        $investors = [];

        foreach ($amounts as $index => $amount) {
            $investors[] = $investor = $this->investor('Investor '.($index + 1), '01841'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT));
            $this->deposit($group, $amount, $investor);
        }

        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-01-01'), $this->admin);
        $group->fresh()->update(['auto_cycle' => true]);

        return [$group->fresh(), ...$investors];
    }

    /** @param  array<string, mixed>  $overrides */
    private function openGroup(array $overrides = []): InvestorGroup
    {
        return InvestorGroup::query()->create(array_merge(['company_id' => $this->company->id], $overrides));
    }

    private function deposit(InvestorGroup $group, float $amount, Investor $investor): InvestorGroupMember
    {
        return app(InvestorGroupService::class)->addMember($group, $investor, ['amount' => $amount, 'payment_method' => 'bank', 'invested_at' => '2026-01-01'], $this->admin);
    }

    private function investor(string $name, string $phone): Investor
    {
        return Investor::query()->create(['company_id' => $this->company->id, 'name' => $name, 'phone' => $phone]);
    }

    private function notice(InvestorGroup $group, Investor $investor, string $givenOn): InvestmentWithdrawalNotice
    {
        return InvestmentWithdrawalNotice::query()->create([
            'company_id' => $this->company->id,
            'investor_id' => $investor->id,
            'group_id' => $group->id,
            'notice_given_at' => $givenOn,
        ]);
    }

    /** @return array<string, mixed> */
    private function advanceOn(InvestorGroup $group, string $day, bool $confirm = false): array
    {
        Carbon::setTestNow($day.' 09:00:00');

        return app(InvestorGroupCycleService::class)->advance($group->fresh(), Carbon::parse($day), $confirm);
    }
}
