<?php

namespace Tests\Feature;

use App\Filament\Investor\Pages\Auth\Register;
use App\Filament\Resources\AgreementTemplates\AgreementTemplateResource;
use App\Filament\Resources\InvestorRequests\InvestorRequestResource;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\InvestmentWithdrawalNotice;
use App\Models\Investor;
use App\Models\InvestorBankAccount;
use App\Models\InvestorCycleElection;
use App\Models\InvestorDraft;
use App\Models\InvestorPortalAccount;
use App\Models\InvestorRequest;
use App\Models\ProjectCostItem;
use App\Models\SettlementPayout;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\AgreementService;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorImportService;
use App\Services\Investment\SettlementService;
use App\Services\InvestorBankAccountService;
use App\Services\InvestorOnboardingService;
use App\Services\InvestorRequestService;
use App\Support\StorefrontApi\Phone;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Phases 3–4 of 15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md: self sign-up
 * through a partner's invite link (reviewed in Investor Drafts), payment
 * accounts approved by staff, agreements accepted in the portal, and
 * investor requests that run the existing staff flows when approved.
 */
class InvestorPortalOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->staff = User::factory()->create(['role' => 'super_admin']);
        $this->company = Company::query()->create([
            'name' => 'Onboarding Co',
            'slug' => 'onboarding-co',
            'domain' => 'onboard.example.test',
            'domain_verified' => true,
            'invoice_prefix' => 'ONB',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
            'investor_portal_enabled' => true,
        ]);
        StorefrontSetting::query()->withoutGlobalScopes()->create(['company_id' => $this->company->id, 'site_type' => StorefrontSetting::SITE_BLADE]);
        app(CompanyContext::class)->set($this->company);
    }

    // ---- Self sign-up ------------------------------------------------------

    public function test_an_applicant_signs_up_with_a_partner_link_and_waits_for_review(): void
    {
        $partner = $this->partnerWithInvite();
        Filament::setCurrentPanel(Filament::getPanel('investor'));

        Livewire::test(Register::class, ['code' => strtolower($partner->invite_code)])
            ->fillForm($this->applicationForm())
            ->call('register')
            ->assertHasNoFormErrors();

        $account = InvestorPortalAccount::query()->where('phone', '01811000001')->firstOrFail();
        $this->assertSame(InvestorPortalAccount::STATUS_PENDING, $account->status);
        $this->assertNull($account->investor_id);
        $this->assertTrue(auth('investor')->check());

        $draft = InvestorDraft::query()->where('portal_account_id', $account->id)->firstOrFail();
        $this->assertSame(InvestorDraft::SOURCE_PORTAL, $draft->source);
        $this->assertSame($partner->id, $draft->channel_partner_id);
        $this->assertSame(['investor_nid', 'investor_photo'], $draft->documents()->orderBy('category')->pluck('category')->all());
        $this->assertNull($draft->documents()->value('uploaded_by'));

        // The applicant sees only the status page; investor pages stay closed.
        $this->actingAs($account, 'investor');
        $this->get('http://investor.onboard.example.test/investor/dashboard')->assertOk()->assertSee(__('Your application is being reviewed'));
        $this->get('http://investor.onboard.example.test/investor/investments')->assertForbidden();
        $this->get('http://investor.onboard.example.test/investor/requests')->assertForbidden();
        $this->get('http://investor.onboard.example.test/investor/payment-accounts')->assertForbidden();
    }

    public function test_sign_up_needs_a_valid_invite_code(): void
    {
        $partner = $this->partnerWithInvite();
        $partner->forceFill(['invite_enabled' => false])->save();
        Filament::setCurrentPanel(Filament::getPanel('investor'));

        Livewire::test(Register::class, ['code' => $partner->invite_code])
            ->assertSee(__('Sign-up works only through an invite link from a channel partner of the company. Please ask your channel partner or the company office for one.'));

        $this->get('http://investor.onboard.example.test/investor/register')->assertOk()->assertSee(__('Sign-up works only through an invite link from a channel partner of the company. Please ask your channel partner or the company office for one.'), false);
    }

    public function test_confirming_the_draft_activates_the_account_and_moves_the_documents(): void
    {
        $account = $this->registeredApplicant();
        $draft = InvestorDraft::query()->where('portal_account_id', $account->id)->firstOrFail();
        Auth::login($this->staff);

        $investor = app(InvestorImportService::class)->confirm($draft);

        $account->refresh();
        $this->assertSame(InvestorPortalAccount::STATUS_ACTIVE, $account->status);
        $this->assertSame($investor->id, $account->investor_id);
        $this->assertSame(2, $investor->documents()->count());
        $this->assertSame(0, InvestmentDocument::query()->where('documentable_type', (new InvestorDraft)->getMorphClass())->count());
        $this->assertSame($draft->channel_partner_id, $investor->channel_partner_id);
    }

    public function test_a_rejected_application_shows_its_reason_and_discarding_also_rejects(): void
    {
        $account = $this->registeredApplicant();
        $draft = InvestorDraft::query()->where('portal_account_id', $account->id)->firstOrFail();

        app(InvestorOnboardingService::class)->reject($draft, 'NID copy is not readable.');
        $draft->delete();

        $account->refresh();
        $this->assertSame(InvestorPortalAccount::STATUS_REJECTED, $account->status);
        $this->assertSame(0, InvestmentDocument::query()->count());

        $this->actingAs($account, 'investor');
        $this->get('http://investor.onboard.example.test/investor/dashboard')->assertOk()->assertSee('NID copy is not readable.');

        $second = $this->registeredApplicant('01811000009', '9999999999');
        InvestorDraft::query()->where('portal_account_id', $second->id)->firstOrFail()->delete();
        $this->assertSame(InvestorPortalAccount::STATUS_REJECTED, $second->fresh()->status);
    }

    // ---- Payment accounts ----------------------------------------------------

    public function test_payment_accounts_wait_for_approval_and_the_approved_one_is_locked(): void
    {
        $investor = $this->investor('Payee', '01811000020');
        $service = app(InvestorBankAccountService::class);

        $first = $service->add($investor, ['type' => 'bank', 'bank_name' => 'NCC Bank', 'branch_name' => 'Motijheel', 'routing_number' => '160271234', 'account_name' => 'Payee', 'account_number' => '0012 3456 7890']);
        $this->assertSame(InvestorBankAccount::STATUS_PENDING, $first->status);
        $this->assertSame('7890', $first->account_last4);
        $this->assertNotSame('001234567890', $first->getRawOriginal('account_number'));

        $service->approve($first, $this->staff);
        $second = $service->add($investor, ['type' => 'mfs', 'mfs_provider' => 'bkash', 'mfs_number' => '01811000020']);
        $service->approve($second, $this->staff);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        $this->expectException(ValidationException::class);
        $first->fresh()->forceFill(['account_number' => '999'])->save();
    }

    public function test_a_bank_account_needs_a_nine_digit_routing_number(): void
    {
        $this->expectException(ValidationException::class);
        app(InvestorBankAccountService::class)->add($this->investor('Bad', '01811000021'), ['type' => 'bank', 'bank_name' => 'X', 'branch_name' => 'Y', 'routing_number' => '12', 'account_name' => 'Z', 'account_number' => '1']);
    }

    // ---- Agreements ------------------------------------------------------------

    public function test_a_published_agreement_is_accepted_once_with_its_hash_and_never_changes(): void
    {
        $account = $this->activeAccount($this->investor('Signer', '01811000030'));
        $template = AgreementTemplate::query()->create(['company_id' => $this->company->id, 'kind' => AgreementTemplate::KIND_INVESTOR, 'title' => 'Mudarabah deed', 'body' => 'Clause 1 ...']);
        $agreements = app(AgreementService::class);

        $this->assertCount(0, $agreements->outstanding($account));
        $agreements->publish($template, $this->staff);
        $this->assertCount(1, $agreements->outstanding($account));

        $acceptance = $agreements->accept($account, $template->fresh());
        $this->assertSame(hash('sha256', "Mudarabah deed\nClause 1 ..."), $acceptance->body_sha256);
        $this->assertCount(0, $agreements->outstanding($account));
        $this->assertSame(1, AgreementAcceptance::query()->count());

        $next = AgreementTemplate::query()->create(['company_id' => $this->company->id, 'kind' => AgreementTemplate::KIND_INVESTOR, 'title' => 'Mudarabah deed', 'body' => 'Clause 1 changed']);
        $this->assertSame(2, $next->version);
        $agreements->publish($next, $this->staff);
        $this->assertCount(1, $agreements->outstanding($account));

        try {
            $template->fresh()->update(['body' => 'edited']);
            $this->fail('A published agreement was edited.');
        } catch (ValidationException) {
        }

        $this->expectException(LogicException::class);
        $acceptance->update(['name' => 'Someone else']);
    }

    // ---- Requests ----------------------------------------------------------------

    public function test_an_approved_top_up_adds_the_investment(): void
    {
        $project = $this->project('running');
        $account = $this->activeAccount($this->investor('TopUp', '01811000040'));
        $service = app(InvestorRequestService::class);

        $request = $service->create($account, InvestorRequest::TYPE_TOP_UP, ['project_id' => $project->id, 'amount' => 50000, 'payment_method' => 'bank', 'payment_reference' => 'TRX-1']);

        try {
            $service->create($account, InvestorRequest::TYPE_TOP_UP, ['project_id' => $project->id, 'amount' => 1000, 'payment_method' => 'bank']);
            $this->fail('A second pending top-up for the same project was accepted.');
        } catch (ValidationException) {
        }

        Auth::login($this->staff);
        $service->approve($request, $this->staff, ['amount' => 50000, 'invested_at' => now()->toDateString(), 'payment_method' => 'bank']);

        $request->refresh();
        $this->assertSame(InvestorRequest::STATUS_APPROVED, $request->status);
        $this->assertInstanceOf(Investment::class, $request->result);
        $this->assertSame(50000.0, (float) $request->result->amount);
        $this->assertSame('TRX-1', $request->result->payment_reference);
    }

    public function test_an_approved_withdrawal_request_starts_a_sixty_day_notice(): void
    {
        $project = $this->project('running');
        $investor = $this->investor('Leaving', '01811000050');
        $this->invest($project, $investor, 100000);
        $account = $this->activeAccount($investor);
        $service = app(InvestorRequestService::class);

        $this->travelTo(now()->startOfDay());
        $request = $service->create($account, InvestorRequest::TYPE_WITHDRAWAL, ['project_id' => $project->id, 'message' => 'Need the money']);
        $this->travel(3)->days();
        $service->approve($request, $this->staff);

        $notice = InvestmentWithdrawalNotice::query()->firstOrFail();
        $this->assertSame($request->created_at->toDateString(), $notice->notice_given_at->toDateString());
        $this->assertSame($request->created_at->copy()->addDays(60)->toDateString(), $notice->effective_at->toDateString());

        // A project without their money cannot be chosen.
        $this->expectException(ValidationException::class);
        $service->create($account, InvestorRequest::TYPE_WITHDRAWAL, ['project_id' => $this->project('running')->id]);
    }

    public function test_a_reinvest_request_records_the_election_on_their_own_pending_payout_only(): void
    {
        [$payoutA, $accountA, $accountB] = $this->settledPayouts();
        $next = $this->project('open');
        $service = app(InvestorRequestService::class);

        try {
            $service->create($accountB, InvestorRequest::TYPE_REINVEST, ['settlement_payout_id' => $payoutA->id, 'election' => 'withdraw']);
            $this->fail('An investor chose for someone else\'s payout.');
        } catch (ValidationException) {
        }

        $request = $service->create($accountA, InvestorRequest::TYPE_REINVEST, ['settlement_payout_id' => $payoutA->id, 'election' => 'partial_reinvest', 'amount' => 10000, 'next_project_id' => $next->id]);

        $manager = User::factory()->create(['role' => 'manager']);
        try {
            $service->approve($request, $manager);
            $this->fail('A user without investments.settle approved a payout choice.');
        } catch (ValidationException) {
        }

        Auth::login($this->staff);
        $service->approve($request, $this->staff);

        $election = InvestorCycleElection::query()->firstOrFail();
        $this->assertSame('partial_reinvest', $election->election);
        $this->assertSame(10000.0, (float) $election->reinvest_amount);
        $this->assertSame($next->id, Investment::query()->find($election->reinvestment_id)->project_id);
    }

    public function test_support_needs_an_answer_and_a_pending_request_can_be_cancelled(): void
    {
        $account = $this->activeAccount($this->investor('Asker', '01811000060'));
        $other = $this->activeAccount($this->investor('Other', '01811000061'));
        $service = app(InvestorRequestService::class);

        $question = $service->create($account, InvestorRequest::TYPE_SUPPORT, ['message' => 'When is the next payout?']);

        try {
            $service->approve($question, $this->staff, []);
            $this->fail('A question was closed without an answer.');
        } catch (ValidationException) {
        }

        $service->approve($question, $this->staff, ['admin_note' => 'Next month.']);
        $this->assertSame('Next month.', $question->fresh()->admin_note);

        $second = $service->create($account, InvestorRequest::TYPE_SUPPORT, ['message' => 'Another one']);

        try {
            $service->cancel($other, $second);
            $this->fail('Someone else cancelled the request.');
        } catch (ValidationException) {
        }

        $service->cancel($account, $second);
        $this->assertSame(InvestorRequest::STATUS_CANCELLED, $second->fresh()->status);
    }

    // ---- Pages ---------------------------------------------------------------------

    public function test_the_new_portal_pages_open(): void
    {
        $partner = $this->partnerWithInvite();
        $investor = $this->investor('Both', '01811000070');
        $account = $this->activeAccount($investor);
        $account->forceFill(['channel_partner_id' => $partner->id])->save();
        AgreementTemplate::query()->create(['company_id' => $this->company->id, 'kind' => AgreementTemplate::KIND_INVESTOR, 'title' => 'Deed', 'body' => 'Text'])
            ->forceFill(['published_at' => now()])->save();

        $this->actingAs($account, 'investor');
        $base = 'http://investor.onboard.example.test/investor';

        $this->get("{$base}/dashboard")->assertOk()->assertSee(__('Please read and accept: :titles', ['titles' => 'Deed']));
        $this->get("{$base}/agreements")->assertOk()->assertSee('Text');
        $this->get("{$base}/requests")->assertOk();
        $this->get("{$base}/payment-accounts")->assertOk();
        $this->get("{$base}/invite-investors")->assertOk()->assertSee('register?code='.$partner->invite_code);
    }

    public function test_staff_see_the_request_queue_and_agreements(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->staff);

        $this->get(InvestorRequestResource::getUrl())->assertOk();
        $this->get(AgreementTemplateResource::getUrl())->assertOk();
        $this->get(AgreementTemplateResource::getUrl('create'))->assertOk();
    }

    // ---- Helpers ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function applicationForm(string $phone = '01811000001', string $nid = '1234567890'): array
    {
        return [
            'name' => 'New Applicant',
            'phone' => $phone,
            'nid_number' => $nid,
            'nominee_name' => 'Nominee',
            'nid_files' => [UploadedFile::fake()->image('nid-front.jpg')],
            'photo_files' => [UploadedFile::fake()->image('photo.jpg')],
            'password' => 'a-strong-password-1',
            'passwordConfirmation' => 'a-strong-password-1',
        ];
    }

    private function registeredApplicant(string $phone = '01811000001', string $nid = '1234567890'): InvestorPortalAccount
    {
        $partner = ChannelPartner::query()->where('invite_enabled', true)->first() ?? $this->partnerWithInvite();
        Filament::setCurrentPanel(Filament::getPanel('investor'));
        auth('investor')->logout();

        Livewire::test(Register::class, ['code' => $partner->invite_code])
            ->fillForm($this->applicationForm($phone, $nid))
            ->call('register')
            ->assertHasNoFormErrors();

        auth('investor')->logout();

        return InvestorPortalAccount::query()->where('phone', $phone)->firstOrFail();
    }

    private function partnerWithInvite(): ChannelPartner
    {
        $partner = ChannelPartner::query()->create(['company_id' => $this->company->id, 'name' => 'Partner '.Str::random(4), 'phone' => '0171'.random_int(1000000, 9999999), 'is_active' => true]);
        $partner->ensureInviteCode();
        $partner->forceFill(['invite_enabled' => true])->save();

        return $partner->fresh();
    }

    private function investor(string $name, string $phone): Investor
    {
        return Investor::query()->create(['company_id' => $this->company->id, 'name' => $name, 'phone' => $phone]);
    }

    private function activeAccount(Investor $investor): InvestorPortalAccount
    {
        $account = InvestorPortalAccount::query()->create([
            'company_id' => $this->company->id,
            'investor_id' => $investor->id,
            'name' => $investor->name,
            'phone' => Phone::normalize($investor->phone),
            'status' => InvestorPortalAccount::STATUS_ACTIVE,
        ]);
        $account->forceFill(['password' => 'a-strong-password-1'])->save();

        return $account;
    }

    private function project(string $status): InvestmentProject
    {
        return InvestmentProject::query()->create([
            'company_id' => $this->company->id,
            'name' => 'Project '.Str::random(4),
            'duration_type' => 'custom_days',
            'trade_cycle_days' => 60,
            'start_date' => now()->toDateString(),
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'status' => $status,
        ]);
    }

    private function invest(InvestmentProject $project, Investor $investor, float $amount): Investment
    {
        return Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => $amount, 'payment_method' => 'bank', 'invested_at' => now()->toDateString(), 'received_by' => $this->staff->id]);
    }

    /** @return array{0: SettlementPayout, 1: InvestorPortalAccount, 2: InvestorPortalAccount} */
    private function settledPayouts(): array
    {
        Auth::login($this->staff);
        $project = $this->project('closed');
        $a = $this->investor('Payout A', '01811000080');
        $b = $this->investor('Payout B', '01811000081');
        $this->invest($project, $a, 60000);
        $this->invest($project, $b, 40000);
        ProjectCostItem::query()->create(['project_id' => $project->id, 'category' => 'landed_cost', 'label' => 'Goods', 'amount' => 100000]);
        $settlement = app(SettlementService::class)->calculateAndSettle($project, 200000, $this->staff->id, acknowledgeUnprovenCosts: true);
        app(SettlementService::class)->confirmSettlement($settlement, $this->staff->id);
        Auth::logout();

        return [$settlement->payouts()->where('investor_id', $a->id)->firstOrFail(), $this->activeAccount($a), $this->activeAccount($b)];
    }
}
