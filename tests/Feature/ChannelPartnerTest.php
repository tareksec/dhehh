<?php

namespace Tests\Feature;

use App\Filament\Resources\ChannelPartners\Pages\CreateChannelPartner;
use App\Filament\Resources\ChannelPartners\Pages\ListChannelPartners;
use App\Filament\Resources\ChannelPartners\Pages\ViewChannelPartner;
use App\Filament\Resources\ChannelPartners\RelationManagers\ReferredInvestorsRelationManager;
use App\Filament\Resources\InvestmentProjects\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Investors\Pages\ListInvestors;
use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\InvestmentDocument;
use App\Models\Investor;
use App\Models\InvestorDraft;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\Investment\InvestorImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner (2026-09-28): channel partners are not investors. They have their own
 * record (name, phone, address, NID as partner ID, payment account, contract
 * and NID copies) and their own accounts.
 */
class ChannelPartnerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create(['name' => 'Partner Co', 'slug' => 'partner-co', 'invoice_prefix' => 'PTC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
        Auth::login(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
    }

    public function test_a_channel_partner_is_created_on_its_own_page_and_never_appears_as_an_investor(): void
    {
        Livewire::test(CreateChannelPartner::class)
            ->fillForm([
                'name' => 'Rafiq Partner',
                'phone' => '01799999999',
                'nid_number' => '1479 939 603',
                'address' => 'Dhaka',
                'payment_method' => 'bkash',
                'account_number' => '01799999999',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $partner = ChannelPartner::query()->whereNid('1479939603')->firstOrFail();
        $this->assertSame('bkash', $partner->payment_method);
        $this->assertSame(0, Investor::query()->count());

        $investor = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Karim', 'phone' => '01711111111', 'nid_number' => '1901688455', 'channel_partner_id' => $partner->id]);

        Livewire::test(ListInvestors::class)
            ->assertCanSeeTableRecords([$investor])
            ->assertCountTableRecords(1)
            ->filterTable('channel_partner_id', $partner->id)
            ->assertCanSeeTableRecords([$investor]);

        Livewire::test(ListChannelPartners::class)->assertCanSeeTableRecords([$partner]);
        Livewire::test(ViewChannelPartner::class, ['record' => $partner->getRouteKey()])->assertOk();
        Livewire::test(ReferredInvestorsRelationManager::class, ['ownerRecord' => $partner, 'pageClass' => ViewChannelPartner::class])
            ->assertCanSeeTableRecords([$investor]);
    }

    public function test_the_partner_id_is_the_nid_and_cannot_repeat(): void
    {
        ChannelPartner::query()->create(['company_id' => $this->company->id, 'name' => 'First', 'phone' => '01711111111', 'nid_number' => '১৪৭৯৯৩৯৬০৩']);

        Livewire::test(CreateChannelPartner::class)
            ->fillForm(['name' => 'Second', 'phone' => '01722222222', 'nid_number' => '1479-939-603'])
            ->call('create')
            ->assertHasFormErrors(['nid_number']);

        $this->expectException(ValidationException::class);
        ChannelPartner::query()->create(['company_id' => $this->company->id, 'name' => 'Third', 'phone' => '01733333333', 'nid_number' => '1479939603']);
    }

    public function test_partner_contract_and_nid_copies_need_the_manage_permission(): void
    {
        Storage::fake('local');
        $partner = ChannelPartner::query()->create(['company_id' => $this->company->id, 'name' => 'Partner', 'phone' => '01711111111']);
        $copy = $partner->documents()->create([
            'company_id' => $this->company->id,
            'category' => InvestmentDocument::CATEGORY_PARTNER_AGREEMENT,
            'file_path' => app(CompanyStorageService::class)->putPrivate($this->company, 'investment-documents', 'agreement.pdf', 'copy'),
        ]);

        $this->assertTrue($copy->isSensitive());
        $this->assertTrue(DocumentsRelationManager::canViewForRecord($partner, ViewChannelPartner::class));

        $accountant = User::factory()->create(['role' => 'accountant', 'is_active' => true]);
        $accountant->companies()->attach($this->company, ['role' => 'accountant', 'is_default' => true]);
        Auth::login($accountant);

        $this->assertFalse(DocumentsRelationManager::canViewForRecord($partner, ViewChannelPartner::class));
        $this->actingAs($accountant)->withSession(['current_company_id' => $this->company->id])
            ->get(route('investment-documents.download', $copy))
            ->assertForbidden();
    }

    public function test_an_imported_row_names_its_partner_by_partner_id(): void
    {
        $partner = ChannelPartner::query()->create(['company_id' => $this->company->id, 'name' => 'Rafiq', 'phone' => '01799999999', 'nid_number' => '9999999999']);

        app(InvestorImportService::class)->importText("Name: Karim\nPhone: 01711111111\nNID: 1479939603\nChannel partner: ৯৯৯৯৯৯৯৯৯৯");

        $this->assertSame($partner->id, InvestorDraft::query()->firstOrFail()->channel_partner_id);
    }
}
