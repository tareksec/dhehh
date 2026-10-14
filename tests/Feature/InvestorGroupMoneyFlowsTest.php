<?php

namespace Tests\Feature;

use App\Filament\Investor\Pages\Requests;
use App\Filament\Resources\InvestorDrafts\InvestorDraftResource;
use App\Filament\Resources\InvestorDrafts\Pages\ListInvestorDrafts;
use App\Filament\Resources\InvestorRequests\Pages\ListInvestorRequests;
use App\Filament\Resources\ProjectSettlements\Pages\ViewProjectSettlement;
use App\Filament\Resources\ProjectSettlements\RelationManagers\PayoutsRelationManager;
use App\Models\Account;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestmentWithdrawalNotice;
use App\Models\Investor;
use App\Models\InvestorCycleElection;
use App\Models\InvestorDraft;
use App\Models\InvestorGroup;
use App\Models\InvestorGroupMember;
use App\Models\InvestorPortalAccount;
use App\Models\InvestorRequest;
use App\Models\ProjectCostItem;
use App\Models\SettlementPayout;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CompanyContext;
use App\Services\Investment\CycleElectionService;
use App\Services\Investment\InvestmentTargets;
use App\Services\Investment\InvestorGroupService;
use App\Services\Investment\InvestorImportService;
use App\Services\Investment\SettlementService;
use App\Services\InvestorPortalAccountService;
use App\Services\InvestorRequestService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Group-based investment, phase G3 (16_INVESTOR_GROUPS_PLAN.md): new money
 * (top-up, reinvest, imports and sign-ups) joins an OPEN GROUP as a new member
 * and never a running one; a withdrawal notice is about a group. Everything
 * that books money is checked once, and only once.
 */
class InvestorGroupMoneyFlowsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    private FundSource $fundSource;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 10:00:00');

        $this->company = Company::query()->create([
            'name' => 'Money Co',
            'slug' => 'money-co',
            'domain' => 'money.example.test',
            'domain_verified' => true,
            'invoice_prefix' => 'MNY',
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
        $this->fundSource = FundSource::query()->create(['name' => 'Investor money', 'type' => 'owner_investment', 'account_id' => $account->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- the targets

    public function test_only_open_groups_and_loose_open_projects_take_new_money(): void
    {
        $open = $this->group();
        $running = $this->runningGroup();
        $closedGroup = $this->group();
        $closedGroup->forceFill(['status' => InvestorGroup::STATUS_CLOSED])->save();
        $loose = $this->looseProject('running');
        $this->looseProject('closed');
        $targets = app(InvestmentTargets::class);

        $keys = $targets->open()->keys()->all();

        $this->assertSame(['g'.$open->id, 'p'.$loose->id], $keys);
        $this->assertNull($targets->find('g'.$running->id), 'a running group takes no new money');
        $this->assertNull($targets->find('g'.$closedGroup->id));
        $this->assertNull($targets->find('p'.$running->projects()->firstOrFail()->id), 'a project of a group is not a target');
        $this->assertSame($open->group_code.' — '.$open->name, $targets->label($open));
        $this->assertSame(['g'.$open->id => $targets->label($open), 'p'.$loose->id => $targets->label($loose)], $targets->options());
    }

    // ------------------------------------------------------------- top-up

    public function test_a_top_up_asks_for_an_open_group_and_is_added_as_a_new_member_when_approved(): void
    {
        $group = $this->group(['receiving_fund_source_id' => $this->fundSource->id]);
        $investor = $this->investor('Top Up', '01830000001');
        $account = $this->activeAccount($investor);
        $service = app(InvestorRequestService::class);

        $request = $service->create($account, InvestorRequest::TYPE_TOP_UP, ['target' => 'g'.$group->id, 'amount' => 300000, 'payment_method' => 'bank', 'payment_reference' => 'TRX-77']);

        $this->assertSame($group->id, $request->group_id);
        $this->assertNull($request->project_id);

        try {
            $service->create($account, InvestorRequest::TYPE_TOP_UP, ['target' => 'g'.$group->id, 'amount' => 1000, 'payment_method' => 'bank']);
            $this->fail('A second pending top-up for the same group was accepted.');
        } catch (ValidationException) {
        }

        $service->approve($request, $this->staff, ['amount' => 300000, 'invested_at' => '2026-03-09', 'payment_method' => 'bank']);

        $request->refresh();
        $member = $request->result;
        $this->assertSame(InvestorRequest::STATUS_APPROVED, $request->status);
        $this->assertInstanceOf(InvestorGroupMember::class, $member);
        $this->assertSame($group->id, $member->group_id);
        $this->assertSame($investor->id, $member->investor_id);
        $this->assertSame(300000.0, (float) $member->amount);
        $this->assertSame('TRX-77', $member->payment_reference);
        $this->assertSame('2026-03-09', $member->invested_at->toDateString());
        $this->assertSame(1, Voucher::query()->count(), 'the money is booked once');
        $this->assertSame('capital_investment', $member->voucher->transaction_type);
        $this->assertSame(300000.0, (float) $member->voucher->amount);
    }

    public function test_new_money_never_joins_a_running_group(): void
    {
        $running = $this->runningGroup();
        $account = $this->activeAccount($this->investor('Early Bird', '01830000002'));
        $service = app(InvestorRequestService::class);

        try {
            $service->create($account, InvestorRequest::TYPE_TOP_UP, ['target' => 'g'.$running->id, 'amount' => 10000, 'payment_method' => 'bank']);
            $this->fail('A top-up into a running group was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.target', $exception->errors());
        }

        // The old field names still work for a loose project, and are refused for a group's project.
        try {
            $service->create($account, InvestorRequest::TYPE_TOP_UP, ['project_id' => $running->projects()->firstOrFail()->id, 'amount' => 10000, 'payment_method' => 'bank']);
            $this->fail('A top-up straight into a group project was accepted.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, InvestorRequest::query()->count());
    }

    public function test_when_the_group_started_before_approval_staff_pick_another_open_group(): void
    {
        $first = $this->group();
        $second = $this->group();
        $this->member($first, 100000);
        $account = $this->activeAccount($this->investor('Slow Money', '01830000003'));
        $service = app(InvestorRequestService::class);
        $request = $service->create($account, InvestorRequest::TYPE_TOP_UP, ['target' => 'g'.$first->id, 'amount' => 50000, 'payment_method' => 'cash']);

        app(InvestorGroupService::class)->startGroup($first, Carbon::parse('2026-03-10'), $this->staff);

        try {
            $service->approve($request, $this->staff, ['amount' => 50000, 'invested_at' => '2026-03-10', 'payment_method' => 'cash']);
            $this->fail('Money was added to a group that already started.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target', $exception->errors());
        }

        $this->assertSame(InvestorRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertSame(1, $first->members()->count());

        $service->approve($request, $this->staff, ['amount' => 50000, 'invested_at' => '2026-03-10', 'payment_method' => 'cash', 'target' => 'g'.$second->id]);

        $this->assertSame(1, $second->members()->count());
        $this->assertSame(1, $first->members()->count(), 'the running group is untouched');
    }

    public function test_a_loose_project_still_takes_a_top_up_the_old_way(): void
    {
        $project = $this->looseProject('running');
        $investor = $this->investor('Old Way', '01830000004');
        $account = $this->activeAccount($investor);
        $service = app(InvestorRequestService::class);

        $request = $service->create($account, InvestorRequest::TYPE_TOP_UP, ['project_id' => $project->id, 'amount' => 40000, 'payment_method' => 'bank']);
        $service->approve($request, $this->staff, ['amount' => 40000, 'invested_at' => '2026-03-10', 'payment_method' => 'bank']);

        $this->assertInstanceOf(Investment::class, $request->fresh()->result);
        $this->assertSame($project->id, $request->fresh()->result->project_id);
    }

    public function test_the_second_deposit_in_another_group_leaves_the_running_group_untouched(): void
    {
        $running = $this->runningGroup();
        $investor = $running->members()->firstOrFail()->investor;
        $project = $running->projects()->firstOrFail();
        $newGroup = $this->group();

        app(InvestmentTargets::class)->place($newGroup, $investor, ['amount' => 1000000, 'payment_method' => 'bank'], $this->staff);

        $this->assertSame(2, InvestorGroupMember::query()->where('investor_id', $investor->id)->count(), 'one investor, two groups');
        $this->assertSame(200000.0, $running->totalActiveDeposits(), 'the old group keeps its amount');
        $this->assertSame(200000.0, $project->fresh()->totalInvested());
        $this->assertSame(1000000.0, $newGroup->totalActiveDeposits());
    }

    // ---------------------------------------------------------- withdrawal

    public function test_a_withdrawal_notice_is_about_a_group_the_investor_is_in(): void
    {
        $group = $this->group();
        $other = $this->group();
        $investor = $this->investor('Leaver', '01830000005');
        $this->member($group, 100000, $investor);
        $account = $this->activeAccount($investor);
        $service = app(InvestorRequestService::class);

        try {
            $service->create($account, InvestorRequest::TYPE_WITHDRAWAL, ['target' => 'g'.$other->id]);
            $this->fail('A notice for a group without their money was accepted.');
        } catch (ValidationException) {
        }

        $request = $service->create($account, InvestorRequest::TYPE_WITHDRAWAL, ['target' => 'g'.$group->id, 'message' => 'Need it back']);
        $this->assertSame($group->id, $request->group_id);

        Carbon::setTestNow('2026-03-13 10:00:00');
        $service->approve($request, $this->staff);

        $notice = InvestmentWithdrawalNotice::query()->firstOrFail();
        $this->assertSame($group->id, $notice->group_id);
        $this->assertNull($notice->project_id);
        $this->assertSame('2026-03-10', $notice->notice_given_at->toDateString(), 'the notice runs from the day it was sent');
        $this->assertSame('2026-05-09', $notice->effective_at->toDateString(), '60 days later');

        try {
            $service->create($account, InvestorRequest::TYPE_WITHDRAWAL, ['target' => 'g'.$group->id]);
            $this->fail('A second notice for the same group was accepted.');
        } catch (ValidationException) {
        }
    }

    // ----------------------------------------------------------- reinvest

    public function test_a_reinvested_payout_joins_an_open_group_as_a_new_member(): void
    {
        [$payout, $investor] = $this->settledPayout(60000, 40000, 100000, 200000);
        $target = $this->group(['receiving_fund_source_id' => $this->fundSource->id]);
        $vouchersBefore = Voucher::query()->count();

        $election = app(CycleElectionService::class)->recordElection($payout, 'partial_reinvest', 10000, $target, $this->staff->id);

        $member = $election->reinvestmentMember;
        $this->assertSame($target->id, $election->next_group_id);
        $this->assertNull($election->next_project_id);
        $this->assertNull($election->reinvestment_id);
        $this->assertInstanceOf(InvestorGroupMember::class, $member);
        $this->assertSame($investor->id, $member->investor_id);
        $this->assertSame(10000.0, (float) $member->amount);
        $this->assertSame('other', $member->payment_method);
        $this->assertStringContainsString("settlement payout #{$payout->id}", (string) $member->payment_reference);
        $this->assertSame($vouchersBefore + 1, Voucher::query()->count(), 'one capital voucher for the reinvested money');
    }

    public function test_a_payout_cannot_be_reinvested_into_a_running_group_or_a_group_project(): void
    {
        [$payout] = $this->settledPayout(60000, 40000, 100000, 200000);
        $running = $this->runningGroup();
        $service = app(CycleElectionService::class);

        foreach ([$running, $running->projects()->firstOrFail()] as $closedDoor) {
            try {
                $service->recordElection($payout, 'reinvest', null, $closedDoor, $this->staff->id);
                $this->fail('Money went into a group that is not open.');
            } catch (RuntimeException) {
            }
        }

        try {
            $service->recordElection($payout, 'reinvest', null, null, $this->staff->id);
            $this->fail('A reinvest without a target was accepted.');
        } catch (RuntimeException) {
        }

        $this->assertNull($payout->fresh()->cycleElection);
        $this->assertSame(0, InvestorGroupMember::query()->where('investor_id', $payout->investor_id)->count());
    }

    public function test_a_reinvest_request_into_an_open_group_runs_through_approval(): void
    {
        [$payout, $investor] = $this->settledPayout(60000, 40000, 100000, 200000);
        $target = $this->group();
        $account = $this->activeAccount($investor);
        $service = app(InvestorRequestService::class);

        $request = $service->create($account, InvestorRequest::TYPE_REINVEST, ['settlement_payout_id' => $payout->id, 'election' => 'reinvest', 'next_target' => 'g'.$target->id]);
        $this->assertSame($target->id, $request->group_id);

        $service->approve($request, $this->staff);

        $election = InvestorCycleElection::query()->firstOrFail();
        $this->assertSame($target->id, $election->next_group_id);
        $this->assertEquals((float) $payout->total_payout, (float) $election->reinvestmentMember->amount, 'a full reinvest rolls the whole payout');
    }

    public function test_staff_record_a_reinvest_election_into_an_open_group_from_the_settlement(): void
    {
        [$payout] = $this->settledPayout(60000, 40000, 100000, 200000);
        $target = $this->group();
        $settlement = $payout->settlement;

        Livewire::test(PayoutsRelationManager::class, ['ownerRecord' => $settlement, 'pageClass' => ViewProjectSettlement::class])
            ->mountTableAction('election', $payout)
            ->setTableActionData(['election' => 'partial_reinvest', 'reinvest_amount' => 5000, 'next_target' => 'g'.$target->id])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame(5000.0, (float) $target->members()->firstOrFail()->amount);
    }

    // ------------------------------------------------------- imports and drafts

    public function test_a_confirmed_draft_with_a_group_adds_a_member_and_keeps_the_stamp_and_cheque_for_the_deed(): void
    {
        $group = $this->group(['receiving_fund_source_id' => $this->fundSource->id]);
        $draft = $this->draft(['group_id' => $group->id, 'name' => 'Draft Investor', 'phone' => '01830000011', 'nid_number' => '1234567890123', 'stamp_number' => '9363512, 9363513', 'cheque_number' => 'CHQ-0011']);

        $this->assertSame([], $draft->blockingIssues());
        $investor = app(InvestorImportService::class)->confirm($draft);

        $member = InvestorGroupMember::query()->where('investor_id', $investor->id)->sole();
        $this->assertSame($group->id, $member->group_id);
        $this->assertSame(200000.0, (float) $member->amount);
        $this->assertSame(['9363512', '9363513'], $member->paperwork['stamp_serial_numbers']);
        $this->assertSame('CHQ-0011', $member->paperwork['cheque_number']);
        $this->assertSame(0, Investment::query()->where('investor_id', $investor->id)->count(), 'a group deposit is not a project investment');
        $this->assertSame(1, Voucher::query()->count());
        $this->assertSame(0, InvestorDraft::query()->count(), 'the draft is gone');

        // When the group starts, the deed paperwork lands on the member's first project row, once.
        $project = app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-03-10'), $this->staff);
        $row = $project->investments()->sole();
        $this->assertSame('CHQ-0011', $row->securityInstruments()->sole()->cheque_number);
        $this->assertSame(1, Voucher::query()->count(), 'starting the group books nothing more');

        $second = app(InvestorGroupService::class)->createProject($group->fresh(), 2);
        app(InvestorGroupService::class)->snapshotMembers($group->fresh(), $second);
        $this->assertSame(0, $second->investments()->sole()->securityInstruments()->count(), 'the deed is not repeated for every project');
    }

    public function test_a_draft_cannot_pick_a_running_group_or_both_a_group_and_a_project(): void
    {
        $open = $this->group();
        $running = $this->runningGroup();
        $project = $this->looseProject('running');

        $toRunning = $this->draft(['group_id' => $running->id, 'name' => 'To Running', 'phone' => '01830000012', 'nid_number' => '2234567890123']);
        $this->assertCount(1, array_filter($toRunning->blockingIssues(), fn (string $issue): bool => str_contains($issue, 'takes no new members')));

        try {
            app(InvestorImportService::class)->confirm($toRunning);
            $this->fail('A draft was confirmed into a running group.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('group_id', $exception->errors());
        }

        $both = $this->draft(['group_id' => $open->id, 'project_id' => $project->id, 'name' => 'Both', 'phone' => '01830000013', 'nid_number' => '3234567890123']);
        $this->assertContains(__('Pick a group or a project for the investment amount, not both.'), $both->blockingIssues());

        try {
            app(InvestorImportService::class)->confirm($both);
            $this->fail('A draft with a group and a project was confirmed.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, InvestorGroupMember::query()->where('group_id', $open->id)->count());
        $this->assertSame(2, InvestorDraft::query()->count());
    }

    public function test_a_draft_into_a_loose_project_still_works_the_old_way(): void
    {
        $project = $this->looseProject('running');
        $draft = $this->draft(['project_id' => $project->id, 'name' => 'Old Draft', 'phone' => '01830000014', 'nid_number' => '4234567890123', 'cheque_number' => 'CHQ-OLD']);

        $investor = app(InvestorImportService::class)->confirm($draft);

        $investment = Investment::query()->where('investor_id', $investor->id)->sole();
        $this->assertSame($project->id, $investment->project_id);
        $this->assertSame('CHQ-OLD', $investment->securityInstruments()->sole()->cheque_number);
    }

    public function test_the_review_screen_offers_open_groups(): void
    {
        $open = $this->group();
        $this->runningGroup();
        $draft = $this->draft(['name' => 'Review Me', 'phone' => '01830000015', 'nid_number' => '5234567890123']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListInvestorDrafts::class)
            ->assertCanSeeTableRecords([$draft])
            ->assertTableBulkActionExists('setInvestment');

        $options = collect(InvestorDraftResource::investmentFields(withAmount: true))
            ->first(fn ($field): bool => $field->getName() === 'group_id')
            ->getOptions();

        $this->assertSame([$open->id => $open->group_code.' — '.$open->name], $options, 'only the open group is offered');
    }

    // ------------------------------------------------------------ the pages

    public function test_the_portal_new_request_form_sends_a_top_up_to_an_open_group(): void
    {
        $group = $this->group();
        $investor = $this->investor('Portal Person', '01830000021');
        $account = $this->ownSessionOf($investor);

        Livewire::test(Requests::class)
            ->callAction('new', ['type' => InvestorRequest::TYPE_TOP_UP, 'target' => 'g'.$group->id, 'amount' => 75000, 'payment_method' => 'bank', 'sent_on' => '2026-03-09'])
            ->assertHasNoActionErrors();

        $request = InvestorRequest::query()->where('portal_account_id', $account->id)->sole();
        $this->assertSame($group->id, $request->group_id);
        $this->assertSame(75000.0, (float) $request->amount);

        Livewire::test(Requests::class)->assertSee($group->group_code.' — '.$group->name);
    }

    public function test_the_portal_withdrawal_form_lists_only_the_groups_the_person_is_in(): void
    {
        $mine = $this->group();
        $notMine = $this->group();
        $investor = $this->investor('Member Person', '01830000022');
        $this->member($mine, 100000, $investor);
        $this->ownSessionOf($investor);

        Livewire::test(Requests::class)
            ->callAction('new', ['type' => InvestorRequest::TYPE_WITHDRAWAL, 'withdrawal_target' => 'g'.$mine->id])
            ->assertHasNoActionErrors();

        $this->assertSame($mine->id, InvestorRequest::query()->sole()->group_id);

        Livewire::test(Requests::class)
            ->callAction('new', ['type' => InvestorRequest::TYPE_WITHDRAWAL, 'withdrawal_target' => 'g'.$notMine->id])
            ->assertHasErrors();
    }

    public function test_staff_approve_a_group_top_up_from_the_request_queue(): void
    {
        $group = $this->group();
        $investor = $this->investor('Queue Person', '01830000023');
        $account = $this->activeAccount($investor);
        $request = app(InvestorRequestService::class)->create($account, InvestorRequest::TYPE_TOP_UP, ['target' => 'g'.$group->id, 'amount' => 60000, 'payment_method' => 'bkash']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListInvestorRequests::class)
            ->callTableAction('approve', $request, ['target' => 'g'.$group->id, 'amount' => 60000, 'invested_at' => '2026-03-10', 'payment_method' => 'bkash'])
            ->assertHasNoTableActionErrors();

        $this->assertInstanceOf(InvestorGroupMember::class, $request->fresh()->result);
        $this->assertSame(60000.0, $group->totalActiveDeposits());
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $overrides */
    private function group(array $overrides = []): InvestorGroup
    {
        return InvestorGroup::query()->create(array_merge(['company_id' => $this->company->id], $overrides));
    }

    private function runningGroup(): InvestorGroup
    {
        $group = $this->group();
        $this->member($group, 200000);
        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-03-10'), $this->staff);

        return $group->fresh();
    }

    private function member(InvestorGroup $group, float $amount, ?Investor $investor = null): InvestorGroupMember
    {
        return app(InvestorGroupService::class)->addMember(
            $group,
            $investor ?? $this->investor('Member '.random_int(1000, 999999), '017'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT)),
            ['amount' => $amount, 'payment_method' => 'bank', 'invested_at' => '2026-03-01'],
            $this->staff,
        );
    }

    private function investor(string $name, string $phone): Investor
    {
        return Investor::query()->create(['company_id' => $this->company->id, 'name' => $name, 'phone' => $phone]);
    }

    private function looseProject(string $status): InvestmentProject
    {
        return InvestmentProject::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Loose '.random_int(100, 999),
            'duration_type' => '2_month',
            'start_date' => '2026-03-01',
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'status' => $status,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function draft(array $attributes): InvestorDraft
    {
        return InvestorDraft::query()->create(array_merge([
            'company_id' => $this->company->id,
            'batch_id' => 'test-batch',
            'source' => InvestorDraft::SOURCE_TEXT,
            'investment_amount' => 200000,
            'payment_method' => 'bank',
            'invested_at' => '2026-03-09',
        ], $attributes));
    }

    private function activeAccount(Investor $investor): InvestorPortalAccount
    {
        $account = app(InvestorPortalAccountService::class)->ensureAccount($investor);
        $account->forceFill(['status' => InvestorPortalAccount::STATUS_ACTIVE, 'password' => 'a-strong-password-1'])->save();

        return $account;
    }

    /** Signs the person in to the portal, as the portal pages need. */
    private function ownSessionOf(Investor $investor): InvestorPortalAccount
    {
        $account = $this->activeAccount($investor);
        $request = Request::create('/investor');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        Filament::setCurrentPanel(Filament::getPanel('investor'));
        $this->actingAs($account, 'investor');

        return $account;
    }

    /**
     * An old-style project settled with two investors; the first one's pending payout.
     *
     * @return array{0: SettlementPayout, 1: Investor}
     */
    private function settledPayout(float $first, float $second, float $cost, float $revenue): array
    {
        $project = $this->looseProject('closed');
        $a = $this->investor('Payout A '.random_int(100, 999), '017'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT));
        $b = $this->investor('Payout B '.random_int(100, 999), '017'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT));

        foreach ([[$a, $first], [$b, $second]] as [$investor, $amount]) {
            Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => $amount, 'payment_method' => 'bank', 'invested_at' => '2026-03-01', 'received_by' => $this->staff->id]);
        }

        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => $cost]);
        $settlement = app(SettlementService::class)->calculateAndSettle($project, $revenue, $this->staff->id, acknowledgeUnprovenCosts: true);
        app(SettlementService::class)->confirmSettlement($settlement, $this->staff->id);

        return [$settlement->payouts()->where('investor_id', $a->id)->firstOrFail(), $a];
    }
}
