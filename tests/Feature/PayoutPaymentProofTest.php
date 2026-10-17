<?php

namespace Tests\Feature;

use App\Filament\Investor\Pages\Commissions;
use App\Filament\Investor\Pages\Documents;
use App\Filament\Investor\Pages\Payouts;
use App\Filament\Resources\PayoutBatches\Pages\ViewPayoutBatch;
use App\Filament\Resources\PayoutBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\ProjectSettlements\Pages\ViewProjectSettlement;
use App\Filament\Resources\ProjectSettlements\RelationManagers\ChannelPartnerPayoutsRelationManager;
use App\Filament\Resources\ProjectSettlements\RelationManagers\PayoutsRelationManager;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\FundSource;
use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\InvestorPortalAccount;
use App\Models\PayoutBatch;
use App\Models\PayoutBatchItem;
use App\Models\ProjectCostItem;
use App\Models\ProjectSettlement;
use App\Models\SettlementPayout;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\Investment\PayoutBatchService;
use App\Services\Investment\PayoutProofService;
use App\Services\Investment\SettlementService;
use App\Services\InvestorBankAccountService;
use App\Services\InvestorPortalAccountService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The payment screenshot on a payout (16_INVESTOR_GROUPS_PLAN.md, phase G4):
 * staff pay by hand, upload the screenshot, and the person paid sees it in
 * the portal. Files stay private to the company; only the person paid (and
 * staff) can open them.
 */
class PayoutPaymentProofTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $staff;

    private FundSource $fundSource;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->company = $this->makeCompany('proof.example.test', 'Proof Co');
        app(CompanyContext::class)->set($this->company);
        $this->staff = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        Auth::login($this->staff);

        $account = Account::query()->create(['name' => 'Bank', 'type' => 'bank', 'opening_balance' => 100000000]);
        $this->fundSource = FundSource::query()->create(['name' => 'Payouts', 'type' => 'owner_investment', 'account_id' => $account->id]);
    }

    // -------------------------------------------------------------- the service

    public function test_only_a_paid_payout_takes_a_screenshot(): void
    {
        [$settlement, $payout] = $this->confirmedSettlement();

        try {
            app(PayoutProofService::class)->attach($payout, $this->storedFile('slip.jpg'), $this->staff);
            $this->fail('A screenshot was added to an unpaid payout.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_proof', $exception->errors());
        }

        $this->assertSame(0, InvestmentDocument::query()->count());
    }

    public function test_a_screenshot_is_kept_privately_against_the_payout_and_logged(): void
    {
        [$settlement, $payout] = $this->confirmedSettlement();
        $payout->update(['payment_status' => 'paid', 'paid_at' => now()->toDateString()]);
        $path = $this->storedFile('slip.jpg');

        $document = app(PayoutProofService::class)->attach($payout->fresh(), $path, $this->staff);

        $this->assertSame(InvestmentDocument::CATEGORY_PAYMENT_PROOF, $document->category);
        $this->assertTrue($document->documentable->is($payout));
        $this->assertSame($this->staff->id, $document->uploaded_by);
        $this->assertSame($this->company->id, $document->company_id);
        $this->assertStringStartsWith($this->company->storageRoot().'/private/', $document->file_path);
        $this->assertTrue($payout->fresh()->hasPaymentProof());
        $this->assertSame($document->id, $payout->fresh()->paymentProof()->id);
        $this->assertNotNull(AuditLog::query()->where('action', 'payout_payment_proof_uploaded')->first());

        // A newer upload becomes the one shown; the older stays on record.
        $newer = app(PayoutProofService::class)->attach($payout->fresh(), $this->storedFile('slip-2.png'), $this->staff);
        $this->assertSame($newer->id, $payout->fresh()->paymentProof()->id);
        $this->assertSame(2, $payout->documents()->count());
    }

    // -------------------------------------------------------- staff screens

    public function test_marking_a_payout_paid_can_carry_the_screenshot(): void
    {
        [$settlement, $payout] = $this->confirmedSettlement();

        Livewire::test(PayoutsRelationManager::class, ['ownerRecord' => $settlement, 'pageClass' => ViewProjectSettlement::class])
            ->callTableAction('markPaid', $payout, ['payment_proof' => UploadedFile::fake()->image('slip.jpg')])
            ->assertHasNoTableActionErrors();

        $payout->refresh();
        $this->assertSame('paid', $payout->payment_status);
        $proof = $payout->paymentProof();
        $this->assertNotNull($proof, 'the screenshot is attached when the payout is paid');
        Storage::disk('local')->assertExists($proof->file_path);
        $this->assertNotNull($payout->voucher_id, 'the payout voucher is booked as before');
    }

    public function test_marking_a_payout_paid_works_without_a_screenshot_and_one_can_follow(): void
    {
        [$settlement, $payout] = $this->confirmedSettlement();
        $table = fn () => Livewire::test(PayoutsRelationManager::class, ['ownerRecord' => $settlement, 'pageClass' => ViewProjectSettlement::class]);

        $table()->callTableAction('markPaid', $payout, [])->assertHasNoTableActionErrors();
        $this->assertFalse($payout->fresh()->hasPaymentProof());

        $table()
            ->assertTableActionVisible('paymentProof', $payout->fresh())
            ->callTableAction('paymentProof', $payout->fresh(), ['payment_proof' => UploadedFile::fake()->create('slip.pdf', 200, 'application/pdf')])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($payout->fresh()->hasPaymentProof());
    }

    public function test_the_upload_button_is_not_offered_before_the_payout_is_paid_or_without_permission(): void
    {
        [$settlement, $payout] = $this->confirmedSettlement();
        $component = fn () => Livewire::test(PayoutsRelationManager::class, ['ownerRecord' => $settlement, 'pageClass' => ViewProjectSettlement::class]);

        $component()->assertTableActionHidden('paymentProof', $payout);

        $payout->update(['payment_status' => 'paid', 'paid_at' => now()->toDateString()]);
        UserRole::query()->create(['name' => 'Investment Viewer', 'slug' => 'investment_viewer', 'is_active' => true, 'permissions' => ['investments.view']]);
        Auth::login(User::factory()->create(['role' => 'investment_viewer', 'is_active' => true]));

        $component()->assertTableActionHidden('paymentProof', $payout->fresh());

        Auth::login($this->staff);
        $component()->assertTableActionVisible('paymentProof', $payout->fresh());
    }

    public function test_only_images_and_pdfs_are_accepted(): void
    {
        [$settlement, $payout] = $this->confirmedSettlement();
        $payout->update(['payment_status' => 'paid', 'paid_at' => now()->toDateString()]);

        Livewire::test(PayoutsRelationManager::class, ['ownerRecord' => $settlement, 'pageClass' => ViewProjectSettlement::class])
            ->callTableAction('paymentProof', $payout->fresh(), ['payment_proof' => UploadedFile::fake()->create('malware.exe', 50, 'application/x-msdownload')])
            ->assertHasTableActionErrors(['payment_proof']);

        $this->assertFalse($payout->fresh()->hasPaymentProof());
    }

    public function test_a_channel_partner_payout_takes_a_screenshot_too(): void
    {
        [$settlement] = $this->confirmedSettlement(withPartner: true);
        $partnerPayout = $settlement->channelPartnerPayouts()->firstOrFail();

        Livewire::test(ChannelPartnerPayoutsRelationManager::class, ['ownerRecord' => $settlement, 'pageClass' => ViewProjectSettlement::class])
            ->callTableAction('markPaid', $partnerPayout, ['payment_method' => 'bank', 'payment_reference' => 'TRX-9', 'payment_proof' => UploadedFile::fake()->image('commission.png')])
            ->assertHasNoTableActionErrors();

        $partnerPayout->refresh();
        $this->assertSame('paid', $partnerPayout->payment_status);
        $this->assertSame('TRX-9', $partnerPayout->payment_reference);
        $this->assertTrue($partnerPayout->hasPaymentProof());
    }

    public function test_a_payout_in_a_batch_takes_its_screenshot_from_the_batch(): void
    {
        [$settlement, $payout] = $this->confirmedSettlement();
        $batch = $this->paidBatch($payout);
        $item = PayoutBatchItem::query()->where('payable_id', $payout->id)->firstOrFail();

        $this->assertSame('paid', $payout->fresh()->payment_status);

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $batch, 'pageClass' => ViewPayoutBatch::class])
            ->assertTableActionVisible('paymentProof', $item)
            ->callTableAction('paymentProof', $item, ['payment_proof' => UploadedFile::fake()->image('batch-slip.jpg')])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($payout->fresh()->hasPaymentProof());
    }

    // ------------------------------------------------------- what the person sees

    public function test_the_person_paid_sees_and_downloads_their_own_screenshot_only(): void
    {
        [$settlement, $payout, $mine, $theirs] = $this->confirmedSettlement();
        $other = $settlement->payouts()->where('investor_id', $theirs->id)->firstOrFail();
        foreach ([$payout, $other] as $row) {
            $row->update(['payment_status' => 'paid', 'paid_at' => now()->toDateString()]);
        }
        $mineProof = app(PayoutProofService::class)->attach($payout->fresh(), $this->storedFile('mine.jpg'), $this->staff);
        $theirProof = app(PayoutProofService::class)->attach($other->fresh(), $this->storedFile('theirs.jpg'), $this->staff);

        $this->portalSessionOf($mine);

        Livewire::test(Payouts::class)
            ->assertCanSeeTableRecords([$payout])
            ->assertCanNotSeeTableRecords([$other])
            ->assertTableActionVisible('paymentProof', $payout->fresh());

        Livewire::test(Documents::class)
            ->assertCanSeeTableRecords([$mineProof])
            ->assertCanNotSeeTableRecords([$theirProof]);

        $base = 'http://investor.proof.example.test/investor';
        $this->get("{$base}/documents/{$mineProof->getKey()}/download")->assertOk();
        $this->get("{$base}/documents/{$theirProof->getKey()}/download")->assertNotFound();
    }

    public function test_a_screenshot_of_another_company_is_never_served(): void
    {
        [$settlement, $payout, $mine] = $this->confirmedSettlement();
        $payout->update(['payment_status' => 'paid', 'paid_at' => now()->toDateString()]);
        app(PayoutProofService::class)->attach($payout->fresh(), $this->storedFile('mine.jpg'), $this->staff);

        $other = $this->makeCompany('other-proof.example.test', 'Other Proof Co');
        app(CompanyContext::class)->set($other);
        $stranger = Investor::query()->create(['company_id' => $other->id, 'name' => 'Stranger', 'phone' => '01850000099']);
        $this->portalSessionOf($stranger, $other);

        Livewire::test(Documents::class)->assertCanNotSeeTableRecords(InvestmentDocument::withoutGlobalScopes()->get());
    }

    public function test_a_partner_sees_the_screenshot_of_their_own_commission(): void
    {
        [$settlement] = $this->confirmedSettlement(withPartner: true);
        $partnerPayout = $settlement->channelPartnerPayouts()->firstOrFail();
        $partnerPayout->update(['payment_status' => 'paid', 'paid_at' => now()->toDateString()]);
        $proof = app(PayoutProofService::class)->attach($partnerPayout->fresh(), $this->storedFile('commission.jpg'), $this->staff);

        $partner = ChannelPartner::query()->findOrFail($partnerPayout->channel_partner_id);
        $this->portalSessionOfPartner($partner);

        Livewire::test(Commissions::class)->assertTableActionVisible('paymentProof', $partnerPayout->fresh());
        Livewire::test(Documents::class)->assertCanSeeTableRecords([$proof]);
        $this->get("http://investor.proof.example.test/investor/documents/{$proof->getKey()}/download")->assertOk();
    }

    public function test_staff_download_a_screenshot_with_the_view_permission(): void
    {
        [$settlement, $payout] = $this->confirmedSettlement();
        $payout->update(['payment_status' => 'paid', 'paid_at' => now()->toDateString()]);
        $proof = app(PayoutProofService::class)->attach($payout->fresh(), $this->storedFile('slip.jpg'), $this->staff);

        $this->actingAs($this->staff)->withSession(['current_company_id' => $this->company->id])
            ->get(route('investment-documents.download', $proof))
            ->assertOk();

        $accountant = User::factory()->create(['role' => 'accountant', 'is_active' => true]);
        $accountant->companies()->attach($this->company, ['role' => 'accountant', 'is_default' => true]);
        $this->actingAs($accountant)->withSession(['current_company_id' => $this->company->id])
            ->get(route('investment-documents.download', $proof))
            ->assertOk();

        $sales = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);
        $sales->companies()->attach($this->company, ['role' => 'sales_staff', 'is_default' => true]);
        $this->actingAs($sales)->withSession(['current_company_id' => $this->company->id])
            ->get(route('investment-documents.download', $proof))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    private function makeCompany(string $domain, string $name): Company
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr(md5($name), 0, 4)),
            'currency' => 'BDT',
            'timezone' => 'UTC',
            'is_active' => true,
            'investor_portal_enabled' => true,
        ]);
        StorefrontSetting::query()->withoutGlobalScopes()->create(['company_id' => $company->id, 'site_type' => StorefrontSetting::SITE_BLADE]);

        return $company;
    }

    /** A file in the company's private storage, as the upload field leaves it. */
    private function storedFile(string $name): string
    {
        $path = $this->company->storageRoot().'/private/payout-proofs/'.$name;
        Storage::disk('local')->put($path, 'screenshot');

        return $path;
    }

    /**
     * A confirmed settlement of an old-style project with two investors (and a partner when asked).
     *
     * @return array{0: ProjectSettlement, 1: SettlementPayout, 2: Investor, 3: Investor}
     */
    private function confirmedSettlement(bool $withPartner = false): array
    {
        $partner = $withPartner ? ChannelPartner::query()->create(['company_id' => $this->company->id, 'name' => 'Partner', 'phone' => '01850000050']) : null;
        $project = InvestmentProject::query()->create([
            'company_id' => $this->company->id, 'name' => 'Proof Project', 'duration_type' => '2_month', 'start_date' => now()->toDateString(),
            'investor_share_percent' => 40, 'channel_partner_share_percent' => 10, 'company_share_percent' => 50, 'status' => 'closed',
            'payout_fund_source_id' => $this->fundSource->id,
        ]);
        $mine = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Mine', 'phone' => '01850000001', 'channel_partner_id' => $partner?->id]);
        $theirs = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Theirs', 'phone' => '01850000002']);

        foreach ([[$mine, 300000], [$theirs, 200000]] as [$investor, $amount]) {
            Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => $amount, 'payment_method' => 'bank', 'invested_at' => now()->toDateString(), 'received_by' => $this->staff->id]);
        }

        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 400000]);
        $settlement = app(SettlementService::class)->calculateAndSettle($project, 700000, $this->staff->id, acknowledgeUnprovenCosts: true);
        app(SettlementService::class)->confirmSettlement($settlement, $this->staff->id);

        return [$settlement->fresh(), $settlement->payouts()->where('investor_id', $mine->id)->firstOrFail(), $mine, $theirs];
    }

    /** A batch whose only payout was recorded paid. */
    private function paidBatch(SettlementPayout $payout): PayoutBatch
    {
        $holder = $payout->investor;
        $accounts = app(InvestorBankAccountService::class);
        $checker = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $account = $accounts->add($holder, [
            'type' => InvestorBankAccount::TYPE_BANK, 'bank_name' => 'NCC Bank', 'branch_name' => 'Motijheel',
            'routing_number' => '160271234', 'account_name' => 'Account Holder', 'account_number' => '1234567890123',
        ], 'admin');
        $accounts->approve($account, $checker);

        $service = app(PayoutBatchService::class);
        $batch = $service->create($this->staff, ['s:'.$payout->id], $this->fundSource->id, now()->toDateString());
        $service->approve($batch, $checker);
        $service->export($batch->fresh(), $this->staff);
        $service->complete($batch->fresh(), $this->staff, 'BATCH-1', now()->toDateString());

        return $batch->fresh();
    }

    private function portalSessionOf(Investor $investor, ?Company $company = null): InvestorPortalAccount
    {
        $account = app(InvestorPortalAccountService::class)->ensureAccount($investor);
        $account->forceFill(['status' => InvestorPortalAccount::STATUS_ACTIVE, 'password' => 'a-strong-password-1'])->save();

        return $this->signIn($account, $company ?? $this->company);
    }

    private function portalSessionOfPartner(ChannelPartner $partner): InvestorPortalAccount
    {
        $account = app(InvestorPortalAccountService::class)->ensureAccount($partner);
        $account->forceFill(['status' => InvestorPortalAccount::STATUS_ACTIVE, 'password' => 'a-strong-password-1'])->save();

        return $this->signIn($account, $this->company);
    }

    private function signIn(InvestorPortalAccount $account, Company $company): InvestorPortalAccount
    {
        $request = Request::create('/investor');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        app(CompanyContext::class)->set($company);
        Filament::setCurrentPanel(Filament::getPanel('investor'));
        $this->actingAs($account, 'investor');

        return $account;
    }
}
