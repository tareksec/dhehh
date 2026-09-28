<?php

namespace Tests\Feature;

use App\Filament\Resources\InvestmentProjects\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Investors\Pages\CreateInvestor;
use App\Filament\Resources\Investors\Pages\EditInvestor;
use App\Filament\Resources\Investors\Pages\ListInvestors;
use App\Filament\Resources\Investors\Pages\ViewInvestor;
use App\Models\Company;
use App\Models\Investment;
use App\Models\InvestmentDocument;
use App\Models\InvestmentProject;
use App\Models\Investor;
use App\Models\InvestorDraft;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Investment\InvestorImportService;
use App\Support\InvestorIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner's rules (2026-09-28): the NID / passport number is the investor ID;
 * NID copies and contracts are visible to investments.manage only; one
 * contract per project covers all its investors.
 */
class InvestorIdentityAndDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Identity Co',
            'slug' => 'identity-co',
            'invoice_prefix' => 'IDC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->admin = User::factory()->create(['role' => 'super_admin']);
        Auth::login($this->admin);
    }

    public function test_the_same_nid_in_any_format_cannot_belong_to_two_investors(): void
    {
        $this->investor('First', '01711111111', '1990 1234-56789');

        $this->assertSame('1990123456789', InvestorIdentity::normalize('১৯৯০১২৩৪৫৬৭৮৯'));

        $this->expectException(ValidationException::class);
        $this->investor('Second', '01722222222', '১৯৯০১২৩৪৫৬৭৮৯');
    }

    public function test_the_investor_form_requires_a_unique_nid(): void
    {
        $this->investor('First', '01711111111', 'EM0162269');

        Livewire::test(CreateInvestor::class)
            ->fillForm(['name' => 'No NID', 'phone' => '01733333333'])
            ->call('create')
            ->assertHasFormErrors(['nid_number' => 'required']);

        Livewire::test(CreateInvestor::class)
            ->fillForm(['name' => 'Same passport', 'phone' => '01744444444', 'nid_number' => 'em 0162269'])
            ->call('create')
            ->assertHasFormErrors(['nid_number']);

        $this->assertSame(1, Investor::query()->count());
    }

    public function test_investors_can_be_found_by_nid_even_though_it_is_encrypted(): void
    {
        $karim = $this->investor('Karim', '01711111111', '1479939603');
        $other = $this->investor('Other', '01722222222', '1901688455');

        Livewire::test(ListInvestors::class)
            ->searchTable('1479 939 603')
            ->assertCanSeeTableRecords([$karim])
            ->assertCanNotSeeTableRecords([$other]);

        $this->assertTrue(Investor::query()->whereNid('১৪৭৯৯৩৯৬০৩')->first()->is($karim));
    }

    public function test_the_checklist_needs_nid_nominee_nid_and_one_contract_per_project(): void
    {
        Storage::fake('local');
        $karim = $this->investor('Karim', '01711111111', '1479939603', ['nominee_name' => 'Karima']);
        $project = $this->project();
        Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $karim->id, 'amount' => 200000, 'payment_method' => 'bank', 'invested_at' => now()->toDateString()]);

        $this->assertSame([false, false, false], array_values($karim->documentChecklist()));
        $this->assertTrue(Investor::query()->missingDocuments()->whereKey($karim->id)->exists());

        $this->document($karim, InvestmentDocument::CATEGORY_INVESTOR_NID);
        $this->document($karim, InvestmentDocument::CATEGORY_NOMINEE_NID);
        // One contract on the project covers every investor in it.
        $this->document($project, InvestmentDocument::CATEGORY_PROJECT_CONTRACT);

        $this->assertSame([true, true, true], array_values($karim->documentChecklist()));
        $this->assertFalse(Investor::query()->missingDocuments()->whereKey($karim->id)->exists());
    }

    public function test_nid_copies_and_contracts_need_the_manage_permission(): void
    {
        Storage::fake('local');
        $karim = $this->investor('Karim', '01711111111', '1479939603');
        $project = $this->project();
        $nidCopy = $this->document($karim, InvestmentDocument::CATEGORY_INVESTOR_NID);
        $contract = $this->document($project, InvestmentDocument::CATEGORY_PROJECT_CONTRACT);
        $sheet = $this->document($project, 'settlement_sheet');

        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->companies()->attach($this->company, ['role' => 'accountant', 'is_default' => true]);
        $this->assertTrue($accountant->hasPermission('investments.view'));
        $this->assertFalse($accountant->hasPermission('investments.manage'));

        foreach ([$nidCopy, $contract] as $document) {
            $this->actingAs($accountant)->withSession(['current_company_id' => $this->company->id])
                ->get(route('investment-documents.download', $document))
                ->assertForbidden();
        }

        $this->actingAs($accountant)->withSession(['current_company_id' => $this->company->id])
            ->get(route('investment-documents.download', $sheet))
            ->assertOk();

        $this->actingAs($this->admin)->withSession(['current_company_id' => $this->company->id])
            ->get(route('investment-documents.download', $nidCopy))
            ->assertOk();

        Auth::login($accountant);
        $this->assertFalse(DocumentsRelationManager::canViewForRecord($karim, ViewInvestor::class));
    }

    public function test_a_new_investor_draft_without_an_nid_cannot_be_confirmed(): void
    {
        $draft = InvestorDraft::query()->create(['company_id' => $this->company->id, 'batch_id' => 'b', 'source' => 'text', 'name' => 'No NID', 'phone' => '01755555555']);

        $this->assertContains(__('NID / passport number is missing. It is the investor ID, so it is required.'), $draft->blockingIssues());

        $this->expectException(ValidationException::class);
        app(InvestorImportService::class)->confirm($draft);
    }

    public function test_the_migration_backfills_the_nid_hash_of_existing_investors(): void
    {
        $karim = $this->investor('Karim', '01711111111', '1479939603');
        DB::table('investors')->where('id', $karim->id)->update(['nid_hash' => null]);

        (require database_path('migrations/2026_09_28_110000_add_nid_hash_to_investors_and_investor_drafts.php'))->up();

        $this->assertSame(InvestorIdentity::hash('1479939603'), $karim->fresh()->nid_hash);
    }

    public function test_the_investor_form_saves_uploaded_paper_copies_as_documents(): void
    {
        Storage::fake('local');

        Livewire::test(CreateInvestor::class)
            ->fillForm([
                'name' => 'Karim',
                'phone' => '01711111111',
                'nid_number' => '1479939603',
                'paper_investor_nid' => [UploadedFile::fake()->create('nid.jpg', 10, 'image/jpeg')],
                'paper_nominee_nid' => [UploadedFile::fake()->create('nominee.jpg', 10, 'image/jpeg')],
                'paper_signed_contract' => [UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf')],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $karim = Investor::query()->whereNid('1479939603')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            [InvestmentDocument::CATEGORY_INVESTOR_NID, InvestmentDocument::CATEGORY_NOMINEE_NID, 'signed_contract'],
            $karim->documents()->pluck('category')->all(),
        );
        $this->assertStringStartsWith($this->company->storageRoot().'/private/investment-documents/', $karim->documents()->value('file_path'));

        Livewire::test(EditInvestor::class, ['record' => $karim->getRouteKey()])
            ->fillForm(['paper_cheque_copy' => [UploadedFile::fake()->create('cheque.jpg', 10, 'image/jpeg')]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(4, $karim->documents()->count());
        $this->assertSame(1, $karim->documents()->where('category', 'cheque_copy')->count());
    }

    public function test_the_investor_list_can_be_filtered_by_project(): void
    {
        $inv1 = $this->project('INV-1');
        $inv2 = $this->project('INV-2');
        $karim = $this->investor('Karim', '01711111111', '1479939603');
        $rahim = $this->investor('Rahim', '01722222222', '1901688455');
        $both = $this->investor('Both', '01733333333', '2852404322');
        $this->invest($karim, $inv1);
        $this->invest($rahim, $inv2);
        $this->invest($both, $inv1);
        $this->invest($both, $inv2);

        Livewire::test(ListInvestors::class)
            ->filterTable('project', [$inv1->id])
            ->assertCanSeeTableRecords([$karim, $both])
            ->assertCanNotSeeTableRecords([$rahim]);

        Livewire::test(ListInvestors::class)
            ->filterTable('project', [$inv2->id])
            ->assertCanSeeTableRecords([$rahim, $both])
            ->assertCanNotSeeTableRecords([$karim]);
    }

    public function test_many_investors_can_be_given_a_channel_partner_at_once(): void
    {
        $partner = $this->investor('Partner', '01799999999', '9999999999', ['is_channel_partner' => true]);
        $other = $this->investor('Other partner', '01788888888', '8888888888', ['is_channel_partner' => true]);
        $karim = $this->investor('Karim', '01711111111', '1479939603');
        $rahim = $this->investor('Rahim', '01722222222', '1901688455');
        $taken = $this->investor('Taken', '01733333333', '2852404322', ['channel_partner_id' => $other->id]);

        Livewire::test(ListInvestors::class)
            ->callTableBulkAction('assignChannelPartner', [$karim, $rahim, $taken, $partner], ['channel_partner_id' => $partner->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame($partner->id, $karim->fresh()->channel_partner_id);
        $this->assertSame($partner->id, $rahim->fresh()->channel_partner_id);
        $this->assertSame($other->id, $taken->fresh()->channel_partner_id);
        $this->assertNull($partner->fresh()->channel_partner_id);
    }

    public function test_an_import_can_set_the_channel_partner_for_its_new_investors(): void
    {
        $partner = $this->investor('Partner', '01799999999', '9999999999', ['is_channel_partner' => true]);

        app(InvestorImportService::class)->importText(
            "Name: Karim\nPhone: 01711111111\nNID: 1479939603\n\nName: Rahim\nPhone: 01722222222\nNID: 1901688455",
            investment: ['channel_partner_id' => $partner->id],
        );

        $drafts = InvestorDraft::query()->get();
        $this->assertCount(2, $drafts);
        $this->assertSame([$partner->id, $partner->id], $drafts->pluck('channel_partner_id')->all());

        $karim = app(InvestorImportService::class)->confirm($drafts->first());
        $this->assertSame($partner->id, $karim->channel_partner_id);
    }

    private function investor(string $name, string $phone, string $nid, array $extra = []): Investor
    {
        return Investor::query()->create(['company_id' => $this->company->id, 'name' => $name, 'phone' => $phone, 'nid_number' => $nid, ...$extra]);
    }

    private function project(string $code = 'INV-1'): InvestmentProject
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
            'status' => 'open',
        ]);
    }

    private function invest(Investor $investor, InvestmentProject $project): Investment
    {
        return Investment::query()->create(['company_id' => $this->company->id, 'project_id' => $project->id, 'investor_id' => $investor->id, 'amount' => 200000, 'payment_method' => 'bank', 'invested_at' => now()->toDateString()]);
    }

    private function document(Investor|InvestmentProject $owner, string $category): InvestmentDocument
    {
        return $owner->documents()->create([
            'company_id' => $this->company->id,
            'category' => $category,
            'file_path' => app(CompanyStorageService::class)->putPrivate($this->company, 'investment-documents', $category.'.pdf', 'copy'),
        ]);
    }
}
