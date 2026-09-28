<?php

namespace Tests\Feature;

use App\Filament\Resources\InvestmentRecords\Pages\ViewInvestmentRecord;
use App\Filament\Resources\Investors\Pages\ListInvestors;
use App\Models\Account;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CompanyContext;
use App\Services\Investment\InvestmentWithdrawalService;
use App\Services\Investment\SettlementService;
use App\Services\VoucherTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner's fixed rule (2026-09-28): a principal withdrawal always creates a
 * voucher, and the investment is marked withdrawn only once the voucher
 * exists. The investment stays in the records.
 */
class InvestmentWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private FundSource $fundSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Withdraw Co',
            'slug' => 'withdraw-co',
            'invoice_prefix' => 'WDC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->admin);

        $account = Account::query()->create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 1000000]);
        $this->fundSource = FundSource::query()->create(['name' => 'Investor payouts', 'type' => 'owner_investment', 'account_id' => $account->id]);
    }

    public function test_a_withdrawal_creates_the_voucher_and_then_marks_the_investment_withdrawn(): void
    {
        $investment = $this->investment('01711111111', '1479939603');

        $voucher = app(InvestmentWithdrawalService::class)->withdraw($investment, $this->data(), $this->admin);

        $this->assertSame(Voucher::TYPE_DEBIT, $voucher->type);
        $this->assertSame('investor_payout', $voucher->transaction_type);
        $this->assertSame(200000.0, (float) $voucher->amount);
        $this->assertSame($this->fundSource->account_id, $voucher->account_id);

        $investment->refresh();
        $this->assertSame(Investment::STATUS_WITHDRAWN, $investment->status);
        $this->assertSame($voucher->id, $investment->withdrawal_voucher_id);
        $this->assertSame('2026-09-20', $investment->withdrawn_at->toDateString());
        $this->assertSame(1, Investment::query()->count());
    }

    public function test_no_voucher_means_no_status_change(): void
    {
        $investment = $this->investment('01711111111', '1479939603');
        $noAccount = FundSource::query()->create(['name' => 'Cash box', 'type' => 'owner_investment']);

        try {
            app(InvestmentWithdrawalService::class)->withdraw($investment, [...$this->data(), 'fund_source_id' => $noAccount->id], $this->admin);
            $this->fail('A fund source without an account must be refused.');
        } catch (ValidationException) {
        }

        $this->assertSame(Investment::STATUS_ACTIVE, $investment->fresh()->status);
        $this->assertSame(0, Voucher::query()->count());

        $this->expectException(ValidationException::class);
        $investment->update(['status' => Investment::STATUS_WITHDRAWN]);
    }

    public function test_a_withdrawn_investment_cannot_be_deleted_changed_or_withdrawn_twice(): void
    {
        $investment = $this->investment('01711111111', '1479939603');
        app(InvestmentWithdrawalService::class)->withdraw($investment, $this->data(), $this->admin);

        foreach ([
            fn () => $investment->fresh()->delete(),
            fn () => $investment->fresh()->update(['amount' => 1]),
            fn () => app(InvestmentWithdrawalService::class)->withdraw($investment->fresh(), $this->data(), $this->admin),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A withdrawn investment must stay as it is.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(200000.0, (float) $investment->fresh()->amount);
        $this->assertSame(1, Voucher::query()->count());
    }

    public function test_the_withdraw_button_on_the_investment_page_works_for_managers_only(): void
    {
        $investment = $this->investment('01711111111', '1479939603');

        Livewire::test(ViewInvestmentRecord::class, ['record' => $investment->getRouteKey()])
            ->callAction('withdrawPrincipal', $this->data())
            ->assertHasNoActionErrors();

        $this->assertTrue($investment->fresh()->isWithdrawn());

        $other = $this->investment('01722222222', '1901688455');
        $accountant = User::factory()->create(['role' => 'accountant', 'is_active' => true]);
        $accountant->companies()->attach($this->company, ['role' => 'accountant', 'is_default' => true]);
        Auth::login($accountant);

        Livewire::test(ViewInvestmentRecord::class, ['record' => $other->getRouteKey()])
            ->assertActionHidden('withdrawPrincipal');
    }

    public function test_an_investor_is_withdrawn_only_when_every_investment_is(): void
    {
        $karim = $this->investment('01711111111', '1479939603')->investor;
        $second = Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $this->project('INV-2')->id, 'investor_id' => $karim->id, 'amount' => 100000, 'payment_method' => 'bank', 'invested_at' => now()->toDateString()]);
        $rahim = $this->investment('01722222222', '1901688455')->investor;

        app(InvestmentWithdrawalService::class)->withdraw($karim->investments()->first(), $this->data(), $this->admin);
        $this->assertSame(Investor::STATUS_ACTIVE, $karim->fresh()->investmentStatus());

        app(InvestmentWithdrawalService::class)->withdraw($second, $this->data(), $this->admin);
        $this->assertSame(Investor::STATUS_WITHDRAWN, $karim->fresh()->investmentStatus());

        Livewire::test(ListInvestors::class)
            ->filterTable('investment_status', Investor::STATUS_WITHDRAWN)
            ->assertCanSeeTableRecords([$karim])
            ->assertCanNotSeeTableRecords([$rahim]);

        Livewire::test(ListInvestors::class)
            ->filterTable('investment_status', Investor::STATUS_ACTIVE)
            ->assertCanSeeTableRecords([$rahim])
            ->assertCanNotSeeTableRecords([$karim]);
    }

    public function test_a_settlement_made_after_a_withdrawal_leaves_the_withdrawn_investment_out(): void
    {
        $withdrawn = $this->investment('01711111111', '1479939603');
        $staying = $this->investment('01722222222', '1901688455');
        app(InvestmentWithdrawalService::class)->withdraw($withdrawn, $this->data(), $this->admin);

        $withdrawn->project->update(['status' => 'closed']);

        $settlement = app(SettlementService::class)->calculateAndSettle($withdrawn->project->fresh(), 300000, $this->admin->id, acknowledgeUnprovenCosts: true);

        $this->assertSame([$staying->investor_id], $settlement->payouts()->pluck('investor_id')->all());
        $this->assertSame(200000.0, (float) $settlement->payouts()->sum('investment_amount'));
    }

    public function test_trashing_the_withdrawal_voucher_makes_the_investment_active_and_restoring_it_withdrawn_again(): void
    {
        $investment = $this->investment('01711111111', '1479939603');
        $voucher = app(InvestmentWithdrawalService::class)->withdraw($investment, $this->data(), $this->admin);

        app(VoucherTrashService::class)->trash($voucher, $this->admin);
        $this->assertSame(Investment::STATUS_ACTIVE, $investment->fresh()->status);
        $this->assertSame('2026-09-20', $investment->fresh()->withdrawn_at->toDateString());

        app(VoucherTrashService::class)->restore($voucher, $this->admin);
        $this->assertSame(Investment::STATUS_WITHDRAWN, $investment->fresh()->status);

        app(VoucherTrashService::class)->trash($voucher, $this->admin);
        app(VoucherTrashService::class)->deletePermanently($voucher, $this->admin);
        $this->assertSame(Investment::STATUS_ACTIVE, $investment->fresh()->status);
        $this->assertSame(1, Investment::query()->count());
    }

    private function data(): array
    {
        return ['withdrawn_at' => '2026-09-20', 'fund_source_id' => $this->fundSource->id, 'payment_method' => 'bank', 'payment_reference' => 'TRX-1', 'note' => 'Took principal after cycle 1'];
    }

    private function investment(string $phone, string $nid): Investment
    {
        $investor = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Investor '.$phone, 'phone' => $phone, 'nid_number' => $nid]);
        $project = InvestmentProject::query()->firstWhere('project_code', 'INV-1') ?? $this->project('INV-1');

        return Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => 200000, 'payment_method' => 'bank', 'invested_at' => now()->toDateString()]);
    }

    private function project(string $code): InvestmentProject
    {
        return InvestmentProject::query()->create([
            'company_id' => $this->company->id,
            'project_code' => $code,
            'name' => 'Sharing machine '.$code,
            'duration_type' => 'custom_days',
            'trade_cycle_days' => 60,
            'start_date' => now()->toDateString(),
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'payout_fund_source_id' => $this->fundSource->id,
            'status' => 'running',
        ]);
    }
}
