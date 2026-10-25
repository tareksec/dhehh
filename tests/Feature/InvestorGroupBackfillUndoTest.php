<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\ProjectCostItem;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorGroupBackfillService;
use App\Services\Investment\InvestorGroupBackfillUndoService;
use App\Services\Investment\InvestorGroupService;
use App\Services\Investment\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The way back from the backfill (owner 2026-10-25: "delete all the groups and
 * start again"): a backfilled group goes out cleanly, the project and its
 * investments stay as they were, and anything that is no longer the backfill's
 * state (a real group, a second project, a settlement, a withdrawal notice) is
 * left alone and named.
 */
class InvestorGroupBackfillUndoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 10:00:00');

        $this->company = Company::query()->create([
            'name' => 'Undo Co',
            'slug' => 'undo-co',
            'invoice_prefix' => 'UND',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->admin);

        $account = Account::query()->create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 100000000]);
        FundSource::query()->create(['name' => 'Investor money', 'type' => 'owner_investment', 'account_id' => $account->id]);
    }

    public function test_the_backfill_gives_the_project_its_group_code_and_keeps_the_old_one_in_the_audit_log(): void
    {
        $project = $this->legacyProject(['start_date' => '2026-02-01']);
        $this->legacyInvestment($project, $this->investor('A', '01710000201'), 100000);
        $oldCode = $project->project_code;

        $plan = app(InvestorGroupBackfillService::class)->plan();
        $this->assertSame('G1-P01', $plan['rows'][0]['new_project_code']);

        app(InvestorGroupBackfillService::class)->apply();

        $this->assertSame('G1-P01', $project->fresh()->project_code);
        $log = AuditLog::query()->where('action', 'investor_group_backfilled')->firstOrFail();
        $this->assertSame($oldCode, $log->new_values['previous_project_code']);
    }

    public function test_undo_takes_a_backfilled_group_out_and_leaves_the_project_and_its_money_as_they_were(): void
    {
        $project = $this->legacyProject(['start_date' => '2026-02-01']);
        $a = $this->legacyInvestment($project, $this->investor('A', '01710000211'), 100000);
        $b = $this->legacyInvestment($project, $this->investor('B', '01710000212'), 250000);
        $oldCode = $project->project_code;
        $vouchers = Voucher::query()->count();

        app(InvestorGroupBackfillService::class)->apply();
        $this->assertSame(1, InvestorGroup::query()->count());
        $this->assertSame(2, InvestorGroupMember::query()->count());

        $service = app(InvestorGroupBackfillUndoService::class);
        $plan = $service->plan();

        $this->assertCount(1, $plan['rows']);
        $this->assertSame($oldCode, $plan['rows'][0]['restore_code']);
        $this->assertSame(1, InvestorGroup::query()->count(), 'planning changes nothing');

        $service->apply();

        $project->refresh();
        $this->assertSame(0, InvestorGroup::query()->count());
        $this->assertSame(0, InvestorGroupMember::query()->count());
        $this->assertNull($project->group_id);
        $this->assertNull($project->sequence_in_group);
        $this->assertSame($oldCode, $project->project_code, 'the old code comes back');
        $this->assertSame('running', $project->status);

        $this->assertEquals([100000.0, 250000.0], Investment::query()->where('project_id', $project->id)->orderBy('id')->pluck('amount')->map(fn ($amount): float => (float) $amount)->all());
        $this->assertSame([$a->id, $b->id], Investment::query()->where('project_id', $project->id)->orderBy('id')->pluck('id')->all(), 'the investments are the same rows');
        $this->assertSame(0, Investment::query()->whereNotNull('group_member_id')->count());
        $this->assertSame($vouchers, Voucher::query()->count(), 'no voucher is booked or removed');
        $this->assertSame(1, AuditLog::query()->where('action', 'investor_group_backfill_undone')->count());

        // Ready to start again, and the numbering starts from G1 once more.
        $again = app(InvestorGroupBackfillService::class)->plan();
        $this->assertSame(['G1'], array_column($again['rows'], 'group_code'));
        $this->assertSame(['G1-P01'], array_column($again['rows'], 'new_project_code'));
    }

    public function test_a_real_group_is_left_alone_and_the_reason_is_named(): void
    {
        $real = InvestorGroup::query()->create(['company_id' => $this->company->id, 'name' => 'The real one']);
        app(InvestorGroupService::class)->addMember($real, $this->investor('Real', '01710000221'), ['amount' => 500000, 'payment_method' => 'bank', 'invested_at' => '2026-03-01'], $this->admin);
        app(InvestorGroupService::class)->startGroup($real, Carbon::parse('2026-03-10'), $this->admin);

        $project = $this->legacyProject(['start_date' => '2026-02-01']);
        $this->legacyInvestment($project, $this->investor('Old', '01710000222'), 100000);
        app(InvestorGroupBackfillService::class)->apply();

        $this->assertSame(['G1', 'G2'], InvestorGroup::query()->orderBy('id')->pluck('group_code')->all());

        $plan = app(InvestorGroupBackfillUndoService::class)->plan();
        $this->assertSame(1, count($plan['rows']));
        $this->assertCount(1, $plan['refused']);
        $this->assertStringContainsString('G1', $plan['refused'][0]['group']);
        $this->assertStringContainsString('not made by the backfill', $plan['refused'][0]['reason']);

        app(InvestorGroupBackfillUndoService::class)->apply();

        $this->assertSame(['G1'], InvestorGroup::query()->pluck('group_code')->all());
        $this->assertSame(1, $real->members()->count());
        $this->assertSame(1, $real->projects()->count());
        $this->assertNull($project->fresh()->group_id);
    }

    public function test_a_group_that_has_moved_on_is_refused(): void
    {
        // A second project already exists.
        $moved = $this->legacyProject(['start_date' => '2026-01-05']);
        $this->legacyInvestment($moved, $this->investor('M', '01710000231'), 100000);

        // A settlement was made.
        $settled = $this->legacyProject(['status' => 'closed', 'start_date' => '2026-01-10']);
        $this->legacyInvestment($settled, $this->investor('S', '01710000232'), 200000);
        ProjectCostItem::query()->create(['project_id' => $settled->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 150000]);
        app(SettlementService::class)->calculateAndSettle($settled, 250000, $this->admin->id, acknowledgeUnprovenCosts: true);

        // A withdrawal notice points at the group.
        $noticed = $this->legacyProject(['start_date' => '2026-01-15']);
        $noticedInvestor = $this->investor('N', '01710000233');
        $this->legacyInvestment($noticed, $noticedInvestor, 300000);

        app(InvestorGroupBackfillService::class)->apply();

        $groupOf = fn (InvestmentProject $project): InvestorGroup => InvestorGroup::query()->findOrFail($project->fresh()->group_id);

        app(InvestorGroupService::class)->createProject($groupOf($moved), 2);

        DB::table('investment_withdrawal_notices')->insert([
            'company_id' => $this->company->id,
            'investor_id' => $noticedInvestor->id,
            'group_id' => $groupOf($noticed)->id,
            'status' => 'pending',
            'notice_given_at' => '2026-03-01',
            'effective_at' => '2026-05-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = app(InvestorGroupBackfillUndoService::class)->plan();

        $this->assertSame([], $plan['rows']);
        $this->assertCount(3, $plan['refused']);
        $reasons = implode(' | ', array_column($plan['refused'], 'reason'));
        $this->assertStringContainsString('more than Project 1', $reasons);
        $this->assertStringContainsString('already has a settlement', $reasons);
        $this->assertStringContainsString('withdrawal notice', $reasons);

        app(InvestorGroupBackfillUndoService::class)->apply();
        $this->assertSame(3, InvestorGroup::query()->count(), 'nothing was removed');
    }

    public function test_an_empty_group_is_deleted_only_when_asked(): void
    {
        $empty = InvestorGroup::query()->create(['company_id' => $this->company->id, 'name' => 'Test group']);
        $service = app(InvestorGroupBackfillUndoService::class);

        $plan = $service->plan();
        $this->assertSame([], $plan['empty']);
        $this->assertStringContainsString('--include-empty', $plan['refused'][0]['reason']);
        $service->apply();
        $this->assertTrue(InvestorGroup::query()->whereKey($empty->id)->exists());

        $this->assertCount(1, $service->plan([], true)['empty']);
        $service->apply([], true);
        $this->assertFalse(InvestorGroup::query()->whereKey($empty->id)->exists());
    }

    public function test_the_command_dry_run_changes_nothing_and_the_real_run_asks_no_questions_with_force(): void
    {
        $project = $this->legacyProject(['start_date' => '2026-02-01']);
        $this->legacyInvestment($project, $this->investor('Cmd', '01710000241'), 123456);
        app(InvestorGroupBackfillService::class)->apply();

        $this->artisan('investments:undo-backfill-groups', ['--company' => $this->company->id, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 group(s) would be removed. Nothing was changed.')
            ->assertSuccessful();
        $this->assertSame(1, InvestorGroup::withoutGlobalScopes()->count());

        $this->artisan('investments:undo-backfill-groups', ['--company' => $this->company->id, '--force' => true])
            ->expectsOutputToContain('removed 1 backfilled group(s)')
            ->assertSuccessful();

        $this->assertSame(0, InvestorGroup::withoutGlobalScopes()->count());
        $this->assertNull(InvestmentProject::withoutGlobalScopes()->findOrFail($project->id)->group_id);
    }

    public function test_the_undo_only_touches_the_current_company(): void
    {
        $mine = $this->legacyProject(['start_date' => '2026-02-01']);
        $this->legacyInvestment($mine, $this->investor('Mine', '01710000251'), 50000);
        app(InvestorGroupBackfillService::class)->apply();

        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-undo', 'invoice_prefix' => 'OTU', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $theirs = $this->legacyProject(['company_id' => $other->id, 'status' => 'running', 'start_date' => '2026-02-02']);
        $theirInvestor = Investor::query()->create(['company_id' => $other->id, 'name' => 'Theirs', 'phone' => '01710000252']);
        Investment::query()->create(['company_id' => $other->id, 'project_id' => $theirs->id, 'investor_id' => $theirInvestor->id, 'amount' => 80000, 'payment_method' => 'cash', 'invested_at' => '2026-03-01']);
        app(InvestorGroupBackfillService::class)->apply();

        app(CompanyContext::class)->set($this->company);
        app(InvestorGroupBackfillUndoService::class)->apply();

        $this->assertNull($mine->fresh()->group_id);
        $this->assertNotNull(InvestmentProject::withoutGlobalScopes()->find($theirs->id)->group_id, 'the other company keeps its group');
        $this->assertSame(1, InvestorGroup::withoutGlobalScopes()->where('company_id', $other->id)->count());
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
