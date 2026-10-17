<?php

namespace Tests\Feature;

use App\Filament\Resources\InvestorDrafts\InvestorDraftResource;
use App\Filament\Resources\InvestorDrafts\Pages\EditInvestorDraft;
use App\Filament\Resources\InvestorDrafts\Pages\ListInvestorDrafts;
use App\Models\ChannelPartner;
use App\Models\Company;
use App\Models\Investment;
use App\Models\InvestmentProject;
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

    public function test_the_owners_inv_sheet_layout_imports_without_editing(): void
    {
        // Same layout as the owner's INV sheets: a title line, SL column,
        // Bangla headers with their own spellings, Bangla digits, two numbers
        // in one phone cell, and a totals line at the bottom.
        $path = $this->tempFile(implode("\n", [
            '"Project name (INV 1 - 10/04/2026)",,,,,,,,,,,',
            'SL,ইনভেস্টরদের নাম,পিতার নাম,ঠিকানা,ফোন নাম্বার,NID/Passport,নমিনীর নাম,নমিনী NID/Passport,নমিনীর ফোন নাম্বার,বিনিয়োগ (৳),স্টাম্প নং.,চেক নং.',
            '১,Investor One,Father One,"House 1, Dhaka",০১৯১১১১১১১১,১৪৭৯৯৩৯৬০৩,Nominee One,৪৬৪৭৫৬৩৯৯৬,,"200,000","৯৩৬৩৫১২, ৯৩৬৩৫১৩",BANK/CHQ-0011280',
            '২,Investor Two,Father Two,"House 2, Dhaka","৩৩৭৪৮৬৫৫৬৬৫, ০১৭২২২২২২২২",১৯০১৬৮৮৪৫৫,Nominee Two,EM0162269,"০১৪০১৪০৬৬৪৯, ০১৬০৯৬৬৩০৬৬","1,000,000",৯৩৬৩৫১৫,BANK/CHQ-0011281',
            'মোট ইনভেস্টমেন্ট এমাউন্ট,,,,,,,,,1200000,,',
        ]), 'csv');

        $result = app(InvestorImportService::class)->importFile($path, 'INV_1.csv');

        $this->assertSame(2, $result['created']);
        $this->assertSame([], $result['ignored_columns']);

        $one = InvestorDraft::query()->where('name', 'Investor One')->sole();
        $this->assertSame(3, $one->source_row);
        $this->assertSame('Father One', $one->guardian_name);
        $this->assertSame('01911111111', $one->phone);
        $this->assertSame('1479939603', $one->nid_number);
        $this->assertSame('Nominee One', $one->nominee_name);
        $this->assertSame('4647563996', $one->nominee_nid_or_passport);
        $this->assertSame('9363512, 9363513', $one->stamp_number);
        $this->assertSame('BANK/CHQ-0011280', $one->cheque_number);
        $this->assertSame('200000.00', $one->investment_amount);
        // An amount with no group or project chosen must be resolved before confirming.
        $this->assertSame([__('Pick a group for the investment amount, or clear the amount.')], $one->blockingIssues());

        $two = InvestorDraft::query()->where('name', 'Investor Two')->sole();
        $this->assertSame('01722222222', $two->phone);
        $this->assertSame('01401406649', $two->nominee_phone);
        $this->assertSame('EM0162269', $two->nominee_nid_or_passport);
        $this->assertStringContainsString('33748655665', implode(' ', $two->importNoteMessages()));
        $this->assertStringContainsString('01609663066', implode(' ', $two->importNoteMessages()));
    }

    public function test_every_sheet_of_an_excel_file_is_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('INV 1');
        $writer->addRow(Row::fromValues(['Title of batch one']));
        $writer->addRow(Row::fromValues(['SL', 'ইনভেস্টরদের নাম', 'ফোন নাম্বার']));
        $writer->addRow(Row::fromValues([1, 'Batch One Investor', '01711111111']));
        $writer->addNewSheetAndMakeItCurrent()->setName('INV 2');
        $writer->addRow(Row::fromValues(['SL', 'ইনভেস্টরদের নাম', 'ফোন নাম্বার']));
        $writer->addRow(Row::fromValues([1, 'Batch Two Investor', 1722222222]));
        $writer->addNewSheetAndMakeItCurrent()->setName('Notes');
        $writer->addRow(Row::fromValues(['Just some notes']));
        $writer->close();

        $result = app(InvestorImportService::class)->importFile($path, 'investors.xlsx');

        $this->assertSame(2, $result['created']);
        $this->assertSame('investors.xlsx · INV 1', InvestorDraft::query()->where('name', 'Batch One Investor')->value('source_name'));
        $this->assertSame('01722222222', InvestorDraft::query()->where('name', 'Batch Two Investor')->value('phone'));
    }

    public function test_the_sample_csv_uses_the_owners_layout_and_imports_back(): void
    {
        ob_start();
        app(InvestorImportService::class)->sampleCsv()->sendContent();
        $csv = (string) ob_get_clean();

        $lines = preg_split('/\R/', trim($csv));
        $this->assertSame(config('investor_import.sample.headings'), str_getcsv($lines[1], ',', '"', ''));

        $result = app(InvestorImportService::class)->importFile($this->tempFile($csv, 'csv'), 'sample.csv');

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['ignored_columns']);
    }

    public function test_an_excel_file_becomes_drafts_and_reads_date_cells(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['নাম', 'মোবাইল', 'জন্ম তারিখ', 'ঠিকানা', 'Channel Partner']));
        $writer->addRow(Row::fromValues(['Rahima Khatun', '01911222333', new DateTimeImmutable('1990-02-03'), 'Mirpur, Dhaka', '01700000000']));
        $writer->close();

        $partner = ChannelPartner::query()->create(['company_id' => $this->company->id, 'name' => 'Partner', 'phone' => '01700000000']);

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
        Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Existing', 'phone' => '01712345678', 'nid_number' => '1111111111']);
        $taken = $this->draft(['name' => 'Duplicate', 'phone' => '01799999999', 'nid_number' => '1111 111 111']);
        $nameless = $this->draft(['phone' => '01812345678', 'nid_number' => '2222222222']);

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
        $draft = $this->draft(['name' => 'Abdul Karim', 'phone' => '01712345678', 'nid_number' => '1990123456789']);

        Livewire::test(ListInvestorDrafts::class)
            ->assertCanSeeTableRecords([$draft])
            ->callAction(TestAction::make('confirm')->table($draft));

        $this->assertSame('Abdul Karim', Investor::query()->sole()->name);
        $this->assertSame(0, InvestorDraft::query()->count());
    }

    public function test_confirm_selected_keeps_drafts_that_still_need_fixing(): void
    {
        $ready = $this->draft(['name' => 'Ready', 'phone' => '01712345678', 'nid_number' => '1990123456789']);
        $broken = $this->draft(['phone' => '01812345678']);

        Livewire::test(ListInvestorDrafts::class)
            ->callTableBulkAction('confirmSelected', [$ready, $broken]);

        $this->assertSame(['Ready'], Investor::query()->pluck('name')->all());
        $this->assertSame([$broken->id], InvestorDraft::query()->pluck('id')->all());
    }

    public function test_reviewing_the_prefilled_form_and_confirming_creates_the_investor(): void
    {
        $draft = $this->draft(['phone' => '01712345678', 'nid_number' => '1990123456789', 'guardian_name' => 'Abdul Rahim', 'import_notes' => [['message' => 'Line :line was not understood and was left out: :text', 'params' => ['line' => 3, 'text' => 'x']]]]);

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

    public function test_an_import_with_a_project_turns_sheet_amounts_into_investments_on_confirm(): void
    {
        $project = $this->project();
        $path = $this->tempFile(implode("\n", [
            'SL,ইনভেস্টরদের নাম,ফোন নাম্বার,NID/Passport,বিনিয়োগ (৳),স্টাম্প নং.,চেক নং.',
            '১,Investor One,01911111111,১৪৭৯৯৩৯৬০৩,"200,000","৯৩৬৩৫১২, ৯৩৬৩৫১৩",BANK/CHQ-1',
        ]), 'csv');

        app(InvestorImportService::class)->importFile($path, 'INV_1.csv', ['project_id' => $project->id, 'payment_method' => 'bank', 'invested_at' => '2026-04-10']);

        $draft = InvestorDraft::query()->sole();
        $this->assertSame($project->id, $draft->project_id);
        $this->assertSame('200000.00', $draft->investment_amount);
        $this->assertSame('bank', $draft->payment_method);
        $this->assertSame('2026-04-10', $draft->invested_at->toDateString());
        $this->assertSame([], $draft->blockingIssues());

        $investor = app(InvestorImportService::class)->confirm($draft);

        $investment = Investment::query()->sole();
        $this->assertSame($investor->id, $investment->investor_id);
        $this->assertSame($project->id, $investment->project_id);
        $this->assertSame('200000.00', $investment->amount);
        $this->assertSame('bank', $investment->payment_method);
        $this->assertSame('2026-04-10', $investment->invested_at->toDateString());
        $this->assertSame(Auth::id(), $investment->received_by);

        $instrument = $investment->securityInstruments()->sole();
        $this->assertSame(['9363512', '9363513'], $instrument->stamp_serial_numbers);
        $this->assertSame('BANK/CHQ-1', $instrument->cheque_number);
        $this->assertSame(0, InvestorDraft::query()->count());
    }

    public function test_a_returning_investor_is_linked_and_only_gets_the_new_investment(): void
    {
        $project = $this->project();
        $existing = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Returning Investor', 'phone' => '01911111111', 'nid_number' => '5555555555', 'address' => 'Old address']);

        // Same NID, new phone number: still the same investor (owner's rule).
        app(InvestorImportService::class)->importText(
            "Name,Phone,NID,Address,Investment\nSomeone Else,01922222222,৫৫৫৫৫৫৫৫৫৫,New address,300000",
            investment: ['project_id' => $project->id, 'payment_method' => 'cash', 'invested_at' => now()->toDateString()],
        );

        $draft = InvestorDraft::query()->sole();
        $this->assertSame($existing->id, $draft->existing_investor_id);
        $this->assertSame([], $draft->blockingIssues());
        $this->assertStringContainsString('01922222222', implode(' ', $draft->importNoteMessages()));

        $this->assertSame($existing->id, app(InvestorImportService::class)->confirm($draft)->id);

        $this->assertSame(1, Investor::query()->count());
        $this->assertSame('Returning Investor', $existing->fresh()->name);
        $this->assertSame('Old address', $existing->fresh()->address);
        $this->assertSame('300000.00', Investment::query()->where('investor_id', $existing->id)->sole()->amount);
    }

    public function test_a_linked_draft_without_a_project_or_an_amount_without_a_project_cannot_be_confirmed(): void
    {
        $existing = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Returning Investor', 'phone' => '01911111111', 'nid_number' => '5555555555']);
        $linked = $this->draft(['existing_investor_id' => $existing->id, 'phone' => '01911111111']);
        $amountOnly = $this->draft(['name' => 'New', 'phone' => '01822222222', 'nid_number' => '6666666666', 'investment_amount' => 5000]);
        $closed = $this->draft(['name' => 'Late', 'phone' => '01833333333', 'nid_number' => '7777777777', 'investment_amount' => 5000, 'project_id' => $this->project(['status' => 'closed'])->id, 'payment_method' => 'cash', 'invested_at' => now()->toDateString()]);

        foreach ([$linked, $amountOnly, $closed] as $draft) {
            $this->assertNotEmpty($draft->blockingIssues());

            try {
                app(InvestorImportService::class)->confirm($draft);
                $this->fail('A draft with problems was confirmed.');
            } catch (ValidationException) {
                $this->assertTrue(InvestorDraft::query()->whereKey($draft->id)->exists());
            }
        }

        $this->assertSame(1, Investor::query()->count());
        $this->assertSame(0, Investment::query()->count());
    }

    public function test_set_project_bulk_action_fills_the_investment_details(): void
    {
        $project = $this->project();
        $draft = $this->draft(['name' => 'Investor One', 'phone' => '01911111111', 'nid_number' => '1479939603', 'investment_amount' => 100000]);

        Livewire::test(ListInvestorDrafts::class)
            ->callTableBulkAction('setInvestment', [$draft], data: ['project_id' => $project->id, 'payment_method' => 'bkash', 'invested_at' => '2026-04-10']);

        $draft->refresh();
        $this->assertSame($project->id, $draft->project_id);
        $this->assertSame('bkash', $draft->payment_method);
        $this->assertSame('2026-04-10', $draft->invested_at->toDateString());
        $this->assertSame([], $draft->blockingIssues());
    }

    public function test_the_review_form_can_link_an_existing_investor_and_confirm_the_investment(): void
    {
        $project = $this->project();
        $existing = Investor::query()->create(['company_id' => $this->company->id, 'name' => 'Returning Investor', 'phone' => '01911111111']);
        $draft = $this->draft(['name' => 'Returning Investor', 'phone' => '01700000000', 'investment_amount' => 50000]);

        Livewire::test(EditInvestorDraft::class, ['record' => $draft->getRouteKey()])
            ->fillForm(['existing_investor_id' => $existing->id, 'project_id' => $project->id, 'payment_method' => 'cash', 'invested_at' => '2026-04-10'])
            ->call('saveAndConfirm')
            ->assertHasNoErrors();

        $this->assertSame(1, Investor::query()->count());
        $this->assertSame($existing->id, Investment::query()->sole()->investor_id);
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

    private function project(array $overrides = []): InvestmentProject
    {
        return InvestmentProject::query()->create([
            'company_id' => $this->company->id,
            'project_code' => 'INV-1',
            'name' => 'Shearing machine',
            'duration_type' => 'custom_days',
            'trade_cycle_days' => 60,
            'start_date' => now()->toDateString(),
            'investor_share_percent' => 40,
            'channel_partner_share_percent' => 10,
            'company_share_percent' => 50,
            'status' => 'open',
            ...$overrides,
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
