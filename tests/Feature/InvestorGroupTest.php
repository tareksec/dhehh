<?php

namespace Tests\Feature;

use App\Filament\Resources\InvestmentProjects\Pages\EditInvestmentProject;
use App\Filament\Resources\InvestmentProjects\Pages\ListInvestmentProjects;
use App\Filament\Resources\InvestmentProjects\Pages\ViewInvestmentProject;
use App\Filament\Resources\InvestmentProjects\RelationManagers\InvestmentsRelationManager as ProjectInvestmentsRelationManager;
use App\Filament\Resources\InvestorGroups\InvestorGroupResource;
use App\Filament\Resources\InvestorGroups\Pages\CreateInvestorGroup;
use App\Filament\Resources\InvestorGroups\Pages\ListInvestorGroups;
use App\Filament\Resources\InvestorGroups\Pages\ViewInvestorGroup;
use App\Filament\Resources\InvestorGroups\RelationManagers\MembersRelationManager;
use App\Filament\Resources\InvestorGroups\RelationManagers\ProjectsRelationManager;
use App\Models\Account;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\ProjectCostItem;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Voucher;
use App\Services\CompanyContext;
use App\Services\Investment\InvestmentLedgerService;
use App\Services\Investment\InvestmentWithdrawalService;
use App\Services\Investment\InvestorGroupBackfillService;
use App\Services\Investment\InvestorGroupService;
use App\Services\Investment\SettlementService;
use App\Services\InvestorRequestService;
use App\Services\VoucherTrashService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Group-based investment (16_INVESTOR_GROUPS_PLAN.md, phase G1): groups,
 * members, the Project 1 snapshot, the one-voucher rule, the guards and the
 * backfill of today's projects. The settlement maths are pinned to the figures
 * of the owner's signed invoice so a change cannot slip through before deploy.
 */
class InvestorGroupTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private FundSource $fundSource;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 10:00:00');

        $this->company = Company::query()->create([
            'name' => 'Group Co',
            'slug' => 'group-co',
            'invoice_prefix' => 'GRP',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
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

    // ---------------------------------------------------------------- groups

    public function test_group_codes_count_up_per_company(): void
    {
        $first = $this->group();
        $second = $this->group();

        $this->assertSame('G1', $first->group_code);
        $this->assertSame('Group-1', $first->name);
        $this->assertSame('G2', $second->group_code);

        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);

        $this->assertSame('G1', $this->group(['company_id' => $other->id])->group_code, 'a second company starts its own numbering');
    }

    public function test_group_shares_must_total_one_hundred_percent(): void
    {
        $this->expectException(ValidationException::class);

        $this->group(['investor_share_percent' => 40, 'channel_partner_share_percent' => 10, 'company_share_percent' => 40]);
    }

    public function test_a_group_only_moves_forward_through_its_statuses(): void
    {
        $group = $this->group();

        $group->forceFill(['status' => InvestorGroup::STATUS_RUNNING])->save();

        $this->expectException(ValidationException::class);
        $group->forceFill(['status' => InvestorGroup::STATUS_OPEN])->save();
    }

    public function test_the_start_date_and_cycle_length_are_locked_once_automatic_cycles_are_on(): void
    {
        $group = $this->runningGroup();

        $group->update(['running_since' => '2026-03-01']); // still fixable while automatic cycles are off
        $this->assertSame('2026-03-01', $group->fresh()->running_since->toDateString());

        $group->update(['auto_cycle' => true]);

        foreach (['running_since' => '2026-03-05', 'cycle_months' => 3] as $field => $value) {
            try {
                $group->fresh()->update([$field => $value]);
                $this->fail("{$field} must be locked while automatic cycles are on.");
            } catch (ValidationException) {
            }
        }
    }

    public function test_a_group_with_members_cannot_be_deleted(): void
    {
        $group = $this->group();
        $this->member($group, 100000);

        $this->expectException(ValidationException::class);
        $group->delete();
    }

    // --------------------------------------------------------------- members

    public function test_members_join_an_open_group_and_the_money_is_booked_once(): void
    {
        $group = $this->group(['receiving_fund_source_id' => $this->fundSource->id]);

        $member = app(InvestorGroupService::class)->addMember($group, $this->investor('Rahim', '01710000001'), [
            'amount' => 300000, 'payment_method' => 'bank', 'payment_reference' => 'TRX-1', 'invested_at' => '2026-03-01',
        ], $this->admin);

        $this->assertSame(InvestorGroupMember::STATUS_ACTIVE, $member->status);
        $this->assertSame(300000.0, (float) $member->amount);
        $this->assertNotNull($member->voucher_id, 'the deposit is booked as a pending capital investment voucher');

        $voucher = $member->voucher;
        $this->assertSame(Voucher::TYPE_CREDIT, $voucher->type);
        $this->assertSame('capital_investment', $voucher->transaction_type);
        $this->assertSame(300000.0, (float) $voucher->amount);
        $this->assertSame($this->fundSource->account_id, $voucher->account_id);
    }

    public function test_a_running_or_closed_group_takes_no_new_members(): void
    {
        $running = $this->runningGroup();
        $investor = $this->investor('Late Joiner', '01710000002');

        try {
            app(InvestorGroupService::class)->addMember($running, $investor, ['amount' => 50000, 'payment_method' => 'cash'], $this->admin);
            $this->fail('A running group must refuse new members.');
        } catch (ValidationException) {
        }

        // The model refuses too, so no code path can sneak a member in.
        try {
            InvestorGroupMember::query()->create(['group_id' => $running->id, 'investor_id' => $investor->id, 'amount' => 50000, 'invested_at' => '2026-03-10']);
            $this->fail('The model must refuse a member on a running group.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, InvestorGroupMember::query()->where('investor_id', $investor->id)->count());
    }

    public function test_a_member_needs_a_positive_amount_and_an_investor_of_the_same_company(): void
    {
        $group = $this->group();

        try {
            app(InvestorGroupService::class)->addMember($group, $this->investor('Zero', '01710000003'), ['amount' => 0, 'payment_method' => 'cash'], $this->admin);
            $this->fail('A zero deposit must be refused.');
        } catch (ValidationException) {
        }

        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        $foreign = Investor::withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Foreign', 'phone' => '01710000004']);

        $this->expectException(ValidationException::class);
        app(InvestorGroupService::class)->addMember($group, $foreign, ['amount' => 1000, 'payment_method' => 'cash'], $this->admin);
    }

    public function test_an_investor_can_hold_several_deposits_in_the_same_open_group(): void
    {
        $group = $this->group();
        $investor = $this->investor('Twice', '01710000005');

        $this->member($group, 100000, $investor);
        $this->member($group, 250000, $investor);

        $this->assertSame(2, $group->activeMembers()->count());
        $this->assertSame(350000.0, $group->totalActiveDeposits());
    }

    public function test_a_member_withdraws_the_deposit_only_while_the_group_is_open(): void
    {
        $group = $this->group();
        $member = $this->member($group, 200000);
        $data = ['withdrawn_at' => '2026-03-10', 'fund_source_id' => $this->fundSource->id, 'payment_method' => 'bank'];

        $voucher = app(InvestmentWithdrawalService::class)->withdrawGroupMember($member, $data, $this->admin);

        $this->assertSame('investor_payout', $voucher->transaction_type);
        $this->assertSame(200000.0, (float) $voucher->amount);
        $this->assertTrue($member->fresh()->isWithdrawn());
        $this->assertSame($voucher->id, $member->fresh()->withdrawal_voucher_id);

        try {
            app(InvestmentWithdrawalService::class)->withdrawGroupMember($member, $data, $this->admin);
            $this->fail('A deposit cannot be withdrawn twice.');
        } catch (ValidationException) {
        }

        // Once the group runs the money is deployed: no instant withdrawal.
        $running = $this->runningGroup();
        $runningMember = $running->members()->firstOrFail();

        $this->expectException(ValidationException::class);
        app(InvestmentWithdrawalService::class)->withdrawGroupMember($runningMember, $data, $this->admin);
    }

    public function test_a_withdrawn_deposit_that_loses_its_voucher_is_active_again(): void
    {
        $group = $this->group();
        $member = $this->member($group, 200000);
        $voucher = app(InvestmentWithdrawalService::class)->withdrawGroupMember($member, ['withdrawn_at' => '2026-03-10', 'fund_source_id' => $this->fundSource->id, 'payment_method' => 'bank'], $this->admin);

        app(VoucherTrashService::class)->trash($voucher, $this->admin);
        $this->assertSame(InvestorGroupMember::STATUS_ACTIVE, $member->fresh()->status);

        app(VoucherTrashService::class)->restore($voucher->fresh(), $this->admin);
        $this->assertSame(InvestorGroupMember::STATUS_WITHDRAWN, $member->fresh()->status);
    }

    // ------------------------------------------------------- starting a group

    public function test_starting_a_group_creates_project_one_with_a_snapshot_of_the_members(): void
    {
        $group = $this->group(['notes' => 'first batch']);
        $a = $this->member($group, 200000, $this->investor('A', '01710000011'));
        $b = $this->member($group, 300000, $this->investor('B', '01710000012'));

        $project = app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-03-10'), $this->admin);

        $group->refresh();
        $this->assertSame(InvestorGroup::STATUS_RUNNING, $group->status);
        $this->assertSame('2026-03-10', $group->running_since->toDateString());
        $this->assertFalse($group->auto_cycle, 'automatic cycles are a separate decision');

        $this->assertSame('G1-P01', $project->project_code);
        $this->assertSame($group->id, $project->group_id);
        $this->assertSame(1, $project->sequence_in_group);
        $this->assertSame('running', $project->status);
        $this->assertSame('2026-03-10', $project->start_date->toDateString());
        $this->assertSame('2026-05-10', $project->end_date->toDateString());
        $this->assertSame('2_month', $project->duration_type);
        $this->assertSame(60, $project->trade_cycle_days, 'returns stay on the 2-month = 60-day basis');
        $this->assertEquals(40, $project->investor_share_percent);
        $this->assertEquals(10, $project->channel_partner_share_percent);
        $this->assertEquals(50, $project->company_share_percent);
        $this->assertEquals(0, $project->company_contribution_amount, 'the contribution is entered per project, never copied');

        $rows = $project->investments()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$a->id, $b->id], $rows->pluck('group_member_id')->all());
        $this->assertSame([200000.0, 300000.0], $rows->map(fn (Investment $row): float => (float) $row->amount)->all());
        $this->assertSame(500000.0, $project->totalInvested());
    }

    public function test_the_snapshot_books_no_second_voucher(): void
    {
        $group = $this->group(['receiving_fund_source_id' => $this->fundSource->id]);
        app(InvestorGroupService::class)->addMember($group, $this->investor('A', '01710000021'), ['amount' => 200000, 'payment_method' => 'bank'], $this->admin);
        app(InvestorGroupService::class)->addMember($group, $this->investor('B', '01710000022'), ['amount' => 300000, 'payment_method' => 'bank'], $this->admin);
        $this->assertSame(2, Voucher::query()->count());

        $project = app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-03-10'), $this->admin);

        $this->assertSame(2, Voucher::query()->count(), 'starting the group must not book the money again');
        $this->assertSame(0, $project->investments()->whereNotNull('voucher_id')->count());

        // Even asking the ledger directly books nothing for a snapshot row.
        app(InvestmentLedgerService::class)->recordInvestmentReceived($project->investments()->firstOrFail());
        $this->assertSame(2, Voucher::query()->count());
    }

    public function test_a_group_needs_a_member_and_a_start_date_that_is_not_in_the_future(): void
    {
        $empty = $this->group();

        try {
            app(InvestorGroupService::class)->startGroup($empty, Carbon::parse('2026-03-10'), $this->admin);
            $this->fail('A group without members must not start.');
        } catch (ValidationException) {
        }

        $group = $this->group();
        $this->member($group, 100000);

        try {
            app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-03-11'), $this->admin);
            $this->fail('A future start date must be refused.');
        } catch (ValidationException) {
        }

        $this->assertSame(InvestorGroup::STATUS_OPEN, $group->fresh()->status);
        $this->assertSame(0, $group->projects()->count());
    }

    public function test_a_group_cannot_be_started_twice(): void
    {
        $group = $this->group();
        $this->member($group, 100000);
        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-03-10'), $this->admin);

        $this->expectException(ValidationException::class);
        app(InvestorGroupService::class)->startGroup($group->fresh(), Carbon::parse('2026-03-10'), $this->admin);
    }

    public function test_later_projects_are_dated_from_the_anchor_and_skip_withdrawn_members(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $group = $this->group();
        $stays = $this->member($group, 200000, $this->investor('Stays', '01710000031'));
        $leaves = $this->member($group, 300000, $this->investor('Leaves', '01710000032'));
        $service = app(InvestorGroupService::class);
        $first = $service->startGroup($group, Carbon::parse('2026-08-31'), $this->admin);
        $this->assertSame('2026-10-31', $first->end_date->toDateString());

        // The member leaves through their (latest) project row: the member follows.
        $row = $first->investments()->where('group_member_id', $leaves->id)->firstOrFail();
        app(InvestmentWithdrawalService::class)->withdraw($row, ['withdrawn_at' => '2026-10-31', 'fund_source_id' => $this->fundSource->id, 'payment_method' => 'bank'], $this->admin);
        $this->assertTrue($leaves->fresh()->isWithdrawn());

        $second = $service->createProject($group->fresh(), 2);
        $service->snapshotMembers($group->fresh(), $second);
        $third = $service->createProject($group->fresh(), 3);
        $fourth = $service->createProject($group->fresh(), 4);

        $this->assertSame(['G1-P02', 'G1-P03', 'G1-P04'], [$second->project_code, $third->project_code, $fourth->project_code]);
        $this->assertSame('2026-10-31', $second->start_date->toDateString());
        $this->assertSame('2026-12-31', $second->end_date->toDateString());
        $this->assertSame('2026-12-31', $third->start_date->toDateString());
        $this->assertSame('2027-02-28', $third->end_date->toDateString(), 'February is clamped to its last day');
        $this->assertSame('2027-04-30', $fourth->end_date->toDateString());

        $this->assertSame([$stays->id], $second->investments()->pluck('group_member_id')->all(), 'the member who left is not copied again');
        $this->assertSame(200000.0, $second->totalInvested());
        $this->assertSame(2, $first->investments()->count(), 'the first project keeps both rows: history never changes');
    }

    public function test_one_position_in_a_group_holds_one_project(): void
    {
        $group = $this->runningGroup();

        $this->expectException(QueryException::class);
        app(InvestorGroupService::class)->createProject($group, 1);
    }

    // ----------------------------------------------------- group project rules

    public function test_money_cannot_be_added_straight_to_a_group_project(): void
    {
        $group = $this->runningGroup();
        $project = $group->projects()->firstOrFail();
        $stranger = $this->investor('Stranger', '01710000041');
        $row = fn (array $overrides = []): array => array_merge([
            'company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $stranger->id,
            'amount' => 50000, 'payment_method' => 'cash', 'invested_at' => '2026-03-10',
        ], $overrides);

        try {
            Investment::query()->create($row());
            $this->fail('A loose investment on a group project must be refused.');
        } catch (ValidationException) {
        }

        // A member of ANOTHER group, or of another investor, is not an excuse either.
        $otherGroup = $this->group();
        $otherMember = $this->member($otherGroup, 70000, $stranger);

        try {
            Investment::query()->create($row(['group_member_id' => $otherMember->id]));
            $this->fail('A member of another group must be refused.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, $project->investments()->count(), 'only the snapshot row is there');
    }

    public function test_a_project_cannot_be_moved_to_another_group(): void
    {
        $group = $this->runningGroup();
        $project = $group->projects()->firstOrFail();
        $other = $this->group();

        $this->expectException(ValidationException::class);
        $project->update(['group_id' => $other->id]);
    }

    public function test_the_pick_a_project_flows_no_longer_offer_group_projects(): void
    {
        $group = $this->runningGroup();
        $plain = $this->legacyProject(['status' => 'running']);

        $offered = app(InvestorRequestService::class)->openProjects()->pluck('id')->all();

        $this->assertContains($plain->id, $offered);
        $this->assertNotContains($group->projects()->firstOrFail()->id, $offered);
    }

    // ------------------------------------------------------------ closing

    public function test_a_group_closes_only_when_every_project_is_settled_and_every_member_is_out(): void
    {
        $group = $this->runningGroup();
        $project = $group->projects()->firstOrFail();
        $service = app(InvestorGroupService::class);

        try {
            $service->closeGroup($group, '', $this->admin);
            $this->fail('A reason is required.');
        } catch (ValidationException) {
        }

        try {
            $service->closeGroup($group, 'Done', $this->admin);
            $this->fail('A group with an unsettled project must not close.');
        } catch (ValidationException) {
        }

        $project->forceFill(['status' => 'closed'])->save();
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 100000]);
        app(SettlementService::class)->calculateAndSettle($project, 150000, $this->admin->id, acknowledgeUnprovenCosts: true);

        try {
            $service->closeGroup($group, 'Done', $this->admin);
            $this->fail('A group whose members still hold money must not close.');
        } catch (ValidationException) {
        }

        $row = $project->investments()->firstOrFail();
        app(InvestmentWithdrawalService::class)->withdraw($row, ['withdrawn_at' => '2026-03-10', 'fund_source_id' => $this->fundSource->id, 'payment_method' => 'bank'], $this->admin);

        $closed = $service->closeGroup($group->fresh(), 'All settled', $this->admin);

        $this->assertSame(InvestorGroup::STATUS_CLOSED, $closed->status);
        $this->assertSame('2026-03-10', $closed->closed_on->toDateString());
        $this->assertSame('All settled', $closed->close_reason);
        $this->assertFalse($closed->auto_cycle);

        $this->expectException(ValidationException::class);
        $service->addMember($closed, $this->investor('Late', '01710000051'), ['amount' => 1000, 'payment_method' => 'cash'], $this->admin);
    }

    // ----------------------------------------------- the money maths, pinned

    public function test_a_group_project_settles_to_the_figures_of_the_signed_invoice(): void
    {
        // Owner's Project-03 invoice: investors ৳62,00,000 + company ৳9,23,987 = the ৳71,23,987
        // landed cost. 40% of the net profit is the investor pool, shared per lakh.
        $group = $this->group();
        $twoLakh = $this->investor('Two Lakh', '01710000061');
        $this->member($group, 200000, $twoLakh);
        $this->member($group, 2000000, $this->investor('Twenty Lakh', '01710000062'));
        $this->member($group, 4000000, $this->investor('Forty Lakh', '01710000063'));

        $project = app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-03-10'), $this->admin);
        $project->forceFill(['company_contribution_amount' => 923987, 'status' => 'closed'])->save();
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 7123987]);

        $settlement = app(SettlementService::class)->calculateAndSettle($project->fresh(), 7814000, $this->admin->id, acknowledgeUnprovenCosts: true);

        $this->assertEquals(690013.00, $settlement->net_profit);
        $this->assertEquals(276005.20, $settlement->investor_pool_amount);
        $this->assertEquals(3874.31, $settlement->rate_per_lac, 'investor profit per ৳1,00,000');
        $this->assertEquals(23.25, $settlement->annualized_return_percent);

        $payout = $settlement->payouts()->where('investor_id', $twoLakh->id)->firstOrFail();
        $this->assertEquals(200000.00, $payout->investment_amount);
        $this->assertEquals(7748.62, $payout->profit_share_amount, 'a ৳2,00,000 investor earns ৳7,748.62');
        $this->assertEquals(0.00, $payout->principal_returned_amount, 'the principal stays in the group');
        $this->assertEquals(7748.62, $payout->total_payout, 'a member who stays is paid the profit only');

        $investorsTotal = (float) $settlement->payouts()->sum('profit_share_amount');
        $this->assertEqualsWithDelta(240207.10, $investorsTotal, 0.05, 'the investors take the pool in proportion to their capital');
        $this->assertSame('settled', $project->fresh()->status);
    }

    public function test_settling_a_group_project_gives_the_same_result_as_the_old_way(): void
    {
        // The group is only a layer above the project: the same money gives
        // the same settlement whether the rows were typed in or copied from members.
        $legacy = $this->legacyProject(['status' => 'closed']);
        $group = $this->group();
        foreach ([['01710000071', 150000], ['01710000072', 350000]] as [$phone, $amount]) {
            $investor = $this->investor('Same '.$phone, $phone);
            Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $legacy->id, 'investor_id' => $investor->id, 'amount' => $amount, 'payment_method' => 'bank', 'invested_at' => '2026-03-01', 'received_by' => $this->admin->id]);
            $this->member($group, $amount, $investor);
        }
        $grouped = app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-03-10'), $this->admin);
        $grouped->forceFill(['status' => 'closed'])->save();

        foreach ([$legacy, $grouped] as $project) {
            ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 400000]);
        }

        $service = app(SettlementService::class);
        $old = $service->calculateAndSettle($legacy, 600000, $this->admin->id, acknowledgeUnprovenCosts: true);
        $new = $service->calculateAndSettle($grouped->fresh(), 600000, $this->admin->id, acknowledgeUnprovenCosts: true);

        foreach (['net_profit', 'investor_pool_amount', 'company_net_amount', 'rate_per_lac', 'annualized_return_percent'] as $figure) {
            $this->assertEquals($old->{$figure}, $new->{$figure}, $figure);
        }
        // Same profit for each investor; only what is paid out differs (a group keeps the principal invested).
        $this->assertEquals(
            $old->payouts()->orderBy('investor_id')->pluck('profit_share_amount')->all(),
            $new->payouts()->orderBy('investor_id')->pluck('profit_share_amount')->all(),
        );
        $this->assertEquals(
            $new->payouts()->orderBy('investor_id')->pluck('profit_share_amount')->all(),
            $new->payouts()->orderBy('investor_id')->pluck('total_payout')->all(),
            'a group project pays the profit only',
        );
        $this->assertEquals(
            $old->payouts()->orderBy('investor_id')->get()->map(fn ($payout): float => round((float) $payout->investment_amount + (float) $payout->profit_share_amount, 2))->all(),
            $old->payouts()->orderBy('investor_id')->pluck('total_payout')->map(fn ($amount): float => (float) $amount)->all(),
            'an old-style project still pays principal + profit',
        );
    }

    // ------------------------------------------------------------- backfill

    public function test_the_backfill_moves_each_project_into_its_own_group_without_touching_the_money(): void
    {
        $settled = $this->legacyProject(['name' => 'Project 01', 'status' => 'closed', 'start_date' => '2026-01-05']);
        $running = $this->legacyProject(['name' => 'Project 02', 'status' => 'running', 'start_date' => '2026-02-20']);
        $this->legacyProject(['name' => 'Still open', 'status' => 'open']);
        $this->legacyProject(['name' => 'No money', 'status' => 'running']);

        $a = $this->investor('A', '01710000081');
        $b = $this->investor('B', '01710000082');
        $this->legacyInvestment($settled, $a, 200000);
        $this->legacyInvestment($settled, $b, 300000);
        $leaver = $this->legacyInvestment($running, $a, 100000);

        ProjectCostItem::query()->create(['project_id' => $settled->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 400000]);
        $settlement = app(SettlementService::class)->calculateAndSettle($settled, 600000, $this->admin->id, acknowledgeUnprovenCosts: true);
        $payoutsBefore = $settlement->payouts()->orderBy('id')->get(['id', 'investor_id', 'investment_amount', 'profit_share_amount', 'total_payout'])->toArray();
        $codesBefore = InvestmentProject::query()->orderBy('id')->pluck('project_code', 'id')->all();

        app(InvestmentWithdrawalService::class)->withdraw($leaver, ['withdrawn_at' => '2026-03-01', 'fund_source_id' => $this->fundSource->id, 'payment_method' => 'bank'], $this->admin);
        $vouchersBefore = Voucher::query()->count();

        $service = app(InvestorGroupBackfillService::class);
        $plan = $service->plan();

        $this->assertSame(['G1', 'G2'], array_column($plan['rows'], 'group_code'));
        $this->assertCount(2, $plan['skipped'], 'the open project and the empty project wait for the owner');
        $this->assertSame(500000.0, $plan['rows'][0]['total']);
        $this->assertSame(0, InvestorGroup::query()->count(), 'planning changes nothing');

        $service->apply();

        $groups = InvestorGroup::query()->orderBy('id')->get();
        $this->assertSame(['G1', 'G2'], $groups->pluck('group_code')->all());
        $this->assertSame(['Group-1', 'Group-2'], $groups->pluck('name')->all());
        $this->assertSame([InvestorGroup::STATUS_RUNNING, InvestorGroup::STATUS_RUNNING], $groups->pluck('status')->all());
        $this->assertSame([false, false], $groups->pluck('auto_cycle')->all(), 'automatic cycles stay off until staff check the dates');
        $this->assertSame(['2026-01-05', '2026-02-20'], $groups->map(fn (InvestorGroup $group): string => $group->running_since->toDateString())->all());

        $settled->refresh();
        $running->refresh();
        $this->assertSame([$groups[0]->id, 1], [$settled->group_id, $settled->sequence_in_group]);
        $this->assertSame([$groups[1]->id, 1], [$running->group_id, $running->sequence_in_group]);
        $this->assertSame($codesBefore, InvestmentProject::query()->orderBy('id')->pluck('project_code', 'id')->all(), 'existing project codes are never renamed');
        $this->assertSame('settled', $settled->status);

        // Members copy the investments; every investment points at its member.
        $this->assertSame(500000.0, $groups[0]->totalActiveDeposits());
        $this->assertSame(0.0, $groups[1]->totalActiveDeposits(), 'the withdrawn deposit is not active');
        $this->assertSame(1, $groups[1]->members()->where('status', InvestorGroupMember::STATUS_WITHDRAWN)->count());
        $this->assertSame(0, Investment::query()->whereIn('project_id', [$settled->id, $running->id])->whereNull('group_member_id')->count());
        $this->assertSame(
            $leaver->fresh()->withdrawal_voucher_id,
            $groups[1]->members()->firstOrFail()->withdrawal_voucher_id,
            'a withdrawn investment keeps its voucher on the member'
        );

        // Nothing was booked or recomputed.
        $this->assertSame($vouchersBefore, Voucher::query()->count());
        $this->assertSame($payoutsBefore, $settlement->fresh()->payouts()->orderBy('id')->get(['id', 'investor_id', 'investment_amount', 'profit_share_amount', 'total_payout'])->toArray());
        $this->assertEquals(500000.0, $settled->totalInvested());

        // A second run has nothing left to move.
        $this->assertSame([], $service->plan()['rows']);
        $this->assertSame(2, InvestorGroup::query()->count());
    }

    public function test_the_backfill_command_dry_run_changes_nothing_and_the_real_run_asks_no_questions_with_force(): void
    {
        $project = $this->legacyProject(['status' => 'running', 'start_date' => '2026-02-01']);
        $this->legacyInvestment($project, $this->investor('Cmd', '01710000091'), 123456);

        $this->artisan('investments:backfill-groups', ['--company' => $this->company->id, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 project(s) would move into groups. Nothing was changed.')
            ->assertSuccessful();
        $this->assertSame(0, InvestorGroup::withoutGlobalScopes()->count());

        $this->artisan('investments:backfill-groups', ['--company' => $this->company->id, '--force' => true])
            ->expectsOutputToContain('moved 1 project(s) into groups')
            ->assertSuccessful();

        app(CompanyContext::class)->set($this->company);
        $this->assertSame(1, InvestorGroup::query()->count());
        $this->assertSame($project->id, InvestorGroup::query()->firstOrFail()->projects()->firstOrFail()->id);
    }

    public function test_the_backfill_only_touches_the_current_company(): void
    {
        $mine = $this->legacyProject(['status' => 'running']);
        $this->legacyInvestment($mine, $this->investor('Mine', '01710000101'), 50000);

        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $theirs = $this->legacyProject(['company_id' => $other->id, 'status' => 'running']);
        $theirInvestor = Investor::query()->create(['company_id' => $other->id, 'name' => 'Theirs', 'phone' => '01710000102']);
        Investment::query()->create(['company_id' => $other->id, 'project_id' => $theirs->id, 'investor_id' => $theirInvestor->id, 'amount' => 80000, 'payment_method' => 'cash', 'invested_at' => '2026-03-01']);

        app(CompanyContext::class)->set($this->company);
        app(InvestorGroupBackfillService::class)->apply();

        $this->assertNotNull($mine->fresh()->group_id);
        $this->assertNull(InvestmentProject::withoutGlobalScopes()->find($theirs->id)->group_id);
        $this->assertSame(0, InvestorGroup::withoutGlobalScopes()->where('company_id', $other->id)->count());
    }

    // ------------------------------------------------------------ the admin

    public function test_staff_start_a_group_from_its_page_and_land_on_project_one(): void
    {
        $group = $this->group();
        $this->member($group, 250000);

        Livewire::test(ViewInvestorGroup::class, ['record' => $group->getRouteKey()])
            ->assertActionVisible('startGroup')
            ->callAction('startGroup', ['running_since' => '2026-03-10'])
            ->assertHasNoActionErrors();

        $group->refresh();
        $this->assertTrue($group->isRunning());
        $this->assertSame('G1-P01', $group->projects()->firstOrFail()->project_code);

        Livewire::test(ViewInvestorGroup::class, ['record' => $group->getRouteKey()])
            ->assertActionHidden('startGroup')
            ->assertActionVisible('closeGroup');
    }

    public function test_the_group_pages_work_and_the_members_tab_adds_a_deposit(): void
    {
        $group = $this->group(['receiving_fund_source_id' => $this->fundSource->id]);
        $investor = $this->investor('Tab Investor', '01710000111');

        Livewire::test(ListInvestorGroups::class)->assertStatus(200)->assertSee('G1');

        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $group, 'pageClass' => ViewInvestorGroup::class])
            ->assertTableActionVisible('create')
            ->mountTableAction('create')
            ->setTableActionData(['investor_id' => $investor->id, 'amount' => 125000, 'payment_method' => 'bank', 'invested_at' => '2026-03-09'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $member = $group->members()->firstOrFail();
        $this->assertSame(125000.0, (float) $member->amount);
        $this->assertNotNull($member->voucher_id);

        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-03-10'), $this->admin);

        Livewire::test(MembersRelationManager::class, ['ownerRecord' => $group->fresh(), 'pageClass' => ViewInvestorGroup::class])
            ->assertTableActionHidden('create')
            ->assertSee('Tab Investor');

        Livewire::test(ProjectsRelationManager::class, ['ownerRecord' => $group->fresh(), 'pageClass' => ViewInvestorGroup::class])
            ->assertSee('G1-P01');
    }

    public function test_a_group_project_offers_no_manual_investment_actions(): void
    {
        $group = $this->runningGroup();
        $project = $group->projects()->firstOrFail();
        $row = $project->investments()->firstOrFail();

        Livewire::test(ProjectInvestmentsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditInvestmentProject::class])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $row)
            ->assertTableActionHidden('delete', $row);

        $ungrouped = $this->legacyProject(['status' => 'running']);

        Livewire::test(ProjectInvestmentsRelationManager::class, ['ownerRecord' => $ungrouped, 'pageClass' => EditInvestmentProject::class])
            ->assertTableActionVisible('create');
    }

    public function test_a_group_project_page_and_list_name_the_group(): void
    {
        $group = $this->runningGroup();
        $project = $group->projects()->firstOrFail();
        $plain = $this->legacyProject(['name' => 'Plain Project']);

        Livewire::test(ViewInvestmentProject::class, ['record' => $project->getRouteKey()])
            ->assertStatus(200)
            ->assertSee('Group-1 · P1')
            ->assertSee('G1-P01');

        Livewire::test(ViewInvestmentProject::class, ['record' => $plain->getRouteKey()])
            ->assertStatus(200)
            ->assertDontSee('· P');

        Livewire::test(ListInvestmentProjects::class)
            ->assertCanSeeTableRecords([$project, $plain])
            ->filterTable('group_id', $group->id)
            ->assertCanSeeTableRecords([$project])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    public function test_only_staff_who_manage_investments_may_change_groups(): void
    {
        $group = $this->group();
        UserRole::query()->create(['name' => 'Investment Viewer', 'slug' => 'investment_viewer', 'is_active' => true, 'permissions' => ['investments.view']]);
        Auth::login(User::factory()->create(['role' => 'investment_viewer', 'is_active' => true]));

        $this->assertFalse(InvestorGroupResource::canCreate());
        $this->assertFalse(InvestorGroupResource::canEdit($group));

        Auth::login($this->admin);
        $this->assertTrue(InvestorGroupResource::canCreate());
        $this->assertTrue(InvestorGroupResource::canEdit($group));
    }

    public function test_a_group_can_be_created_from_the_form_with_a_generated_code(): void
    {
        Livewire::test(CreateInvestorGroup::class)
            ->fillForm(['cycle_months' => 2, 'investor_share_percent' => 40, 'channel_partner_share_percent' => 10, 'company_share_percent' => 50])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = InvestorGroup::query()->firstOrFail();
        $this->assertSame('G1', $group->group_code);
        $this->assertSame('Group-1', $group->name);
        $this->assertSame($this->admin->id, $group->created_by);
        $this->assertSame(InvestorGroup::STATUS_OPEN, $group->status);
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $overrides */
    private function group(array $overrides = []): InvestorGroup
    {
        return InvestorGroup::query()->create(array_merge(['company_id' => $this->company->id], $overrides));
    }

    /** A group already started on 2026-03-10 with one member (৳2,00,000). */
    private function runningGroup(): InvestorGroup
    {
        $group = $this->group();
        $this->member($group, 200000, $this->investor('Runner '.$group->group_code, '017'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT)));
        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-03-10'), $this->admin);

        return $group->fresh();
    }

    private function member(InvestorGroup $group, float $amount, ?Investor $investor = null): InvestorGroupMember
    {
        return app(InvestorGroupService::class)->addMember(
            $group,
            $investor ?? $this->investor('Member '.random_int(1000, 999999), '017'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT)),
            ['amount' => $amount, 'payment_method' => 'bank', 'invested_at' => '2026-03-01'],
            $this->admin,
        );
    }

    private function investor(string $name, string $phone): Investor
    {
        return Investor::query()->create(['company_id' => $this->company->id, 'name' => $name, 'phone' => $phone]);
    }

    /** A project the old way: no group. */
    private function legacyProject(array $overrides = []): InvestmentProject
    {
        return InvestmentProject::query()->create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Legacy Project',
            'duration_type' => '2_month',
            'start_date' => '2026-03-01',
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'status' => 'running',
        ], $overrides));
    }

    private function legacyInvestment(InvestmentProject $project, Investor $investor, float $amount): Investment
    {
        return Investment::query()->create([
            'company_id' => $project->company_id, 'project_id' => $project->id, 'investor_id' => $investor->id,
            'amount' => $amount, 'payment_method' => 'bank', 'invested_at' => '2026-03-01', 'received_by' => $this->admin->id,
        ]);
    }
}
