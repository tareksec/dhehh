<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\FundSource;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\InvestorGroup;
use App\Models\ProjectCostItem;
use App\Models\SettlementPayout;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorGroupCycleService;
use App\Services\Investment\InvestorGroupService;
use App\Services\Investment\PayoutCompletionService;
use App\Services\Investment\PayoutProofService;
use App\Services\Investment\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The investors' side of the IMS link (owner decisions 2026-10-15): investors,
 * groups, projects with their progress percentage, investments and payouts with
 * the screenshot. Behind its own "Investor sync" key, read only, one company's
 * data per key, and never a customer or a supplier.
 */
class InvestorSyncApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    private User $staff;

    private FundSource $fundSource;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->company = $this->makeCompany('Invest Co', 'INV');
        $this->other = $this->makeCompany('Other Co', 'OTH');
        app(CompanyContext::class)->set($this->company);
        $this->staff = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->staff);

        $account = Account::query()->create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 100000000]);
        $this->fundSource = FundSource::query()->create(['name' => 'Payouts', 'type' => 'owner_investment', 'account_id' => $account->id]);
        [, $this->token] = CompanyApiKey::generate($this->company, 'IMS investors', null, CompanyApiKey::SCOPE_INVESTOR_SYNC);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------ key scopes

    public function test_only_an_investor_sync_key_opens_the_investor_feed(): void
    {
        [, $business] = CompanyApiKey::generate($this->company, 'Business', null, CompanyApiKey::SCOPE_SYNC);
        [, $website] = CompanyApiKey::generate($this->company, 'Website', null, CompanyApiKey::SCOPE_WEBSITE);

        $this->getJson('/api/v1/sync/investor/investors')->assertStatus(401);
        $this->getJson('/api/v1/sync/investor/investors', ['Authorization' => "Bearer {$business}"])->assertStatus(403)->assertJsonPath('error', 'wrong_key_scope');
        $this->getJson('/api/v1/sync/investor/investors', ['Authorization' => "Bearer {$website}"])->assertStatus(403);
        $this->getJson('/api/v1/sync/investor/investors', $this->auth())->assertOk();
    }

    public function test_the_investor_key_does_not_open_the_business_feed_and_the_business_feed_has_no_investors(): void
    {
        $this->getJson('/api/v1/sync/orders', $this->auth())->assertStatus(403)->assertJsonPath('error', 'wrong_key_scope');

        [, $business] = CompanyApiKey::generate($this->company, 'Business', null, CompanyApiKey::SCOPE_SYNC);
        foreach (['investors', 'investments', 'payouts', 'investor'] as $resource) {
            $this->getJson("/api/v1/sync/{$resource}", ['Authorization' => "Bearer {$business}"])->assertNotFound();
        }
    }

    public function test_the_investor_feed_is_read_only(): void
    {
        $this->postJson('/api/v1/sync/investor/investors', [], $this->auth())->assertStatus(405);
        $this->putJson('/api/v1/sync/investor/payouts', [], $this->auth())->assertStatus(405);
        $this->deleteJson('/api/v1/sync/investor/payouts', [], $this->auth())->assertStatus(405);
    }

    public function test_ping_lists_the_investor_resources_only(): void
    {
        $resources = $this->getJson('/api/v1/sync/investor/ping', $this->auth())->assertOk()->json('resources');

        $this->assertEqualsCanonicalizing(['investors', 'groups', 'projects', 'investments', 'payouts'], $resources);
        $this->getJson('/api/v1/sync/investor/customers', $this->auth())->assertNotFound();
        $this->getJson('/api/v1/sync/investor/suppliers', $this->auth())->assertNotFound();
    }

    // ----------------------------------------------------------- the records

    public function test_an_investor_comes_with_name_phone_nid_groups_and_approved_bank_accounts_only(): void
    {
        [$group, $me] = $this->startedGroup([200000]);
        $me->forceFill(['nid_number' => '1990123456789'])->save();
        $this->bankAccount($me, 'Approved Bank', '1234567890', InvestorBankAccount::STATUS_APPROVED);
        $this->bankAccount($me, 'Waiting Bank', '9999999999', InvestorBankAccount::STATUS_PENDING);

        $row = collect($this->getJson('/api/v1/sync/investor/investors', $this->auth())->assertOk()->json('data'))->firstWhere('id', $me->id);

        $this->assertSame('Member 1', $row['name']);
        $this->assertSame($me->phone, $row['phone']);
        $this->assertSame('1990123456789', $row['nid_number']);
        $this->assertSame($group->group_code, $row['groups'][0]['group_code']);
        $this->assertSame('200000.00', $row['groups'][0]['amount']);
        $this->assertCount(1, $row['bank_accounts'], 'a pending account is not shared');
        $this->assertSame('Approved Bank', $row['bank_accounts'][0]['bank_name']);
        $this->assertSame('1234567890', $row['bank_accounts'][0]['account_number']);
    }

    public function test_a_project_shares_only_how_far_it_has_run(): void
    {
        [$group] = $this->startedGroup([200000]);
        Carbon::setTestNow('2026-01-30 10:00:00');

        $project = collect($this->getJson('/api/v1/sync/investor/projects', $this->auth())->assertOk()->json('data'))->first();

        // Project 1 runs 1 Jan to 1 Mar; 30 Jan is 29 of 59 days in.
        $this->assertSame(49, $project['progress_percent']);
        $this->assertSame($group->group_code, $project['group_code']);
        $this->assertSame(1, $project['sequence_in_group']);
        foreach (['target_amount', 'company_contribution_amount', 'investor_share_percent', 'description'] as $private) {
            $this->assertArrayNotHasKey($private, $project);
        }
    }

    public function test_a_payout_comes_with_its_amounts_status_date_and_screenshot_link(): void
    {
        [$group, $me, $payout] = $this->settledGroupProject();

        $row = $this->payoutRow($payout);
        $this->assertSame('pending', $row['payment_status']);
        $this->assertSame('32000.00', $row['profit_share_amount']);
        $this->assertNull($row['payment_proof']);
        $this->assertSame($me->id, $row['investor_id']);
        $this->assertSame($group->projects()->where('sequence_in_group', 1)->value('id'), $row['project_id']);

        app(PayoutCompletionService::class)->markPaid($payout, $this->staff, ['payment_reference' => 'TRX-9']);
        app(PayoutProofService::class)->attach($payout->fresh(), $this->storedFile('slip.jpg'), $this->staff);

        $row = $this->payoutRow($payout);
        $this->assertSame('paid', $row['payment_status']);
        $this->assertSame('TRX-9', $row['payment_reference']);
        $this->assertNotNull($row['paid_at']);
        $this->assertSame("/api/v1/sync/investor/payouts/{$payout->id}/proof", $row['payment_proof']['download_path']);

        $this->get($row['payment_proof']['download_path'], $this->auth())->assertOk()->assertDownload('slip.jpg');
    }

    public function test_a_payout_without_a_screenshot_has_no_download(): void
    {
        [, , $payout] = $this->settledGroupProject();

        $this->get("/api/v1/sync/investor/payouts/{$payout->id}/proof", $this->auth())->assertNotFound();
    }

    public function test_a_key_only_ever_sees_its_own_companys_investors_and_payouts(): void
    {
        [, , $payout] = $this->settledGroupProject();
        app(CompanyContext::class)->set($this->other);
        Investor::query()->create(['company_id' => $this->other->id, 'name' => 'Stranger', 'phone' => '01899999999']);
        app(CompanyContext::class)->set($this->company);
        [, $otherToken] = CompanyApiKey::generate($this->other, 'Other IMS', null, CompanyApiKey::SCOPE_INVESTOR_SYNC);
        $otherAuth = ['Authorization' => "Bearer {$otherToken}"];

        $names = collect($this->getJson('/api/v1/sync/investor/investors', $otherAuth)->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Stranger'], $names);
        $this->assertSame([], $this->getJson('/api/v1/sync/investor/payouts', $otherAuth)->assertOk()->json('data'));
        $this->getJson("/api/v1/sync/investor/payouts/{$payout->id}/proof", $otherAuth)->assertNotFound();
    }

    public function test_no_customer_or_supplier_data_is_anywhere_in_the_investor_feed(): void
    {
        [, , $payout] = $this->settledGroupProject();
        app(PayoutCompletionService::class)->markPaid($payout, $this->staff);

        foreach (['investors', 'groups', 'projects', 'investments', 'payouts'] as $resource) {
            $body = $this->getJson("/api/v1/sync/investor/{$resource}", $this->auth())->assertOk()->getContent();
            $this->assertStringNotContainsString('customer', strtolower($body), "{$resource} mentions a customer");
            $this->assertStringNotContainsString('supplier', strtolower($body), "{$resource} mentions a supplier");
        }
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /** @return array<string, mixed> */
    private function payoutRow(SettlementPayout $payout): array
    {
        return collect($this->getJson('/api/v1/sync/investor/payouts', $this->auth())->assertOk()->json('data'))->firstWhere('id', $payout->id);
    }

    private function makeCompany(string $name, string $prefix): Company
    {
        return Company::query()->create([
            'name' => $name, 'slug' => strtolower($prefix), 'invoice_prefix' => $prefix,
            'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true,
        ]);
    }

    /**
     * @param  list<float|int>  $amounts
     * @return array{0: InvestorGroup, 1: Investor, 2?: Investor}
     */
    private function startedGroup(array $amounts): array
    {
        $group = InvestorGroup::query()->create(['company_id' => $this->company->id, 'payout_fund_source_id' => $this->fundSource->id]);
        $investors = [];

        foreach ($amounts as $index => $amount) {
            $investors[] = $investor = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Member '.($index + 1), 'phone' => '01861'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT)]);
            app(InvestorGroupService::class)->addMember($group, $investor, ['amount' => $amount, 'payment_method' => 'bank', 'invested_at' => '2026-01-01'], $this->staff);
        }

        app(InvestorGroupService::class)->startGroup($group, Carbon::parse('2026-01-01'), $this->staff);
        $group->fresh()->update(['auto_cycle' => true]);

        return [$group->fresh(), ...$investors];
    }

    /**
     * Two members (2,00,000 and 3,00,000). Project 1 ended on 1 March and was settled at a 2,00,000 profit and
     * confirmed, so the first member's share is 32,000 and her payout is pending.
     *
     * @return array{0: InvestorGroup, 1: Investor, 2: SettlementPayout}
     */
    private function settledGroupProject(): array
    {
        [$group, $me] = $this->startedGroup([200000, 300000]);
        Carbon::setTestNow('2026-03-01 09:00:00');
        app(InvestorGroupCycleService::class)->advance($group->fresh(), Carbon::parse('2026-03-01'));
        $first = $group->projects()->where('sequence_in_group', 1)->firstOrFail();
        ProjectCostItem::query()->create(['project_id' => $first->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 400000]);
        $settlement = app(SettlementService::class)->calculateAndSettle($first->fresh(), 600000, $this->staff->id, acknowledgeUnprovenCosts: true);
        app(SettlementService::class)->confirmSettlement($settlement, $this->staff->id);
        Carbon::setTestNow('2026-03-05 10:00:00');

        return [$group->fresh(), $me, $settlement->payouts()->where('investor_id', $me->id)->firstOrFail()];
    }

    private function bankAccount(Investor $investor, string $bank, string $number, string $status): InvestorBankAccount
    {
        $account = InvestorBankAccount::query()->create([
            'company_id' => $this->company->id, 'holder_type' => $investor->getMorphClass(), 'holder_id' => $investor->id,
            'type' => 'bank', 'bank_name' => $bank, 'branch_name' => 'Main', 'routing_number' => '123456789',
            'account_name' => $investor->name, 'account_number' => $number,
        ]);
        $account->forceFill(['status' => $status])->save();

        return $account;
    }

    private function storedFile(string $name): string
    {
        $path = $this->company->storageRoot().'/private/payout-proofs/'.$name;
        Storage::disk('local')->put($path, 'screenshot');

        return $path;
    }
}
