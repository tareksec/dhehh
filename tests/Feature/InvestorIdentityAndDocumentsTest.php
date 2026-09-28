<?php

namespace Tests\Feature;

use App\Filament\Resources\InvestmentProjects\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Investors\Pages\CreateInvestor;
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
use App\Services\Investment\InvestorDocumentUploadService;
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

    public function test_bulk_upload_attaches_each_file_to_the_investor_with_that_nid(): void
    {
        Storage::fake('local');
        $karim = $this->investor('Karim', '01711111111', '1479939603');
        $sourav = $this->investor('Sourav', '01722222222', 'EM0162269');

        $result = app(InvestorDocumentUploadService::class)->attach([
            $this->tempUpload('1479939603_nid.jpg'),
            $this->tempUpload('1479939603_nominee.jpg'),
            $this->tempUpload('১৪৭৯৯৩৯৬০৩ contract.pdf'),
            $this->tempUpload('EM0162269-cheque.jpg'),
            $this->tempUpload('9999999999_nid.jpg'),
        ], $this->company);

        $this->assertSame(4, $result['attached']);
        $this->assertSame(['9999999999_nid.jpg'], $result['unmatched']);
        $this->assertEqualsCanonicalizing(
            [InvestmentDocument::CATEGORY_INVESTOR_NID, InvestmentDocument::CATEGORY_NOMINEE_NID, 'signed_contract'],
            $karim->documents()->pluck('category')->all(),
        );
        $this->assertSame(['cheque_copy'], $sourav->documents()->pluck('category')->all());

        $path = $karim->documents()->where('category', InvestmentDocument::CATEGORY_INVESTOR_NID)->value('file_path');
        $this->assertStringStartsWith($this->company->storageRoot().'/private/', $path);
    }

    public function test_the_bulk_upload_action_on_the_investor_list_works(): void
    {
        Storage::fake('local');
        $karim = $this->investor('Karim', '01711111111', '1479939603');

        Livewire::test(ListInvestors::class)
            ->callAction('uploadDocumentsByNid', ['files' => [UploadedFile::fake()->create('1479939603_nid.jpg', 10, 'image/jpeg')]])
            ->assertHasNoActionErrors();

        $this->assertSame([InvestmentDocument::CATEGORY_INVESTOR_NID], $karim->documents()->pluck('category')->all());
        $this->assertSame([], Storage::disk('local')->allFiles('imports/investor-documents'));
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

    private function investor(string $name, string $phone, string $nid, array $extra = []): Investor
    {
        return Investor::query()->create(['company_id' => $this->company->id, 'name' => $name, 'phone' => $phone, 'nid_number' => $nid, ...$extra]);
    }

    private function project(): InvestmentProject
    {
        return InvestmentProject::query()->create([
            'company_id' => $this->company->id,
            'project_code' => 'INV-1',
            'name' => 'Sharing machine',
            'duration_type' => 'custom_days',
            'trade_cycle_days' => 60,
            'start_date' => now()->toDateString(),
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'status' => 'open',
        ]);
    }

    private function document(Investor|InvestmentProject $owner, string $category): InvestmentDocument
    {
        return $owner->documents()->create([
            'company_id' => $this->company->id,
            'category' => $category,
            'file_path' => app(CompanyStorageService::class)->putPrivate($this->company, 'investment-documents', $category.'.pdf', 'copy'),
        ]);
    }

    /** @return array{path: string, name: string} */
    private function tempUpload(string $name): array
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, 'scan');

        return ['path' => $path, 'name' => $name];
    }
}
