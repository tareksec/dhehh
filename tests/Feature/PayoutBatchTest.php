<?php

namespace Tests\Feature;

use App\Filament\Resources\PayoutBatches\Pages\ListPayoutBatches;
use App\Filament\Resources\PayoutBatches\Pages\ViewPayoutBatch;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchItem;
use App\Models\ProjectCostItem;
use App\Models\ProjectSettlement;
use App\Models\SettlementPayout;
use App\Models\User;
use App\Models\Voucher;
use App\Services\CompanyContext;
use App\Services\Investment\CycleElectionService;
use App\Services\Investment\PayoutBatchService;
use App\Services\Investment\PayoutCompletionService;
use App\Services\Investment\SettlementService;
use App\Services\InvestorBankAccountService;
use App\Services\VoucherTrashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 5 of 15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md: payout batches with
 * maker-checker approval, a stored payment list, per-item failures and one
 * payout voucher per batch.
 */
class PayoutBatchTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $maker;

    private User $checker;

    private FundSource $fundSource;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->company = Company::query()->create([
            'name' => 'Batch Co',
            'slug' => 'batch-co',
            'invoice_prefix' => 'BTC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->maker = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->checker = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->maker);

        $account = Account::query()->create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 1000000]);
        $this->fundSource = FundSource::query()->create(['name' => 'Investor payouts', 'type' => 'owner_investment', 'account_id' => $account->id]);
    }

    public function test_only_payouts_with_an_approved_account_are_offered(): void
    {
        [, $alpha, $beta, $settlement] = $this->settledProject();
        $this->approvedAccount($alpha);
        app(InvestorBankAccountService::class)->add($beta, $this->bank('2222'));

        $payables = app(PayoutBatchService::class)->payables();

        $alphaPayout = $settlement->payouts()->where('investor_id', $alpha->id)->first();
        $this->assertSame(['s:'.$alphaPayout->id], $payables['eligible']->keys()->all());
        $this->assertSame(__('No approved payment account.'), $payables['left_out']->first()['reason']);
    }

    public function test_the_full_flow_pays_every_payout_and_books_one_voucher(): void
    {
        [, $alpha, $beta, $settlement] = $this->settledProject();
        $this->approvedAccount($alpha);
        $this->approvedAccount($beta, '5555');
        $service = app(PayoutBatchService::class);

        $batch = $service->create($this->maker, $service->payables()['eligible']->keys()->all(), $this->fundSource->id, now()->toDateString());
        $this->assertSame(1, $batch->batch_number);
        $this->assertSame(2, $batch->item_count);
        $this->assertSame(round((float) $settlement->payouts()->sum('total_payout'), 2), (float) $batch->total_amount);

        $service->approve($batch, $this->checker);
        $file = $service->export($batch->fresh(), $this->maker);
        $this->assertStringContainsString('1234567890123', $file['contents']);
        $this->assertStringContainsString('Investor Alpha', $file['contents']);
        $batch->refresh();
        $this->assertSame(PayoutBatch::STATUS_EXPORTED, $batch->status);
        $this->assertSame(hash('sha256', $file['contents']), $batch->export_sha256);

        $service->complete($batch, $this->maker, 'NCC-REF-1', '2026-10-08');

        $batch->refresh();
        $this->assertSame(PayoutBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(1, Voucher::query()->count());
        $voucher = Voucher::query()->first();
        $this->assertSame('investor_payout', $voucher->transaction_type);
        $this->assertSame((float) $batch->total_amount, (float) $voucher->amount);
        $this->assertSame($voucher->id, $batch->voucher_id);

        foreach ($settlement->payouts()->get() as $payout) {
            $this->assertSame('paid', $payout->payment_status);
            $this->assertSame('NCC-REF-1', $payout->payment_reference);
            $this->assertSame('bank', $payout->payment_method);
            $this->assertSame($voucher->id, $payout->voucher_id);
        }

        $this->assertSame('paid_out', $settlement->fresh()->status);
    }

    public function test_the_maker_cannot_approve_their_own_batch_even_as_super_admin(): void
    {
        $batch = $this->batchForAlpha();

        $this->expectException(ValidationException::class);
        app(PayoutBatchService::class)->approve($batch, $this->maker);
    }

    public function test_staff_without_the_batch_permissions_cannot_make_or_approve_one(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        [, $alpha] = $this->settledProject();
        $this->approvedAccount($alpha);
        $service = app(PayoutBatchService::class);

        try {
            $service->create($manager, $service->payables()['eligible']->keys()->all());
            $this->fail('A manager must not make a payout batch.');
        } catch (ValidationException) {
        }

        $batch = $service->create($this->maker, $service->payables()['eligible']->keys()->all());

        $this->expectException(ValidationException::class);
        $service->approve($batch, $manager);
    }

    public function test_a_payout_in_a_live_batch_is_not_offered_again_and_cannot_be_paid_by_hand(): void
    {
        $batch = $this->batchForAlpha();
        $payout = $batch->items()->first()->payable;

        $this->assertFalse(app(PayoutBatchService::class)->payables()['eligible']->has('s:'.$payout->id));

        try {
            app(PayoutCompletionService::class)->markPaid($payout, $this->maker);
            $this->fail('A payout held by a batch must not be paid by hand.');
        } catch (ValidationException) {
        }

        $this->expectException(RuntimeException::class);
        app(CycleElectionService::class)->recordElection($payout, 'reinvest', null, InvestmentProject::query()->first(), $this->maker->id);
    }

    public function test_a_failed_item_frees_its_payout_and_is_left_out_of_the_voucher(): void
    {
        [, $alpha, $beta, $settlement] = $this->settledProject();
        $this->approvedAccount($alpha);
        $this->approvedAccount($beta, '5555');
        $service = app(PayoutBatchService::class);
        $batch = $service->create($this->maker, $service->payables()['eligible']->keys()->all(), $this->fundSource->id);
        $service->approve($batch, $this->checker);

        $betaItem = $batch->items()->where('payee_id', $beta->id)->first();
        $service->markItemFailed($betaItem, $this->maker, 'Account closed');
        $this->assertTrue($service->payables()['eligible']->has('s:'.$betaItem->payable_id));

        $service->complete($batch->fresh(), $this->maker, 'REF-2');

        $alphaPayout = $settlement->payouts()->where('investor_id', $alpha->id)->first();
        $this->assertSame('paid', $alphaPayout->payment_status);
        $this->assertSame('pending', $betaItem->payable->fresh()->payment_status);
        $this->assertSame((float) $alphaPayout->total_payout, (float) Voucher::query()->first()->amount);
        $this->assertSame('confirmed', $settlement->fresh()->status);
    }

    public function test_trashing_the_batch_voucher_makes_the_payouts_pending_again(): void
    {
        $batch = $this->batchForAlpha();
        $service = app(PayoutBatchService::class);
        $service->approve($batch, $this->checker);
        $service->complete($batch->fresh(), $this->maker, 'REF-3');

        $voucher = Voucher::query()->firstOrFail();
        app(VoucherTrashService::class)->trash($voucher, $this->maker);
        $this->assertSame('pending', SettlementPayout::query()->first()->payment_status);

        app(VoucherTrashService::class)->restore($voucher->fresh(), $this->maker);
        $this->assertSame('paid', SettlementPayout::query()->first()->payment_status);
    }

    public function test_a_cancelled_batch_frees_its_payouts_and_a_draft_item_can_be_taken_out(): void
    {
        $batch = $this->batchForAlpha();
        $service = app(PayoutBatchService::class);
        $item = $batch->items()->first();

        $service->removeItem($item, $this->maker);
        $this->assertSame(0, $batch->fresh()->item_count);

        $batch = $this->batchFor($item->payee);
        $service->cancel($batch, $this->checker, 'Wrong date');
        $this->assertSame(PayoutBatch::STATUS_CANCELLED, $batch->fresh()->status);
        $this->assertTrue($service->payables()['eligible']->has('s:'.$batch->items()->first()->payable_id));
    }

    public function test_the_payment_list_keeps_its_hash_and_each_download_is_audited(): void
    {
        $batch = $this->batchForAlpha();
        $service = app(PayoutBatchService::class);
        $service->approve($batch, $this->checker);

        $first = $service->export($batch->fresh(), $this->maker);
        $second = $service->export($batch->fresh(), $this->maker);
        $this->assertSame($first['contents'], $second['contents']);
        $this->assertSame(2, AuditLog::query()->where('action', 'payout_batch.downloaded')->count());

        Storage::disk('local')->put($batch->fresh()->export_path, 'tampered');
        $this->expectException(ValidationException::class);
        $service->export($batch->fresh(), $this->maker);
    }

    public function test_a_name_that_looks_like_a_formula_is_neutralised_in_the_list(): void
    {
        [, $alpha] = $this->settledProject();
        $alpha->update(['name' => '=HYPERLINK("x")']);
        $this->approvedAccount($alpha);
        $service = app(PayoutBatchService::class);
        $batch = $service->create($this->maker, $service->payables()['eligible']->keys()->all());

        $this->assertStringContainsString("'=HYPERLINK", $service->manualList($batch));
    }

    public function test_the_account_number_is_encrypted_and_kept_out_of_the_audit_log(): void
    {
        $batch = $this->batchForAlpha();
        $item = $batch->items()->first();

        $this->assertSame('1234567890123', $item->account_number);
        $this->assertStringNotContainsString('1234567890123', (string) PayoutBatchItem::query()->toBase()->value('account_number'));
        $this->assertSame('0123', $item->account_last4);
        $this->assertFalse(AuditLog::query()->where('new_values', 'like', '%1234567890123%')->exists());
    }

    public function test_a_channel_partner_payout_joins_a_batch_with_the_partners_account(): void
    {
        $partner = ChannelPartner::query()->create(['company_id' => $this->company->id, 'name' => 'Partner Gamma', 'phone' => '01711000020']);
        [, $alpha, , $settlement] = $this->settledProject($partner);
        $this->approvedAccount($partner, '7777');
        $service = app(PayoutBatchService::class);

        $partnerPayout = $settlement->channelPartnerPayouts()->firstOrFail();
        $this->assertTrue($service->payables()['eligible']->has('p:'.$partnerPayout->id));

        $batch = $service->create($this->maker, ['p:'.$partnerPayout->id], $this->fundSource->id);
        $service->approve($batch, $this->checker);
        $service->complete($batch->fresh(), $this->maker, 'REF-4');

        $this->assertSame('paid', $partnerPayout->fresh()->payment_status);
        $this->assertSame('bank', $partnerPayout->fresh()->payment_method);
    }

    public function test_the_admin_pages_make_approve_and_record_a_batch(): void
    {
        [, $alpha] = $this->settledProject();
        $this->approvedAccount($alpha);

        Livewire::test(ListPayoutBatches::class)
            ->callAction('newBatch', ['fund_source_id' => $this->fundSource->id])
            ->assertHasNoActionErrors();
        $batch = PayoutBatch::query()->firstOrFail();
        $this->assertSame(1, $batch->item_count);

        Livewire::test(ViewPayoutBatch::class, ['record' => $batch->getRouteKey()])->assertActionVisible('approve')->callAction('approve');
        $this->assertSame(PayoutBatch::STATUS_DRAFT, $batch->fresh()->status);

        Auth::login($this->checker);
        Livewire::test(ViewPayoutBatch::class, ['record' => $batch->getRouteKey()])->callAction('approve');
        $this->assertSame(PayoutBatch::STATUS_APPROVED, $batch->fresh()->status);

        Livewire::test(ViewPayoutBatch::class, ['record' => $batch->getRouteKey()])
            ->callAction('download')
            ->assertFileDownloaded('payout-batch-1.csv');

        Livewire::test(ViewPayoutBatch::class, ['record' => $batch->getRouteKey()])
            ->callAction('complete', ['bank_reference' => 'REF-5', 'paid_on' => now()->toDateString()]);
        $this->assertSame(PayoutBatch::STATUS_COMPLETED, $batch->fresh()->status);
    }

    // ---- Helpers ---------------------------------------------------------

    private function batchForAlpha(): PayoutBatch
    {
        [, $alpha] = $this->settledProject();
        $this->approvedAccount($alpha);

        return $this->batchFor($alpha);
    }

    private function batchFor(Investor $investor): PayoutBatch
    {
        $service = app(PayoutBatchService::class);
        $key = $service->payables()['eligible']->keys()
            ->first(fn (string $key): bool => SettlementPayout::query()->find((int) substr($key, 2))?->investor_id === $investor->id);

        return $service->create($this->maker, [$key], $this->fundSource->id);
    }

    /** @return array<string, string> */
    private function bank(string $suffix = '0123'): array
    {
        return [
            'type' => InvestorBankAccount::TYPE_BANK,
            'bank_name' => 'NCC Bank',
            'branch_name' => 'Motijheel',
            'routing_number' => '160271234',
            'account_name' => 'Account Holder',
            'account_number' => '123456789'.$suffix,
        ];
    }

    private function approvedAccount(Investor|ChannelPartner $holder, string $suffix = '0123'): InvestorBankAccount
    {
        $account = app(InvestorBankAccountService::class)->add($holder, $this->bank($suffix), 'admin');
        app(InvestorBankAccountService::class)->approve($account, $this->checker);

        return $account->fresh();
    }

    /** Two investors (60k / 40k) in a confirmed settlement; the first one brought by $partner if given. */
    private function settledProject(?ChannelPartner $partner = null): array
    {
        static $run = 0;
        $run++;

        $project = InvestmentProject::query()->create([
            'name' => 'Batch Project '.$run,
            'duration_type' => 'custom_days',
            'trade_cycle_days' => 60,
            'start_date' => now()->toDateString(),
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'status' => 'closed',
        ]);
        $alpha = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Investor Alpha', 'phone' => '0171100'.str_pad((string) ($run * 2), 4, '0', STR_PAD_LEFT), 'channel_partner_id' => $partner?->id]);
        $beta = Investor::query()->create(['name' => 'Investor Beta', 'phone' => '0171100'.str_pad((string) ($run * 2 + 1), 4, '0', STR_PAD_LEFT)]);

        foreach ([[$alpha, 60000], [$beta, 40000]] as [$investor, $amount]) {
            Investment::query()->create(['project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => $amount, 'payment_method' => 'bank', 'invested_at' => now()->toDateString(), 'received_by' => $this->maker->id]);
        }

        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Machine', 'amount' => 100000]);

        $settlement = app(SettlementService::class)->calculateAndSettle($project, 200000, $this->maker->id, acknowledgeUnprovenCosts: true);
        app(SettlementService::class)->confirmSettlement($settlement, $this->maker->id);

        return [$project, $alpha, $beta, ProjectSettlement::query()->find($settlement->id)];
    }
}
