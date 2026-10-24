<?php

namespace Tests\Feature;

use App\Filament\Resources\InvestorGroups\Pages\CreateInvestorGroup;
use App\Filament\Resources\InvestorGroups\Pages\EditInvestorGroup;
use App\Models\Account;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investor;
use App\Models\InvestorGroup;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Receiving / Payout Fund Source pickers list the company's money
 * accounts from Finance > Accounts (the Fund Sources page is retired, so
 * the fund_sources table was empty and the pickers showed nothing). The
 * picked account is stored as its fund source, made on first use.
 */
class InvestorGroupFundSourcePickerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 10:00:00');

        $this->company = $this->makeCompany('Picker Co', 'picker-co');
        app(CompanyContext::class)->set($this->company);
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_pickers_list_only_the_companys_active_money_accounts(): void
    {
        Account::ensureSystemAccountsForCompany($this->company);
        $bkash = Account::query()->create(['name' => 'Bkash', 'type' => 'mobile_banking']);
        $brac = Account::query()->create(['name' => 'Brac Bank', 'type' => 'bank']);
        $cash = Account::query()->create(['name' => 'Cash', 'type' => 'cash']);
        Account::query()->create(['name' => 'Closed Bank', 'type' => 'bank', 'is_active' => false]);

        $other = $this->makeCompany('Other Co', 'other-co');
        Account::query()->create(['company_id' => $other->id, 'name' => 'Other Bank', 'type' => 'bank']);

        $this->assertSame(
            [$bkash->id => 'Bkash', $brac->id => 'Brac Bank', $cash->id => 'Cash'],
            FundSource::moneyAccountOptions(),
            'no system account (shipment / customer due / inventory), no inactive account, no other company',
        );

        app(CompanyContext::class)->set($other);
        $this->assertSame(['Other Bank'], array_values(FundSource::moneyAccountOptions()), 'switching company switches the list');
    }

    public function test_a_company_without_a_money_account_gets_an_empty_list_until_one_is_added(): void
    {
        Account::ensureSystemAccountsForCompany($this->company);
        $this->assertSame([], FundSource::moneyAccountOptions());

        $bank = Account::query()->create(['name' => 'New Bank', 'type' => 'bank']);
        $this->assertSame([$bank->id => 'New Bank'], FundSource::moneyAccountOptions());
    }

    public function test_a_group_saved_from_the_form_books_deposits_and_payouts_against_the_picked_accounts(): void
    {
        $receiving = Account::query()->create(['name' => 'Brac Bank', 'type' => 'bank', 'opening_balance' => 1000000]);
        $payout = Account::query()->create(['name' => 'Bkash', 'type' => 'mobile_banking', 'opening_balance' => 1000000]);

        Livewire::test(CreateInvestorGroup::class)
            ->assertFormFieldExists('receiving_fund_source_id')
            ->fillForm([
                'cycle_months' => 2,
                'investor_share_percent' => 40,
                'channel_partner_share_percent' => 10,
                'company_share_percent' => 50,
                'receiving_fund_source_id' => $receiving->id,
                'payout_fund_source_id' => $payout->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = InvestorGroup::query()->firstOrFail();
        $this->assertSame($receiving->id, $group->receivingFundSource->account_id);
        $this->assertSame($payout->id, $group->payoutFundSource->account_id);
        $this->assertSame('Brac Bank', $group->receivingFundSource->name);

        $member = app(InvestorGroupService::class)->addMember($group, $this->investor(), [
            'amount' => 50000, 'payment_method' => 'bank', 'invested_at' => '2026-03-01',
        ], $this->admin);

        $voucher = Voucher::query()->findOrFail($member->voucher_id);
        $this->assertSame('capital_investment', $voucher->transaction_type);
        $this->assertSame($receiving->id, $voucher->account_id);

        $project = app(InvestorGroupService::class)->startGroup($group->fresh(), Carbon::parse('2026-03-10'), $this->admin);
        $this->assertSame($group->receiving_fund_source_id, $project->receiving_fund_source_id);
        $this->assertSame($group->payout_fund_source_id, $project->payout_fund_source_id, 'the payout account is copied into the project');
    }

    public function test_picking_the_same_account_again_reuses_its_fund_source_and_the_edit_form_shows_it(): void
    {
        $bank = Account::query()->create(['name' => 'NCC Bank', 'type' => 'bank']);
        $existing = FundSource::query()->create(['name' => 'Old name', 'type' => 'bank', 'account_id' => $bank->id]);

        $group = InvestorGroup::query()->create(['company_id' => $this->company->id, 'payout_fund_source_id' => $existing->id]);

        Livewire::test(EditInvestorGroup::class, ['record' => $group->getRouteKey()])
            ->assertFormSet(['payout_fund_source_id' => $bank->id, 'receiving_fund_source_id' => null])
            ->fillForm(['receiving_fund_source_id' => $bank->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $group->refresh();
        $this->assertSame($existing->id, $group->payout_fund_source_id);
        $this->assertSame($existing->id, $group->receiving_fund_source_id);
        $this->assertSame(1, FundSource::query()->count(), 'no duplicate fund source for the same account');
    }

    public function test_another_companys_account_cannot_be_picked(): void
    {
        $other = $this->makeCompany('Other Co', 'other-co');
        $foreign = Account::query()->create(['company_id' => $other->id, 'name' => 'Other Bank', 'type' => 'bank']);
        Account::ensureSystemAccountsForCompany($this->company);
        $system = Account::query()->where('system_key', 'new_shipment')->firstOrFail();

        $this->assertNull(FundSource::forAccount($foreign->id));
        $this->assertNull(FundSource::forAccount($system->id));

        Livewire::test(CreateInvestorGroup::class)
            ->fillForm([
                'cycle_months' => 2,
                'investor_share_percent' => 40,
                'channel_partner_share_percent' => 10,
                'company_share_percent' => 50,
                'receiving_fund_source_id' => $foreign->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['receiving_fund_source_id']);

        $this->assertSame(0, FundSource::query()->withoutGlobalScopes()->count());
    }

    private function makeCompany(string $name, string $slug): Company
    {
        return Company::query()->create([
            'name' => $name, 'slug' => $slug, 'invoice_prefix' => strtoupper(substr($slug, 0, 3)),
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
    }

    private function investor(): Investor
    {
        return Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Rahim', 'phone' => '01710000001']);
    }
}
