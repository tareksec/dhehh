<?php

namespace Tests\Feature;

use App\Filament\Investor\Pages\Dashboard;
use App\Filament\Investor\Pages\MyInvestments;
use App\Filament\Investor\Pages\Payouts;
use App\Models\Account;
use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestmentWithdrawalNotice;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\InvestorPortalAccount;
use App\Models\ProjectCostItem;
use App\Models\SettlementPayout;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorGroupCycleService;
use App\Services\Investment\InvestorGroupService;
use App\Services\Investment\PayoutCompletionService;
use App\Services\Investment\PayoutProofService;
use App\Services\Investment\SettlementService;
use App\Services\InvestorPortalAccountService;
use App\Services\InvestorPortalCards;
use App\Services\InvestorPortalService;
use App\Support\MoneyFormatter;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What an investor sees of a group (16_INVESTOR_GROUPS_PLAN.md, phase G5): a
 * progress bar per project, "Project N completed" when its two months are over,
 * the payout and its screenshot, and a card that leaves the dashboard once the
 * payout is PAID AND its screenshot is up. The totals count a member's money
 * once, however many projects it has been through.
 */
class InvestorPortalGroupViewTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    private FundSource $fundSource;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->company = Company::query()->create([
            'name' => 'View Co',
            'slug' => 'view-co',
            'domain' => 'view.example.test',
            'domain_verified' => true,
            'invoice_prefix' => 'VWC',
            'currency' => 'BDT',
            'timezone' => 'UTC',
            'is_active' => true,
            'investor_portal_enabled' => true,
        ]);
        StorefrontSetting::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'site_type' => StorefrontSetting::SITE_BLADE]);
        app(CompanyContext::class)->set($this->company);
        $this->staff = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->staff);

        $account = Account::query()->create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 100000000]);
        $this->fundSource = FundSource::query()->create(['name' => 'Payouts', 'type' => 'owner_investment', 'account_id' => $account->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- the cards

    public function test_a_running_project_shows_its_progress_towards_the_end_date(): void
    {
        [$group, $me] = $this->startedGroup([200000]);
        $account = $this->signIn($me);
        $cards = app(InvestorPortalCards::class);

        // Project 1 runs 1 Jan – 1 Mar (59 days). 30 Jan is 29 days in: 49%.
        $today = fn (string $day): CarbonImmutable => CarbonImmutable::parse($day);
        $this->assertSame(0, $cards->dashboard($account, $today('2026-01-01'))['groups'][0]['cards'][0]['progress']);
        $this->assertSame(49, $cards->dashboard($account, $today('2026-01-30'))['groups'][0]['cards'][0]['progress']);
        $this->assertSame(98, $cards->dashboard($account, $today('2026-02-28'))['groups'][0]['cards'][0]['progress']);

        Carbon::setTestNow('2026-01-30 10:00:00');
        Livewire::test(Dashboard::class)
            ->assertSee($group->group_code.' — '.$group->name)
            ->assertSee('Your money in this group: '.MoneyFormatter::currency(200000, 'BDT'))
            ->assertSee('49%')
            ->assertSee('Ends on 01 Mar 2026')
            ->assertSeeHtml('data-project-card="running"');
    }

    public function test_when_the_two_months_are_over_the_card_says_the_project_is_completed_and_the_next_one_runs(): void
    {
        [$group, $me] = $this->startedGroup([200000]);
        $this->advanceOn($group, '2026-03-01');
        $account = $this->signIn($me);
        Carbon::setTestNow('2026-03-05 10:00:00');

        $board = app(InvestorPortalCards::class)->dashboard($account, CarbonImmutable::parse('2026-03-05'));
        $cards = $board['groups'][0]['cards'];

        $this->assertCount(2, $cards, 'project 2 is running while project 1 waits for its settlement');
        $this->assertSame(['running', 'completed'], $cards->pluck('state')->all());
        $this->assertSame([2, 1], $cards->pluck('number')->all());
        $this->assertSame(100, $cards->firstWhere('number', 1)['progress']);
        $this->assertSame(6, $cards->firstWhere('number', 2)['progress'], '4 of 61 days into project 2');

        Livewire::test(Dashboard::class)
            ->assertSee('Project 1 completed')
            ->assertSeeHtml('data-project-card="completed"')
            ->assertSeeHtml('data-project-card="running"')
            ->assertSee('The company is calculating your profit.');
    }

    public function test_the_settled_project_shows_the_profit_and_that_payment_is_on_the_way(): void
    {
        [$group, $me, $payout] = $this->settledGroupProject(confirm: true);
        $this->signIn($me);

        $component = Livewire::test(Dashboard::class);
        $component->assertSeeHtml('data-project-card="payment_pending"')
            ->assertSee('Payment on the way')
            ->assertSee('Your profit')
            ->assertSee(MoneyFormatter::currency(32000, 'BDT'))
            ->assertSee('You receive');
        $this->assertSame(32000.0, (float) $payout->total_payout, 'a member who stays is paid the profit only');
    }

    public function test_a_paid_payout_without_its_screenshot_stays_and_with_it_the_project_leaves_the_dashboard(): void
    {
        [$group, $me, $payout, $other] = $this->settledGroupProject(confirm: true);
        $this->signIn($me);
        app(PayoutCompletionService::class)->markPaid($payout, $this->staff);

        Livewire::test(Dashboard::class)
            ->assertSeeHtml('data-project-card="paid"')
            ->assertSee('The company will add the payment screenshot here.')
            ->assertSee('Project 1 completed');

        app(PayoutProofService::class)->attach($payout->fresh(), $this->storedFile('slip.jpg'), $this->staff);

        Livewire::test(Dashboard::class)
            ->assertDontSeeHtml('data-project-card="paid"')
            ->assertDontSee('Project 1 completed')
            ->assertSeeHtml('data-project-card="running"')
            ->assertSee('Project 2'); // the group's next project is still there

        // The finished project is kept on the Payouts page.
        Livewire::test(Payouts::class)
            ->assertCanSeeTableRecords([$payout])
            ->assertTableActionVisible('paymentProof', $payout->fresh());

        // The other member's payout, unpaid, stays on their own dashboard.
        $this->signIn($other);
        Livewire::test(Dashboard::class)->assertSeeHtml('data-project-card="payment_pending"');
    }

    public function test_an_open_group_tells_the_investor_it_has_not_started(): void
    {
        $group = InvestorGroup::query()->create(['company_id' => $this->company->id]);
        $me = $this->investor('Waiting', '01860000001');
        $this->deposit($group, 150000, $me);
        $account = $this->signIn($me);

        Livewire::test(Dashboard::class)
            ->assertSee('Your money in this group: '.MoneyFormatter::currency(150000, 'BDT'))
            ->assertSee('This group has not started yet.')
            ->assertDontSeeHtml('data-project-card');

        // Money waiting in an open group is invested, but it is not at work yet.
        $portal = app(InvestorPortalService::class);
        $this->assertSame(150000.0, $portal->investorSummary($account)['total_invested']);
        $this->assertSame(0.0, $portal->capitalAtWork($account));
    }

    public function test_two_deposits_in_the_same_project_make_one_card(): void
    {
        $group = InvestorGroup::query()->create(['company_id' => $this->company->id]);
        $me = $this->investor('Twice', '01860000002');
        $this->deposit($group, 100000, $me);
        $this->deposit($group, 50000, $me);
        $this->deposit($group, 300000, $this->investor('Other', '01860000003'));
        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-01-01'), $this->staff);
        $account = $this->signIn($me);

        $cards = app(InvestorPortalCards::class)->dashboard($account, CarbonImmutable::parse('2026-01-10'))['groups'][0]['cards'];

        $this->assertCount(1, $cards);
        $this->assertSame(150000.0, $cards[0]['amount']);
    }

    public function test_nobody_sees_another_investors_cards(): void
    {
        [$group, $me, , $other] = $this->settledGroupProject(confirm: true);
        $stranger = $this->investor('Stranger', '01860000099');
        $account = $this->signIn($stranger);

        $board = app(InvestorPortalCards::class)->dashboard($account);

        $this->assertCount(0, $board['groups']);
        $this->assertCount(0, $board['legacy']);
    }

    // ----------------------------------------------------------- the numbers

    public function test_capital_at_work_and_total_invested_count_a_members_money_once(): void
    {
        [$group, $me, $payout] = $this->settledGroupProject(confirm: true);
        $account = $this->signIn($me);
        $portal = app(InvestorPortalService::class);

        // Two projects hold a copy of the same ৳2,00,000; it is one deposit and still at work.
        $this->assertSame(2, $me->investments()->count());
        $this->assertSame(200000.0, $portal->capitalAtWork($account));
        $this->assertSame(200000.0, $portal->investorSummary($account)['total_invested']);

        app(PayoutCompletionService::class)->markPaid($payout, $this->staff);
        $summary = $portal->investorSummary($account);

        $this->assertSame(200000.0, $portal->capitalAtWork($account), 'a settled project does not take a member\'s money out of the group');
        $this->assertSame(32000.0, $summary['profit_received']);
        $this->assertSame(32000.0, $summary['paid_out'], 'only the profit was paid');
        $this->assertSame(0.0, $summary['profit_pending']);
    }

    public function test_a_leaving_member_stays_in_capital_at_work_until_the_last_payout_is_paid(): void
    {
        [$group, $leaver, $stays] = $this->startedGroup([200000, 300000]);
        $this->notice($group, $leaver);
        $this->advanceOn($group, '2026-03-01');
        $first = $group->projects()->where('sequence_in_group', 1)->firstOrFail();
        ProjectCostItem::query()->create(['project_id' => $first->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 400000]);
        $settlement = app(SettlementService::class)->calculateAndSettle($first->fresh(), 600000, $this->staff->id, acknowledgeUnprovenCosts: true);
        $account = $this->signIn($leaver);
        $portal = app(InvestorPortalService::class);

        $this->assertSame(200000.0, $portal->capitalAtWork($account), 'before the settlement is confirmed it is still theirs at work');
        app(SettlementService::class)->confirmSettlement($settlement, $this->staff->id);
        $this->assertSame(200000.0, $portal->capitalAtWork($account), 'confirmed but not paid: still on its way');

        $payout = $settlement->payouts()->where('investor_id', $leaver->id)->firstOrFail();
        $this->assertEquals(200000 + 32000, (float) $payout->total_payout, 'principal + profit');
        app(PayoutCompletionService::class)->markPaid($payout, $this->staff);

        $this->assertSame(0.0, $portal->capitalAtWork($account), 'paid: nothing of theirs is at work any more');
        $this->assertSame(300000.0, $portal->capitalAtWork($this->signIn($stays)), 'the member who stays is unaffected');
    }

    public function test_a_projects_outside_any_group_still_count_the_old_way(): void
    {
        $me = $this->investor('Old Way', '01860000004');
        $project = InvestmentProject::query()->create([
            'company_id' => $this->company->id, 'name' => 'Old', 'duration_type' => '2_month', 'start_date' => '2026-01-01',
            'investor_share_percent' => 40, 'channel_partner_share_percent' => 10, 'company_share_percent' => 50, 'status' => 'running',
        ]);
        Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $me->id, 'amount' => 90000, 'payment_method' => 'bank', 'invested_at' => '2026-01-01']);
        $account = $this->signIn($me);
        $portal = app(InvestorPortalService::class);

        $this->assertSame(90000.0, $portal->capitalAtWork($account));
        $this->assertSame(90000.0, $portal->investorSummary($account)['total_invested']);

        $project->forceFill(['status' => 'closed'])->save();
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 50000]);
        app(SettlementService::class)->calculateAndSettle($project->fresh(), 100000, $this->staff->id, acknowledgeUnprovenCosts: true);
        $this->assertSame(0.0, $portal->capitalAtWork($account), 'an old-style project returns its principal when settled');
    }

    public function test_the_statement_lists_one_deposit_per_membership_and_balances_to_the_money_still_in_the_group(): void
    {
        [$group, $me, $payout] = $this->settledGroupProject(confirm: true);
        $account = $this->signIn($me);
        app(PayoutCompletionService::class)->markPaid($payout, $this->staff);

        $entries = app(InvestorPortalService::class)->statement($account);

        $this->assertSame(1, $entries->where('type', 'deposit')->count(), 'one deposit although two projects hold a copy');
        $this->assertSame(200000.0, $entries->firstWhere('type', 'deposit')['amount']);
        $this->assertSame(32000.0, $entries->firstWhere('type', 'profit')['amount']);
        $this->assertSame(-32000.0, $entries->firstWhere('type', 'payout')['amount']);
        $this->assertSame(200000.0, round($entries->sum('amount'), 2), 'deposit + profit - payout = the principal still invested');
    }

    public function test_a_partners_referred_capital_counts_members_once(): void
    {
        $partner = ChannelPartner::query()->create(['company_id' => $this->company->id, 'name' => 'Partner', 'phone' => '01860000060']);
        $group = InvestorGroup::query()->create(['company_id' => $this->company->id]);
        $referred = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Referred', 'phone' => '01860000061', 'channel_partner_id' => $partner->id]);
        $this->deposit($group, 400000, $referred);
        $this->deposit($group, 100000, $this->investor('Direct', '01860000062'));
        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-01-01'), $this->staff);
        $group->fresh()->update(['auto_cycle' => true]);
        $this->advanceOn($group, '2026-03-01');

        $partnerAccount = app(InvestorPortalAccountService::class)->ensureAccount($partner);
        $partnerAccount->forceFill(['status' => InvestorPortalAccount::STATUS_ACTIVE, 'password' => 'a-strong-password-1'])->save();

        $this->assertSame(400000.0, app(InvestorPortalService::class)->partnerSummary($partnerAccount)['referred_capital'], 'two projects hold a copy; the capital is counted once');
    }

    // ------------------------------------------------------------ the pages

    public function test_my_investments_names_the_group_and_calls_a_finished_cycle_completed(): void
    {
        [$group, $me] = $this->startedGroup([200000]);
        $this->advanceOn($group, '2026-03-01');
        $this->signIn($me);
        $first = $me->investments()->whereHas('project', fn ($query) => $query->where('sequence_in_group', 1))->firstOrFail();

        Livewire::test(MyInvestments::class)
            ->assertCanSeeTableRecords($me->investments()->get())
            ->assertSee($group->name.' · Project 1')
            ->assertSee($group->name.' · Project 2')
            ->assertSee('Completed');

        $this->assertSame('closed', $first->project->status);
    }

    public function test_the_payouts_page_says_when_the_capital_stays_invested(): void
    {
        [$group, $me, $payout] = $this->settledGroupProject(confirm: true);
        $this->signIn($me);

        Livewire::test(Payouts::class)
            ->assertCanSeeTableRecords([$payout])
            ->assertSee('Stays invested')
            ->assertSee($group->name.' · Project 1');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A group started on 2026-01-01 with one investor per amount and automatic cycles on.
     *
     * @param  list<float|int>  $amounts
     * @return array{0: InvestorGroup, 1: Investor, 2?: Investor}
     */
    private function startedGroup(array $amounts): array
    {
        $group = InvestorGroup::query()->create(['company_id' => $this->company->id, 'payout_fund_source_id' => $this->fundSource->id]);
        $investors = [];

        foreach ($amounts as $index => $amount) {
            $investors[] = $investor = $this->investor('Member '.($index + 1), '01861'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT));
            $this->deposit($group, $amount, $investor);
        }

        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-01-01'), $this->staff);
        $group->fresh()->update(['auto_cycle' => true]);

        return [$group->fresh(), ...$investors];
    }

    /**
     * Two members (৳2,00,000 and ৳3,00,000). Project 1 ended on 1 March and was settled at a ৳2,00,000
     * profit, so the pool is ৳80,000 and the first member's share is ৳32,000. Project 2 now runs.
     *
     * @return array{0: InvestorGroup, 1: Investor, 2: SettlementPayout, 3: Investor}
     */
    private function settledGroupProject(bool $confirm): array
    {
        [$group, $me, $other] = $this->startedGroup([200000, 300000]);
        $this->advanceOn($group, '2026-03-01');
        $first = $group->projects()->where('sequence_in_group', 1)->firstOrFail();
        ProjectCostItem::query()->create(['project_id' => $first->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 400000]);
        $settlement = app(SettlementService::class)->calculateAndSettle($first->fresh(), 600000, $this->staff->id, acknowledgeUnprovenCosts: true);

        if ($confirm) {
            app(SettlementService::class)->confirmSettlement($settlement, $this->staff->id);
        }

        Carbon::setTestNow('2026-03-05 10:00:00');

        return [$group->fresh(), $me, $settlement->payouts()->where('investor_id', $me->id)->firstOrFail(), $other];
    }

    private function deposit(InvestorGroup $group, float $amount, Investor $investor): InvestorGroupMember
    {
        return app(InvestorGroupService::class)->addMember($group, $investor, ['amount' => $amount, 'payment_method' => 'bank', 'invested_at' => '2026-01-01'], $this->staff);
    }

    private function investor(string $name, string $phone): Investor
    {
        return Investor::query()->create(['company_id' => $this->company->id, 'name' => $name, 'phone' => $phone]);
    }

    private function notice(InvestorGroup $group, Investor $investor): void
    {
        InvestmentWithdrawalNotice::query()->create(['company_id' => $this->company->id, 'investor_id' => $investor->id, 'group_id' => $group->id, 'notice_given_at' => '2025-12-01']);
    }

    private function advanceOn(InvestorGroup $group, string $day): array
    {
        Carbon::setTestNow($day.' 09:00:00');

        return app(InvestorGroupCycleService::class)->advance($group->fresh(), Carbon::parse($day));
    }

    private function storedFile(string $name): string
    {
        $path = $this->company->storageRoot().'/private/payout-proofs/'.$name;
        Storage::disk('local')->put($path, 'screenshot');

        return $path;
    }

    /** Signs the investor in to the portal, as the portal pages need. */
    private function signIn(Investor $investor): InvestorPortalAccount
    {
        $account = app(InvestorPortalAccountService::class)->ensureAccount($investor);
        $account->forceFill(['status' => InvestorPortalAccount::STATUS_ACTIVE, 'password' => 'a-strong-password-1'])->save();
        $request = Request::create('/investor');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        app(CompanyContext::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('investor'));
        $this->actingAs($account, 'investor');

        return $account;
    }
}
