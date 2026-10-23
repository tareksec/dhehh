<?php

namespace Tests\Feature;

use App\Filament\Trade\Resources\LcFiles\Pages\CreateLcFile;
use App\Filament\Trade\Resources\LcFiles\Pages\EditLcFile;
use App\Filament\Trade\Resources\LcFiles\Pages\ViewLcFile;
use App\Http\Middleware\SetTradePortalLocale;
use App\Models\Company;
use App\Models\LcFile;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\LcFileService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Trade Portal phase 4 (BUILD-BRIEF.md): the LC file and its papers, against the owner's samples (SUMMARY.md). */
class TradeLcFileTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Purchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Tasneem Knitting Industry', 'slug' => 'tasneem-'.uniqid(), 'invoice_prefix' => 'TK'.random_int(1000, 9999),
            'is_active' => true, 'trade_portal_enabled' => true,
            'registered_address' => '24/3, AUCKPARA, ASHULIA, SAVAR, DHAKA, BANGLADESH',
            'factory_address' => 'Chan Nagor, Plot-594, BSCIC 4-No Road, Fatullah, Narayangonj',
            'tin_number' => '626339507948', 'bin_number' => '006673859-0112', 'irc_number' => '260326112298025',
            'signatory_name' => 'Mamunur Rashid', 'signatory_title' => 'Proprietor',
        ]);
        $this->owner = User::factory()->create(['role' => 'trade_owner', 'is_active' => true]);
        $this->owner->companies()->attach($this->company->getKey(), ['role' => 'trade_owner', 'is_default' => true]);

        app(CompanyContext::class)->set($this->company);
        $supplier = Supplier::query()->create(['name' => 'Matill']);
        $machine = Product::query()->create(['name' => 'Used circular knitting machine', 'sku' => 'UCKM-1', 'unit' => 'set', 'sale_price' => 0, 'price' => 0, 'is_active' => true]);
        $this->purchase = Purchase::query()->create(['supplier_id' => $supplier->getKey(), 'purchase_date' => '2026-09-06', 'status' => 'draft']);
        $this->purchase->items()->create(['product_id' => $machine->getKey(), 'quantity' => 8, 'unit_cost' => 0]);
    }

    protected function inPortal(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('trade'));
        app(CompanyContext::class)->set($this->company);
    }

    /** The LC of the samples (documents 2, 4, 5, 6, 9). */
    protected function sampleForm(): array
    {
        return [
            'purchase_id' => $this->purchase->getKey(),
            'pi_number' => 'HM-06092026', 'pi_date' => '2026-09-06',
            'lc_value_usd' => 35600,
            'delivery_terms' => 'CFR CHATTOGRAM SEAPORT BANGLADESH',
            'goods_description' => 'USED CIRCULAR KNITTING MACHINE WITH STANDARD ACCESSORIES & Cylinder Diameter Exceeding 165 mm.',
            'goods_used' => true,
            'country_of_origin' => 'China',
            'supplier_company_name' => 'HONGKONG MATILL TRADING COMPANY LIMITED.',
            'supplier_address' => 'FLAT/RM F 6/F MEGA CUBE NO.8 WANG KWONG ROAD, KOWLOON , HONGKONG',
            'supplier_phone' => '0086-13430352752',
            'lc_number' => '163026010167', 'lc_date' => '2026-09-07',
            'lc_bank' => "NCC Islamic Banking Gulshan Branch,\nDhaka",
            'lc_account_name' => 'Tasneem Knitting Industry.', 'lc_account_number' => '7021010002144',
            'margin_percent' => 30, 'opening_letter_date' => '2026-09-06',
            'bank_branch' => 'NATIONAL CREDIT AND COMMERCE BANK PLC, NCC BANK BHABAN BRANCH(NITS), DHAKA BD BANGLADESH',
            'bank_bin' => '000483356-0202',
            'insurer_name' => 'ISLAMI COMMERCIAL INSURANCE PLC',
            'port_of_loading' => 'FROM ANY SEAPORT OF CHINA', 'port_of_discharge' => 'CHATTOGRAM SEAPORT, BANGLADESH',
            'carrier' => 'MAERSK', 'container_number' => 'MRSU7329355', 'seal_number' => 'ML-CN6255248',
            'total_packages' => '8 SETS WITH ALL STANDARD ACCESSORIES', 'net_weight_kg' => 17280, 'gross_weight_kg' => 19200, 'cbm' => 68,
            'ci_number' => 'TKI-1015', 'ci_date' => '2026-09-23', 'certificate_date' => '2026-09-24',
            'stage' => 'lc_opened', 'doc_status' => 'awaiting_docs',
        ];
    }

    /** The paper's HTML, rendered with the same data the PDF gets. */
    protected function paper(LcFile $file, string $document): string
    {
        $file->load(['purchase.supplier', 'purchase.company', 'purchase.items.product']);

        return view('trade.lc.'.$document, [
            'file' => $file, 'purchase' => $file->purchase, 'supplier' => $file->purchase->supplier, 'company' => $file->purchase->company,
            'letterheadPath' => null, 'signaturePath' => null, 'supplierSignaturePath' => null,
        ])->render();
    }

    public function test_one_form_fills_the_purchase_the_supplier_and_the_lc_file(): void
    {
        $this->inPortal();

        Livewire::test(CreateLcFile::class)->fillForm($this->sampleForm())->call('create')->assertHasNoFormErrors();

        $file = LcFile::query()->firstOrFail();
        $this->purchase->refresh();
        $this->assertSame('163026010167', $this->purchase->lc_number);
        $this->assertSame('HM-06092026', $this->purchase->pi_number);
        $this->assertSame('TKI-1015', $this->purchase->ci_number);
        $this->assertSame('HONGKONG MATILL TRADING COMPANY LIMITED.', $this->purchase->supplier->company_name);
        $this->assertSame(35600.0, $file->lcValue());
        $this->assertSame(10680.0, $file->marginUsd());
        $this->assertSame('lc_opened', $file->stage);

        // The edit form shows the purchase's and the supplier's fields again.
        Livewire::test(EditLcFile::class, ['record' => $file->getKey()])
            ->assertFormSet(['lc_number' => '163026010167', 'pi_date' => '2026-09-06', 'supplier_phone' => '0086-13430352752', 'certificate_date' => '2026-09-24'])
            ->fillForm(['vessel_name' => 'MAERSK HAI PHONG'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('MAERSK HAI PHONG', $file->refresh()->vessel_name);
    }

    public function test_the_lc_letter_and_the_drl_carry_the_samples_wording(): void
    {
        $file = app(LcFileService::class)->save(null, $this->sampleForm());

        $letter = $this->paper($file, 'opening-letter');
        $this->assertStringContainsString('Subject: Request for Opening Letter of Credit (L/C) against of PI-HM-06092026 for USD 35,600 with 30% Margin.', $letter);
        $this->assertStringContainsString('amounting to USD 35,600 (US Dollars Thirty Five Thousand Six Hundred Only) in favor of our supplier HONGKONG MATILL TRADING COMPANY LIMITED. for import purposes with 30% margin.', $letter);
        $this->assertStringContainsString('Account Number: 7021010002144', $letter);
        $this->assertStringContainsString('06.09.2026', $letter);

        $drl = $this->paper($file, 'drl');
        $this->assertStringContainsString('Release of Full Payment and Documents under L/C No. 163026010167', $drl);
        $this->assertStringContainsString('07-September-2026', $drl);
        $this->assertStringContainsString('USD 35600.00', $drl);
    }

    public function test_the_supplier_papers_carry_the_ids_weights_and_clauses(): void
    {
        $file = app(LcFileService::class)->save(null, [...$this->sampleForm(), 'cover_note_number' => 'ICI/BAB/MC-0118/09/2026', 'cover_note_date' => '2026-09-01']);

        $certificate = $this->paper($file, 'beneficiary-certificate');
        $this->assertStringContainsString('TIN NO: 626339507948, BIN NO: 006673859-0112 HAS BEEN INSCRIBED', $certificate);
        $this->assertStringContainsString('E) THE GOODS ARE USED MACHINE', $certificate);
        $this->assertStringContainsString('TOTAL NET WEIGHT: 17280 KGS AND TOTAL GROSS WEIGHT: 19200 KGS', $certificate);
        $this->assertStringContainsString('BIN NO. 000483356-0202 OF NATIONAL CREDIT AND COMMERCE BANK PLC', $certificate);

        $list = $this->paper($file, 'detail-packing-list');
        $this->assertStringContainsString('TIN:626339507948 AND BIN:006673859-0112', $list);
        $this->assertStringContainsString('CI NO: TKI-1015', $list);
        $this->assertStringContainsString('17280', $list);

        $advice = $this->paper($file, 'shipment-advice');
        $this->assertStringContainsString('CONTAINER NO. MRSU7329355 SEAL NO. ML-CN6255248', $advice);
        $this->assertStringContainsString('AND TOTAL CBM 68 CBM', $advice);
        $this->assertStringContainsString('QUOTING INSURANCE COVER NOTE NO: ICI/BAB/MC-0118/09/2026 DTD. 01/09/2026', $advice);
        $this->assertStringContainsString('COPY TO: ISLAMI COMMERCIAL INSURANCE PLC', $advice);
    }

    public function test_a_shipment_advice_without_its_cover_note_is_not_printed(): void
    {
        $file = app(LcFileService::class)->save(null, $this->sampleForm());

        $this->assertSame(['Insurance cover note no. and date'], $file->missingFor('shipment-advice'));
        $this->assertSame([], $file->missingFor('opening-letter'));

        $this->actingAs($this->owner)->withSession([SetTradePortalLocale::SESSION_KEY => 'en'])
            ->get(route('filament.trade.lc-files.document', ['lcFile' => $file, 'document' => 'shipment-advice']))
            ->assertStatus(422)
            ->assertSee('Insurance cover note no. and date');

        $this->actingAs($this->owner)
            ->get(route('filament.trade.lc-files.document', ['lcFile' => $file, 'document' => 'opening-letter']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_every_paper_prints_as_a_pdf_once_complete(): void
    {
        $file = app(LcFileService::class)->save(null, [...$this->sampleForm(), 'cover_note_number' => 'ICI/BAB/MC-0118/09/2026', 'cover_note_date' => '2026-09-01']);
        app(CompanyContext::class)->clear();

        foreach (array_keys(LcFile::DOCUMENTS) as $document) {
            $response = $this->actingAs($this->owner)->get(route('filament.trade.lc-files.document', ['lcFile' => $file, 'document' => $document]));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', (string) $response->getContent(), $document);
        }
    }

    public function test_an_empty_file_lists_what_each_paper_still_needs(): void
    {
        $file = app(LcFileService::class)->save(null, ['purchase_id' => $this->purchase->getKey()]);

        $this->assertContains('LC opening bank', $file->missingFor('opening-letter'));
        $this->assertContains('Margin %', $file->missingFor('opening-letter'));
        $this->assertContains('Commercial Invoice no. and date', $file->missingFor('detail-packing-list'));
        $this->assertSame('pi_received', $file->stage);

        $this->actingAs($this->owner)->withSession([SetTradePortalLocale::SESSION_KEY => 'en'])->get('/trade/lc-files/'.$file->getKey())
            ->assertOk()->assertSee('Still needed:')->assertSee('Not ready');
    }

    public function test_the_stage_moves_forward_and_shows_on_home(): void
    {
        $file = app(LcFileService::class)->save(null, $this->sampleForm());
        $this->inPortal();

        Livewire::test(ViewLcFile::class, ['record' => $file->getKey()])->callAction('nextStage');
        $this->assertSame('under_inspection', $file->refresh()->stage);

        app(CompanyContext::class)->clear();
        $this->actingAs($this->owner)->withSession([SetTradePortalLocale::SESSION_KEY => 'en'])->get('/trade')
            ->assertOk()->assertSee('LC 163026010167')->assertSee('Under inspection');
    }

    public function test_one_lc_file_per_purchase_and_the_owner_role_has_it(): void
    {
        app(LcFileService::class)->save(null, ['purchase_id' => $this->purchase->getKey()]);

        $this->assertThrows(fn () => app(LcFileService::class)->save(null, ['purchase_id' => $this->purchase->getKey()]), ValidationException::class);
        $this->assertContains('trade.lc', UserRole::query()->where('slug', 'trade_owner')->value('permissions'));
        $this->assertTrue($this->owner->isTradePortalOnly());
    }

    public function test_without_the_permission_or_from_another_company_nothing_opens(): void
    {
        $file = app(LcFileService::class)->save(null, $this->sampleForm());
        UserRole::query()->create(['name' => 'Clerk', 'slug' => 'trade_clerk', 'permissions' => ['trade.access', 'trade.sales'], 'is_active' => true]);
        $clerk = User::factory()->create(['role' => 'trade_clerk', 'is_active' => true]);
        $clerk->companies()->attach($this->company->getKey(), ['role' => 'trade_clerk', 'is_default' => true]);

        app(CompanyContext::class)->clear();
        $this->actingAs($clerk)->get('/trade/lc-files')->assertForbidden();
        $this->actingAs($clerk)->get(route('filament.trade.lc-files.document', ['lcFile' => $file, 'document' => 'drl']))->assertForbidden();

        $other = Company::query()->create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'invoice_prefix' => 'OT'.random_int(1000, 9999), 'is_active' => true, 'trade_portal_enabled' => true]);
        $stranger = User::factory()->create(['role' => 'trade_owner', 'is_active' => true]);
        $stranger->companies()->attach($other->getKey(), ['role' => 'trade_owner', 'is_default' => true]);
        app(CompanyContext::class)->clear();

        $this->actingAs($stranger)->get('/trade/lc-files/'.$file->getKey())->assertNotFound();
        $this->actingAs($stranger)->get(route('filament.trade.lc-files.document', ['lcFile' => $file, 'document' => 'drl']))->assertNotFound();
    }
}
