<?php

namespace Tests\Feature\Ims;

use App\Ims\Notifications\ImsNotifier;
use App\Ims\Profit\ProposalService;
use App\Ims\Settings\ImsSettings;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\InvestorPortalAccount;
use App\Models\ProfitProposal;
use App\Models\ProjectCostItem;
use App\Models\ProjectSettlement;
use App\Models\StatementDispatch;
use App\Services\Investment\PayoutCompletionService;
use App\Services\Investment\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Profit proposals (PRD FR-45), the frozen cycle (FR-47) and the daily jobs
 * (TRD §6): each does its work once and nothing while switched off.
 */
class ProposalsAndJobsTest extends TestCase
{
    use ImsTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imsCompany();
        Auth::login($this->staff());
    }

    private function closedWithInvestor(): InvestmentProject
    {
        $project = $this->closedProject();
        Investment::query()->create(['project_id' => $project->id, 'investor_id' => $this->verified($this->newInvestor())->id, 'amount' => 100000, 'payment_method' => 'bank', 'invested_at' => now()->subDays(60)->toDateString()]);

        return $project;
    }

    public function test_a_proposal_is_made_once_and_shares_out_nothing_by_itself(): void
    {
        $project = $this->closedWithInvestor();

        $this->artisan('ims:profit-proposals')->assertSuccessful();
        $this->artisan('ims:profit-proposals')->assertSuccessful();

        $this->assertSame(1, ProfitProposal::query()->count());
        $this->assertSame(0, ProjectSettlement::query()->count(), 'nothing is settled without a decision');
        $this->assertSame('closed', $project->refresh()->status);
    }

    public function test_entering_the_profit_by_hand_settles_and_closes_the_proposal(): void
    {
        $project = $this->closedWithInvestor();
        app(ProposalService::class)->generate($this->company);
        $proposal = ProfitProposal::query()->sole();

        try {
            app(ProposalService::class)->enterByHand($proposal, $this->staff('accountant'), 5000);
            $this->fail('An accountant decided a proposal.');
        } catch (ValidationException) {
        }

        $settlement = app(ProposalService::class)->enterByHand($proposal, $this->staff('manager'), 5000);

        $this->assertSame(ProfitProposal::STATUS_APPROVED, $proposal->refresh()->status);
        $this->assertSame($settlement->id, $proposal->settlement_id);
        $this->assertEquals(5000, $settlement->net_profit);
    }

    public function test_a_proposal_for_a_project_settled_another_way_is_no_longer_needed(): void
    {
        $project = $this->closedWithInvestor();
        app(ProposalService::class)->generate($this->company);
        app(SettlementService::class)->calculateAndSettle($project, 0, $this->staff()->id, manualNetProfit: 1000);

        app(ProposalService::class)->markStale();

        $this->assertSame(ProfitProposal::STATUS_STALE, ProfitProposal::query()->sole()->status);
    }

    public function test_a_fully_paid_cycle_is_frozen(): void
    {
        $project = $this->closedWithInvestor();
        $cost = ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'local_expense', 'label' => 'Rent', 'amount' => 100]);
        $service = app(SettlementService::class);
        $settlement = $service->calculateAndSettle($project, 0, $this->staff()->id, manualNetProfit: 2000);
        $service->confirmSettlement($settlement, $this->staff()->id);

        foreach ($settlement->payouts as $payout) {
            app(PayoutCompletionService::class)->markPaid($payout, $this->staff());
        }

        $this->assertNotNull($settlement->refresh()->locked_at);
        $this->assertTrue($project->refresh()->isFrozen());

        $this->expectException(ValidationException::class);
        $cost->update(['amount' => 999]);
    }

    public function test_the_maturity_reminder_goes_once_and_not_while_switched_off(): void
    {
        $project = $this->closedProject(['status' => 'running']);
        $investor = $this->verified($this->newInvestor());
        $this->portalAccount($investor);
        $investment = Investment::query()->create(['project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => 20000, 'payment_method' => 'bank', 'invested_at' => now()->subMonths(6)->addDays(5)->toDateString(), 'tenor_months' => 6]);

        ImsSettings::save($this->company, ['jobs' => ['maturity_reminders' => false]]);
        $this->artisan('ims:maturity-reminders')->assertSuccessful();
        $this->assertNull($investment->refresh()->maturity_reminder_sent_at);

        ImsSettings::save($this->company->refresh(), ['jobs' => ['maturity_reminders' => true]]);
        $this->artisan('ims:maturity-reminders')->assertSuccessful();
        $sentAt = $investment->refresh()->maturity_reminder_sent_at;
        $this->assertNotNull($sentAt);

        $this->artisan('ims:maturity-reminders')->assertSuccessful();
        $this->assertEquals($sentAt, $investment->refresh()->maturity_reminder_sent_at);
        $this->assertSame(1, $this->portalNotifications($investment->investor_id, 'maturity_near'));
    }

    public function test_the_monthly_statement_goes_once_per_month(): void
    {
        $investor = $this->verified($this->newInvestor());
        $this->portalAccount($investor);

        $this->artisan('ims:monthly-statements', ['--period' => '2026-09'])->assertSuccessful();
        $this->artisan('ims:monthly-statements', ['--period' => '2026-09'])->assertSuccessful();

        $this->assertSame(1, StatementDispatch::query()->count());
        $this->assertSame(1, $this->portalNotifications($investor->id, 'monthly_statement'));
    }

    public function test_news_always_reaches_the_portal_and_sms_only_when_written_and_switched_on(): void
    {
        $investor = $this->verified($this->newInvestor());
        $account = $this->portalAccount($investor);

        app(ImsNotifier::class)->send('payout_paid', $investor, ['amount' => 1500]);

        $this->assertSame(1, $account->notifications()->count());
        $this->assertSame('payout_paid', $account->notifications()->first()->data['event']);
    }

    private function portalNotifications(int $investorId, string $event): int
    {
        $account = InvestorPortalAccount::query()->where('investor_id', $investorId)->first();

        return $account ? $account->notifications()->get()->filter(fn ($row): bool => ($row->data['event'] ?? null) === $event)->count() : 0;
    }
}
