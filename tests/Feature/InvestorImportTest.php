<?php

namespace Tests\Feature;

use App\Filament\Resources\InvestorDrafts\InvestorDraftResource;
use App\Filament\Resources\InvestorDrafts\Pages\EditInvestorDraft;
use App\Filament\Resources\InvestorDrafts\Pages\ListInvestorDrafts;
use App\Models\Company;
use App\Models\Investor;
use App\Models\InvestorDraft;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Investment\InvestorImportService;
use DateTimeImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class InvestorImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('import-co');
        app(CompanyContext::class)->set($this->company);
        Auth::login(User::factory()->create(['role' => 'super_admin']));
    }

    public function test_a_csv_file_becomes_drafts_not_investors(): void
    {
        $path = $this->tempFile("\xEF\xBB\xBF".implode("\n", [
            'Name,Father\'s Name,Mobile,Date of Birth,NID,Nominee,Relation,Unknown Column',
            'Abdul Karim,Abdul Rahim,+880 1712-345678,01/05/1980,1990123456789,Karima Begum,Wife,x',
            '',
            'Nurul Islam,,1812345678,not a date,,,,',
        ]), 'csv');

        $result = app(InvestorImportService::class)->importFile($path, 'investors.csv');

        $this->assertSame(2, $result['created']);
        $this->assertSame(['Unknown Column'], $result['ignored_columns']);
        $this->assertSame(0, Investor::query()->count());

        $karim = InvestorDraft::query()->where('name', 'Abdul Karim')->sole();
        $this->assertSame($this->company->id, $karim->company_id);
        $this->assertSame('01712345678', $karim->phone);
        $this->assertSame('Abdul Rahim', $karim->guardian_name);
        $this->assertSame('1980-05-01', $karim->date_of_birth->toDateString());
        $this->assertSame('1990123456789', $karim->nid_number);
        $this->assertSame('Karima Begum', $karim->nominee_name);
        $this->assertSame('Wife', $karim->nominee_relation);
        $this->assertSame(InvestorDraft::SOURCE_CSV, $karim->source);
        $this->assertSame(2, $karim->source_row);
        $this->assertSame([], $karim->blockingIssues());

        $nurul = InvestorDraft::query()->where('name', 'Nurul Islam')->sole();
        $this->assertSame('01812345678', $nurul->phone);
        $this->assertNull($nurul->date_of_birth);
        $this->assertCount(1, $nurul->importNoteMessages());
    }

    public function test_an_excel_file_becomes_drafts_and_reads_date_cells(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['নাম', 'মোবাইল', 'জন্ম তারিখ', 'ঠিকানা', 'Channel Partner']));
        $writer->addRow(Row::fromValues(['Rahima Khatun', '01911222333', new DateTimeImmutable('1990-02-03'), 'Mirpur, Dhaka', '01700000000']));
        $writer->close();

        $partner = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Partner', 'phone' => '01700000000', 'is_channel_partner' => true]);

        $result = app(InvestorImportService::class)->importFile($path, 'investors.xlsx');

        $this->assertSame(1, $result['created']);
        $draft = InvestorDraft::query()->sole();
        $this->assertSame('Rahima Khatun', $draft->name);
        $this->assertSame('01911222333', $draft->phone);
        $this->assertSame('1990-02-03', $draft->date_of_birth->toDateString());
        $this->assertSame('Mirpur, Dhaka', $draft->address);
        $this->assertSame($partner->id, $draft->channel_partner_id);
        $this->assertSame(InvestorDraft::SOURCE_EXCEL, $draft->source);
    }

    public function test_pasted_labelled_text_becomes_one_draft_per_block(): void
    {
        $text = implode("\n", [
            'Name: Abdul Karim',
            "Father's name: Abdul Rahim",
            'Phone: ০১৭১২৩৪৫৬৭৮',
            'Address: House 4,',
            'Road 2, Dhaka',
            'Nominee: Karima Begum',
            'Some random note',
            '',
            '১. নাম: Nurul Islam',
            'মোবাইল: 01812345678',
            'Name: Third Person',
            'Phone: 01912345678',
        ]);

        $result = app(InvestorImportService::class)->importText($text);

        $this->assertSame(3, $result['created']);
        $this->assertSame(0, Investor::query()->count());

        $karim = InvestorDraft::query()->where('name', 'Abdul Karim')->sole();
        $this->assertSame('01712345678', $karim->phone);
        $this->assertSame("House 4,\nRoad 2, Dhaka", $karim->address);
        $this->assertSame('Karima Begum', $karim->nominee_name);
        $this->assertSame(InvestorDraft::SOURCE_TEXT, $karim->source);
        $this->assertCount(1, $karim->importNoteMessages());

        $this->assertSame('01812345678', InvestorDraft::query()->where('name', 'Nurul Islam')->value('phone'));
        $this->assertSame('01912345678', InvestorDraft::query()->where('name', 'Third Person')->value('phone'));
    }

    public function test_a_table_pasted_from_excel_is_read_as_rows(): void
    {
        $text = "Name\tPhone\tNID\nAbdul Karim\t01712345678\t1990123456789\nNurul Islam\t01812345678\t";

        $result = app(InvestorImportService::class)->importText($text);

        $this->assertSame(2, $result['created']);
        $this->assertSame('1990123456789', InvestorDraft::query()->where('name', 'Abdul Karim')->sole()->nid_number);
    }

    public function test_text_with_no_investor_details_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(InvestorImportService::class)->importText('hello there');
    }

    public function test_confirming_a_draft_creates_the_investor_and_removes_the_draft(): void
    {
        $draft = $this->draft(['name' => 'Abdul Karim', 'phone' => '01712345678', 'nid_number' => '1990123456789', 'nominee_name' => 'Karima Begum', 'date_of_birth' => '1980-05-01']);

        $investor = app(InvestorImportService::class)->confirm($draft);

        $this->assertSame($this->company->id, $investor->company_id);
        $this->assertSame('Abdul Karim', $investor->name);
        $this->assertSame('1990123456789', $investor->fresh()->nid_number);
        $this->assertSame('Karima Begum', $investor->nominee_name);
        $this->assertSame('1980-05-01', $investor->date_of_birth->toDateString());
        $this->assertSame(0, InvestorDraft::query()->count());
    }

    public function test_a_draft_with_a_missing_name_or_taken_phone_cannot_be_confirmed(): void
    {
        Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Existing', 'phone' => '01712345678']);
        $taken = $this->draft(['name' => 'Duplicate', 'phone' => '01712345678']);
        $nameless = $this->draft(['phone' => '01812345678']);

        $this->assertNotEmpty($taken->blockingIssues());
        $this->assertNotEmpty($nameless->blockingIssues());

        foreach ([$taken, $nameless] as $draft) {
            try {
                app(InvestorImportService::class)->confirm($draft);
                $this->fail('A draft with problems was confirmed.');
            } catch (ValidationException) {
                $this->assertTrue($draft->exists && InvestorDraft::query()->whereKey($draft->id)->exists());
            }
        }

        $this->assertSame(1, Investor::query()->count());
    }

    public function test_pasting_text_on_the_drafts_page_creates_drafts(): void
    {
        Livewire::test(ListInvestorDrafts::class)
            ->callAction('importText', ['text' => "Name: Abdul Karim\nPhone: 01712345678"])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame('01712345678', InvestorDraft::query()->sole()->phone);
        $this->assertSame(0, Investor::query()->count());
    }

    public function test_uploading_a_csv_on_the_drafts_page_creates_drafts(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('investors.csv', "Name,Phone\nAbdul Karim,01712345678\n");

        Livewire::test(ListInvestorDrafts::class)
            ->callAction('importFile', ['file' => $file])
            ->assertHasNoActionErrors();

        $draft = InvestorDraft::query()->sole();
        $this->assertSame('01712345678', $draft->phone);
        $this->assertSame('investors.csv', $draft->source_name);
        $this->assertSame([], Storage::disk('local')->allFiles('imports/investors'));
    }

    public function test_the_confirm_row_action_moves_a_draft_to_the_investor_list(): void
    {
        $draft = $this->draft(['name' => 'Abdul Karim', 'phone' => '01712345678']);

        Livewire::test(ListInvestorDrafts::class)
            ->assertCanSeeTableRecords([$draft])
            ->callAction(TestAction::make('confirm')->table($draft));

        $this->assertSame('Abdul Karim', Investor::query()->sole()->name);
        $this->assertSame(0, InvestorDraft::query()->count());
    }

    public function test_confirm_selected_keeps_drafts_that_still_need_fixing(): void
    {
        $ready = $this->draft(['name' => 'Ready', 'phone' => '01712345678']);
        $broken = $this->draft(['phone' => '01812345678']);

        Livewire::test(ListInvestorDrafts::class)
            ->callTableBulkAction('confirmSelected', [$ready, $broken]);

        $this->assertSame(['Ready'], Investor::query()->pluck('name')->all());
        $this->assertSame([$broken->id], InvestorDraft::query()->pluck('id')->all());
    }

    public function test_reviewing_the_prefilled_form_and_confirming_creates_the_investor(): void
    {
        $draft = $this->draft(['phone' => '01712345678', 'guardian_name' => 'Abdul Rahim', 'import_notes' => [['message' => 'Line :line was not understood and was left out: :text', 'params' => ['line' => 3, 'text' => 'x']]]]);

        Livewire::test(EditInvestorDraft::class, ['record' => $draft->getRouteKey()])
            ->assertSchemaStateSet(['phone' => '01712345678', 'guardian_name' => 'Abdul Rahim'])
            ->call('saveAndConfirm')
            ->assertHasErrors(['data.name']);

        $this->assertSame(0, Investor::query()->count());

        Livewire::test(EditInvestorDraft::class, ['record' => $draft->getRouteKey()])
            ->fillForm(['name' => 'Abdul Karim'])
            ->call('saveAndConfirm')
            ->assertHasNoErrors();

        $investor = Investor::query()->sole();
        $this->assertSame('Abdul Karim', $investor->name);
        $this->assertSame('Abdul Rahim', $investor->guardian_name);
        $this->assertSame(0, InvestorDraft::query()->count());
    }

    public function test_drafts_stay_inside_their_company(): void
    {
        $this->draft(['name' => 'Company A draft', 'phone' => '01712345678']);
        $other = $this->makeCompany('other-co');

        app(CompanyContext::class)->set($other);

        $this->assertSame(0, InvestorDraft::query()->count());
    }

    public function test_users_without_manage_permission_cannot_open_drafts(): void
    {
        Auth::login(User::factory()->create(['role' => 'sales_staff']));

        $this->assertFalse(InvestorDraftResource::canViewAny());
        $this->assertFalse(InvestorDraftResource::canImport());
    }

    private function draft(array $attributes): InvestorDraft
    {
        return InvestorDraft::query()->create([
            'company_id' => $this->company->id,
            'batch_id' => 'test-batch',
            'source' => InvestorDraft::SOURCE_TEXT,
            ...$attributes,
        ]);
    }

    private function makeCompany(string $slug): Company
    {
        return Company::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
    }

    private function tempFile(string $content, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inv').'.'.$extension;
        file_put_contents($path, $content);

        return $path;
    }
}
