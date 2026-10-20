<?php

namespace Tests\Feature\Ims;

use App\Ims\Capital\CapitalCalculator;
use App\Ims\Capital\CapitalReturnService;
use App\Models\Account;
use App\Models\CapitalReturn;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\ProjectCostItem;
use App\Services\Investment\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Capital (IMS-V2/TRD.md §4.2–4.3): the owner's loss rule, the one capital
 * formula, and paying capital back — never more than is left, never below
 * zero, a voided return counts no more.
 */
class CapitalTest extends TestCase
{
    use ImsTestHelpers;
    use RefreshDatabase;

    private FundSource $fund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imsCompany();
        Auth::login($this->staff());
        $account = Account::query()->create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 100000000]);
        $this->fund = FundSource::query()->create(['name' => 'Payouts', 'type' => 'owner_investment', 'account_id' => $account->id]);
    }

    /** @return array{0: InvestmentProject, 1: Investment, 2: Investor} */
    private function settledLoss(float $capital, float $cost, float $revenue, float $percent = 35, string $outcome = 'loss_investor_borne'): array
    {
        $project = $this->closedProject(['investor_share_percent' => $percent, 'channel_partner_share_percent' => 0, 'company_share_percent' => 100 - $percent]);
        $investor = $this->verified($this->newInvestor());
        $investment = Investment::query()->create(['project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => $capital, 'payment_method' => 'bank', 'invested_at' => now()->subDays(60)->toDateString()]);
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => $cost]);

        $service = app(SettlementService::class);
        $settlement = $service->calculateAndSettle($project, $revenue, $this->staff()->id, acknowledgeUnprovenCosts: true, outcome: $outcome, lossReason: 'Market fell sharply');
        $service->confirmSettlement($settlement, $this->staff()->id);

        return [$project, $investment->refresh(), $investor];
    }

    public function test_the_owners_example_35_percent_of_a_100000_loss_comes_from_capital(): void
    {
        [, $investment] = $this->settledLoss(1000000, 1100000, 1000000, 35);

        $figures = app(CapitalCalculator::class)->forInvestment($investment);

        $this->assertSame(35000.0, $figures['loss']);
        $this->assertSame(965000.0, $figures['returnable']);
    }

    public function test_an_investor_never_loses_more_than_was_put_in(): void
    {
        [, $investment] = $this->settledLoss(100000, 500000, 0, 100);

        $figures = app(CapitalCalculator::class)->forInvestment($investment);

        $this->assertSame(100000.0, $figures['loss']);
        $this->assertSame(0.0, $figures['returnable']);
        $this->assertSame(0.0, $figures['outstanding']);
    }

    public function test_a_loss_the_company_bears_leaves_the_capital_whole(): void
    {
        [, $investment] = $this->settledLoss(100000, 150000, 100000, 35, 'loss_manager_borne');

        $this->assertSame(100000.0, app(CapitalCalculator::class)->forInvestment($investment)['returnable']);
    }

    public function test_money_still_waiting_has_nothing_at_work(): void
    {
        $project = $this->closedProject(['status' => 'open']);
        $waiting = Investment::query()->create(['project_id' => $project->id, 'investor_id' => $this->newInvestor()->id, 'amount' => 5000, 'payment_method' => 'bank', 'invested_at' => now()->toDateString()]);

        $this->assertTrue($waiting->isPending());
        $this->assertSame(0.0, app(CapitalCalculator::class)->forInvestment($waiting)['outstanding']);
    }

    public function test_capital_is_paid_back_in_parts_but_never_more_than_is_left(): void
    {
        $project = $this->closedProject(['status' => 'running']);
        $investor = $this->verified($this->newInvestor());
        $investment = Investment::query()->create(['project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => 100000, 'payment_method' => 'bank', 'invested_at' => now()->subMonths(6)->toDateString(), 'tenor_months' => 6]);
        $service = app(CapitalReturnService::class);
        $data = ['returned_on' => now()->toDateString(), 'fund_source_id' => $this->fund->id, 'method' => 'bank'];

        $this->assertSame(now()->toDateString(), $investment->maturity_date->toDateString(), 'the tenor sets the maturity date');

        $service->record($investment, [...$data, 'amount' => 40000], $this->staff('accountant'));
        $this->assertSame(60000.0, app(CapitalCalculator::class)->forInvestment($investment->refresh())['outstanding']);

        try {
            $service->record($investment, [...$data, 'amount' => 70000], $this->staff('accountant'));
            $this->fail('More than what is left was paid back.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('60,000', collect($exception->errors())->flatten()->first());
        }

        $service->record($investment, [...$data, 'amount' => 60000], $this->staff('accountant'));
        $this->assertTrue($investment->refresh()->isWithdrawn(), 'all paid back: the investment is done');
        $this->assertSame(2, CapitalReturn::query()->count());
    }

    public function test_a_manager_cannot_pay_back_capital_and_a_voided_return_counts_no_more(): void
    {
        $project = $this->closedProject(['status' => 'running']);
        $investment = Investment::query()->create(['project_id' => $project->id, 'investor_id' => $this->verified($this->newInvestor())->id, 'amount' => 50000, 'payment_method' => 'bank', 'invested_at' => now()->toDateString()]);
        $data = ['amount' => 50000, 'returned_on' => now()->toDateString(), 'fund_source_id' => $this->fund->id, 'method' => 'bank'];

        try {
            app(CapitalReturnService::class)->record($investment, $data, $this->staff('manager'));
            $this->fail('A manager paid back capital.');
        } catch (ValidationException) {
        }

        $return = app(CapitalReturnService::class)->record($investment, $data, $this->staff());
        $this->assertTrue($investment->refresh()->isWithdrawn());

        app(CapitalReturnService::class)->void($return, 'Entered for the wrong person', $this->staff());

        $this->assertSame(CapitalReturn::STATUS_VOIDED, $return->refresh()->status);
        $this->assertFalse($investment->refresh()->isWithdrawn(), 'the voucher was cancelled, so the investment is active again');
        $this->assertSame(50000.0, app(CapitalCalculator::class)->forInvestment($investment)['outstanding']);
        $this->expectException(ValidationException::class);
        $return->delete();
    }

    public function test_only_a_super_admin_voids_a_settlement_with_a_real_reason(): void
    {
        $project = $this->closedProject();
        Investment::query()->create(['project_id' => $project->id, 'investor_id' => $this->verified($this->newInvestor())->id, 'amount' => 100000, 'payment_method' => 'bank', 'invested_at' => now()->subDays(60)->toDateString()]);
        $settlement = app(SettlementService::class)->calculateAndSettle($project, 0, $this->staff()->id, manualNetProfit: 10000);

        foreach ([[$this->staff('manager'), 'A long enough reason'], [$this->staff(), 'short']] as [$user, $reason]) {
            try {
                app(SettlementService::class)->voidSettlement($settlement, $reason, $user->id);
                $this->fail('The void was allowed.');
            } catch (RuntimeException) {
            }
        }

        app(SettlementService::class)->voidSettlement($settlement, 'Wrong profit was typed in', $this->staff()->id);
        $this->assertSame('closed', $project->refresh()->status);
    }
}
