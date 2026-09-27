<?php

namespace Tests\Feature;

use App\Filament\Pages\ExpenseScanSettings;
use App\Filament\Resources\ExpenseScans\Pages\CreateExpenseScan;
use App\Filament\Resources\ExpenseScans\Pages\ReviewExpenseScan;
use App\Jobs\ProcessExpenseScanJob;
use App\Models\Account;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseScan;
use App\Models\TransactionLedger;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CompanyContext;
use App\Services\CompanyStorageService;
use App\Services\ExpenseScan\ExpenseScanConfigService;
use App\Services\ExpenseScan\ExpenseScanPublisher;
use App\Services\ExpenseScan\ExpenseScanReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function company(string $slug = 'scan-co'): Company
    {
        $company = Company::query()->create([
            'name' => 'Scan Co '.$slug,
            'slug' => $slug,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)).random_int(100, 999),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        return $company;
    }

    private function user(Company $company, string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->companies()->syncWithoutDetaching([$company->getKey() => ['role' => $role, 'is_default' => true]]);

        return $user;
    }

    private function configure(Company $company): void
    {
        app(ExpenseScanConfigService::class)->save($company, [
            'enabled' => true,
            'api_format' => 'anthropic',
            'provider' => 'Anthropic',
            'model' => 'claude-opus-5',
            'api_key' => 'sk-ant-test',
        ]);
        $company->refresh();
        app(CompanyContext::class)->set($company);
    }

    private function scanWithImage(Company $company, array $attributes = []): ExpenseScan
    {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $path = app(CompanyStorageService::class)->putPrivate($company, 'expense-scans', 'note.png', $png);

        return ExpenseScan::query()->create([
            'image_paths' => [$path],
            'status' => ExpenseScan::STATUS_PENDING,
            ...$attributes,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $expenses */
    private function fakeClaude(array $expenses): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => ExpenseScanReader::TOOL_NAME, 'input' => ['expenses' => $expenses]],
                ],
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 200],
            ]),
        ]);
    }

    public function test_the_job_reads_the_photo_into_draft_lines_without_touching_the_ledger(): void
    {
        $company = $this->company();
        $this->configure($company);
        $transport = ExpenseCategory::query()->create(['name' => 'Transport', 'slug' => 'transport', 'is_active' => true]);
        $cash = Account::query()->create(['name' => 'Cash', 'opening_balance' => 5000]);
        $bkash = Account::query()->create(['name' => 'bKash', 'opening_balance' => 5000]);
        $scan = $this->scanWithImage($company, ['default_account_id' => $cash->getKey()]);

        $this->fakeClaude([
            ['date' => '২০২৬-০৯-২০', 'description' => 'রিকশা ভাড়া', 'amount' => '১২০', 'category_id' => $transport->getKey(), 'new_category_name' => null, 'account_id' => $bkash->getKey(), 'confidence' => 0.9],
            ['date' => null, 'description' => 'চা নাস্তা', 'amount' => 80, 'category_id' => 99999, 'new_category_name' => 'আপ্যায়ন', 'account_id' => 99999, 'confidence' => 0.4],
            ['date' => '2026-02-30', 'description' => '', 'amount' => 0, 'category_id' => null, 'new_category_name' => null, 'account_id' => null, 'confidence' => 1],
        ]);

        (new ProcessExpenseScanJob($scan->getKey()))->handle(app(CompanyContext::class), app(ExpenseScanReader::class));

        $scan->refresh();
        $this->assertSame(ExpenseScan::STATUS_READY, $scan->status);
        $this->assertSame('claude-opus-5', $scan->model);

        $items = $scan->items()->get();
        $this->assertCount(2, $items, 'A line with neither amount nor description is dropped.');

        $this->assertSame('2026-09-20', $items[0]->expense_date->toDateString());
        $this->assertSame('120.00', (string) $items[0]->amount);
        $this->assertSame($transport->getKey(), $items[0]->expense_category_id);
        $this->assertSame($bkash->getKey(), $items[0]->account_id);

        $this->assertNull($items[1]->expense_date, 'No date on the note stays blank.');
        $this->assertNull($items[1]->expense_category_id, 'An unknown category id is rejected.');
        $this->assertSame('আপ্যায়ন', $items[1]->new_category_name);
        $this->assertSame($cash->getKey(), $items[1]->account_id, 'An unknown account id falls back to the scan default.');

        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(0, TransactionLedger::query()->where('type', 'expense')->count());

        Http::assertSent(function (Request $request): bool {
            $content = $request['messages'][0]['content'];

            return $request->hasHeader('x-api-key', 'sk-ant-test')
                && $request['model'] === 'claude-opus-5'
                && $content[0]['type'] === 'image'
                && $content[0]['source']['media_type'] === 'image/jpeg'
                && $request['tools'][0]['name'] === ExpenseScanReader::TOOL_NAME
                && str_contains($request['system'], 'Transport');
        });
    }

    public function test_the_job_fails_with_a_clear_message_when_the_ai_is_not_configured(): void
    {
        $company = $this->company();
        $scan = $this->scanWithImage($company);
        Http::fake();

        (new ProcessExpenseScanJob($scan->getKey()))->handle(app(CompanyContext::class), app(ExpenseScanReader::class));

        $scan->refresh();
        $this->assertSame(ExpenseScan::STATUS_FAILED, $scan->status);
        $this->assertStringContainsString('not set up', $scan->error_message);
        Http::assertNothingSent();
    }

    public function test_the_job_records_a_provider_error_as_failed(): void
    {
        $company = $this->company();
        $this->configure($company);
        $scan = $this->scanWithImage($company);
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        (new ProcessExpenseScanJob($scan->getKey()))->handle(app(CompanyContext::class), app(ExpenseScanReader::class));

        $this->assertSame(ExpenseScan::STATUS_FAILED, $scan->refresh()->status);
        $this->assertStringContainsString('could not be reached', $scan->error_message);
    }

    public function test_publish_creates_expenses_categories_and_ledger_entries_once(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');
        $cash = Account::query()->create(['name' => 'Cash', 'opening_balance' => 5000]);
        $scan = ExpenseScan::query()->create(['image_paths' => ['x.png'], 'status' => ExpenseScan::STATUS_READY]);
        $scan->items()->create(['company_id' => $company->getKey(), 'expense_date' => '2026-09-20', 'description' => 'চা', 'amount' => 80, 'new_category_name' => 'আপ্যায়ন', 'account_id' => $cash->getKey(), 'sort_order' => 0]);
        $scan->items()->create(['company_id' => $company->getKey(), 'expense_date' => '2026-09-21', 'description' => 'বিস্কুট', 'amount' => 40, 'new_category_name' => 'আপ্যায়ন', 'account_id' => $cash->getKey(), 'sort_order' => 1]);

        $count = app(ExpenseScanPublisher::class)->publish($scan, $admin);

        $this->assertSame(2, $count);
        $this->assertSame(ExpenseScan::STATUS_PUBLISHED, $scan->refresh()->status);
        $this->assertSame($admin->getKey(), $scan->published_by);
        $this->assertSame(1, ExpenseCategory::query()->where('name', 'আপ্যায়ন')->count(), 'One new category, reused by both lines.');

        $expenses = Expense::query()->orderBy('expense_date')->get();
        $this->assertCount(2, $expenses);
        $this->assertSame($scan->getKey(), $expenses[0]->expense_scan_id);
        $this->assertSame('চা', $expenses[0]->note);
        $this->assertSame(2, TransactionLedger::query()->where('type', 'expense')->count());
        $this->assertNotNull($scan->items()->first()->expense_id);

        $this->expectException(ValidationException::class);
        app(ExpenseScanPublisher::class)->publish($scan, $admin);
    }

    public function test_publish_is_all_or_nothing_when_a_line_is_incomplete(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');
        $cash = Account::query()->create(['name' => 'Cash', 'opening_balance' => 5000]);
        $category = ExpenseCategory::query()->create(['name' => 'Food', 'slug' => 'food', 'is_active' => true]);
        $scan = ExpenseScan::query()->create(['image_paths' => ['x.png'], 'status' => ExpenseScan::STATUS_READY]);
        $scan->items()->create(['company_id' => $company->getKey(), 'expense_date' => '2026-09-20', 'amount' => 80, 'expense_category_id' => $category->getKey(), 'account_id' => $cash->getKey()]);
        $scan->items()->create(['company_id' => $company->getKey(), 'expense_date' => null, 'amount' => 40, 'expense_category_id' => $category->getKey(), 'account_id' => $cash->getKey()]);

        try {
            app(ExpenseScanPublisher::class)->publish($scan, $admin);
            $this->fail('Publishing with a missing date should fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Line 2 is missing: date', collect($exception->errors())->flatten()->implode(' '));
        }

        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(ExpenseScan::STATUS_READY, $scan->refresh()->status);
    }

    public function test_publish_rolls_back_when_an_account_would_go_negative(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');
        $cash = Account::query()->create(['name' => 'Cash', 'opening_balance' => 100]);
        $category = ExpenseCategory::query()->create(['name' => 'Food', 'slug' => 'food', 'is_active' => true]);
        $scan = ExpenseScan::query()->create(['image_paths' => ['x.png'], 'status' => ExpenseScan::STATUS_READY]);
        $scan->items()->create(['company_id' => $company->getKey(), 'expense_date' => '2026-09-20', 'amount' => 80, 'expense_category_id' => $category->getKey(), 'account_id' => $cash->getKey(), 'sort_order' => 0]);
        $scan->items()->create(['company_id' => $company->getKey(), 'expense_date' => '2026-09-20', 'amount' => 50, 'expense_category_id' => $category->getKey(), 'account_id' => $cash->getKey(), 'sort_order' => 1]);

        try {
            app(ExpenseScanPublisher::class)->publish($scan, $admin);
            $this->fail('Publishing past the account balance should fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Line 2', collect($exception->errors())->flatten()->implode(' '));
        }

        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(0, TransactionLedger::query()->where('type', 'expense')->count());
    }

    public function test_possible_duplicate_matches_an_existing_expense_with_the_same_date_and_amount(): void
    {
        $company = $this->company();
        $cash = Account::query()->create(['name' => 'Cash', 'opening_balance' => 5000]);
        $category = ExpenseCategory::query()->create(['name' => 'Food', 'slug' => 'food', 'is_active' => true]);
        $existing = Expense::query()->create(['expense_category_id' => $category->getKey(), 'account_id' => $cash->getKey(), 'amount' => 250, 'expense_date' => '2026-09-20']);
        $publisher = app(ExpenseScanPublisher::class);

        $this->assertSame($existing->getKey(), $publisher->possibleDuplicate('2026-09-20', '250', null)?->getKey());
        $this->assertNull($publisher->possibleDuplicate('2026-09-21', '250', null));
        $this->assertNull($publisher->possibleDuplicate(null, '250', null));
    }

    public function test_amount_and_date_parsing_handles_bangla_digits_and_currency_marks(): void
    {
        $this->assertSame(1250.0, ExpenseScanReader::parseAmount('৳১,২৫০/-'));
        $this->assertSame(99.5, ExpenseScanReader::parseAmount('99.50 টাকা'));
        $this->assertNull(ExpenseScanReader::parseAmount('অস্পষ্ট'));
        $this->assertNull(ExpenseScanReader::parseAmount(-5));
        $this->assertSame('2026-09-05', ExpenseScanReader::parseDate('২০২৬-০৯-০৫'));
        $this->assertNull(ExpenseScanReader::parseDate('2026-02-30'));
        $this->assertNull(ExpenseScanReader::parseDate('05/09/2026'));
    }

    public function test_only_users_with_the_ai_scan_permission_can_open_the_pages(): void
    {
        $company = $this->company();
        $scanRole = UserRole::query()->create([
            'name' => 'Expense Scanner',
            'slug' => 'expense_scanner',
            'permissions' => ['expenses.ai_scan'],
            'is_active' => true,
        ]);

        foreach (['super_admin' => 200, $scanRole->slug => 200, 'manager' => 403, 'accountant' => 403] as $role => $status) {
            $this->actingAs($this->user($company, $role))
                ->withSession(['current_company_id' => $company->getKey()])
                ->get('/admin/finance/expense-scans')
                ->assertStatus($status);
        }
    }

    public function test_upload_stores_the_photo_privately_and_queues_the_read(): void
    {
        Queue::fake();
        $company = $this->company();
        $this->configure($company);
        $admin = $this->user($company, 'super_admin');

        Livewire::actingAs($admin)
            ->test(CreateExpenseScan::class)
            ->fillForm(['image_paths' => [UploadedFile::fake()->image('note.jpg', 800, 600)]])
            ->call('create')
            ->assertHasNoFormErrors();

        $scan = ExpenseScan::query()->sole();
        $this->assertSame(ExpenseScan::STATUS_PENDING, $scan->status);
        $this->assertSame($admin->getKey(), $scan->user_id);
        $this->assertCount(1, $scan->imagePaths());
        $this->assertStringStartsWith($company->storageRoot().'/private/expense-scans/', $scan->imagePaths()[0]);
        Storage::disk('local')->assertExists($scan->imagePaths()[0]);
        Queue::assertPushed(ProcessExpenseScanJob::class, fn (ProcessExpenseScanJob $job): bool => $job->expenseScanId === $scan->getKey());
    }

    public function test_pasted_text_alone_creates_a_scan_and_is_sent_to_the_ai(): void
    {
        Queue::fake();
        $company = $this->company();
        $this->configure($company);
        $admin = $this->user($company, 'super_admin');
        $cash = Account::query()->create(['name' => 'Cash', 'opening_balance' => 5000]);
        $pasted = "২০/০৯/২০২৬\nরিকশা ভাড়া ১২০\nচা নাস্তা ৮০";

        Livewire::actingAs($admin)
            ->test(CreateExpenseScan::class)
            ->fillForm(['source_text' => $pasted, 'default_account_id' => $cash->getKey()])
            ->call('create')
            ->assertHasNoFormErrors();

        $scan = ExpenseScan::query()->sole();
        $this->assertSame([], $scan->imagePaths());
        $this->assertSame($pasted, $scan->source_text);
        Queue::assertPushed(ProcessExpenseScanJob::class);

        $this->fakeClaude([
            ['date' => '2026-09-20', 'description' => 'রিকশা ভাড়া', 'amount' => 120, 'category_id' => null, 'new_category_name' => 'যাতায়াত', 'account_id' => null, 'confidence' => 0.95],
            ['date' => '2026-09-20', 'description' => 'চা নাস্তা', 'amount' => 80, 'category_id' => null, 'new_category_name' => 'আপ্যায়ন', 'account_id' => null, 'confidence' => 0.95],
        ]);

        (new ProcessExpenseScanJob($scan->getKey()))->handle(app(CompanyContext::class), app(ExpenseScanReader::class));

        $this->assertSame(ExpenseScan::STATUS_READY, $scan->refresh()->status);
        $this->assertCount(2, $scan->items()->get());
        Http::assertSent(function (Request $request) use ($pasted): bool {
            $content = $request['messages'][0]['content'];

            return $content[0]['type'] === 'text'
                && str_contains($content[0]['text'], $pasted)
                && collect($content)->doesntContain('type', 'image');
        });
    }

    public function test_upload_needs_a_photo_or_pasted_text(): void
    {
        Queue::fake();
        $company = $this->company();
        $this->configure($company);

        Livewire::actingAs($this->user($company, 'super_admin'))
            ->test(CreateExpenseScan::class)
            ->fillForm(['source_text' => '', 'image_paths' => []])
            ->call('create')
            ->assertHasFormErrors(['image_paths' => 'required', 'source_text' => 'required']);

        $this->assertSame(0, ExpenseScan::query()->count());
    }

    public function test_upload_is_blocked_until_the_ai_is_configured(): void
    {
        Queue::fake();
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');

        Livewire::actingAs($admin)
            ->test(CreateExpenseScan::class)
            ->fillForm(['image_paths' => [UploadedFile::fake()->image('note.jpg')]])
            ->call('create')
            ->assertNotified('Smart Scan is not set up yet');

        $this->assertSame(0, ExpenseScan::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_review_page_saves_corrections_and_publishes(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');
        $cash = Account::query()->create(['name' => 'Cash', 'opening_balance' => 5000]);
        $category = ExpenseCategory::query()->create(['name' => 'Food', 'slug' => 'food', 'is_active' => true]);
        $scan = ExpenseScan::query()->create(['image_paths' => ['x.png'], 'status' => ExpenseScan::STATUS_READY]);
        $scan->items()->create(['company_id' => $company->getKey(), 'expense_date' => null, 'description' => 'Lunch', 'amount' => 300, 'expense_category_id' => $category->getKey(), 'account_id' => $cash->getKey()]);

        $page = Livewire::actingAs($admin)->test(ReviewExpenseScan::class, ['record' => $scan->getKey()]);
        $key = array_key_first($page->get('data.items'));

        $page->set("data.items.{$key}.expense_date", '2026-09-22')
            ->set("data.items.{$key}.amount", '350')
            ->callAction('publish')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $expense = Expense::query()->sole();
        $this->assertSame('2026-09-22', $expense->expense_date->toDateString());
        $this->assertSame('350.00', (string) $expense->amount);
        $this->assertSame(ExpenseScan::STATUS_PUBLISHED, $scan->refresh()->status);
    }

    public function test_review_page_can_add_a_line_by_hand(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');
        $scan = ExpenseScan::query()->create(['image_paths' => ['x.png'], 'status' => ExpenseScan::STATUS_READY]);

        Livewire::actingAs($admin)
            ->test(ReviewExpenseScan::class, ['record' => $scan->getKey()])
            ->set('data.items', ['new-line' => ['expense_date' => '2026-09-23', 'description' => 'Courier', 'amount' => '150', 'expense_category_id' => null, 'new_category_name' => 'Delivery', 'account_id' => null, 'reference' => null, 'confidence' => null]])
            ->call('save')
            ->assertHasNoFormErrors();

        $item = $scan->items()->sole();
        $this->assertSame($company->getKey(), $item->company_id);
        $this->assertSame('Delivery', $item->new_category_name);
        $this->assertSame('150.00', (string) $item->amount);
    }

    public function test_review_page_polls_while_the_ai_is_reading_and_fills_lines_when_done(): void
    {
        $company = $this->company();
        $admin = $this->user($company, 'super_admin');
        $scan = ExpenseScan::query()->create(['image_paths' => ['x.png'], 'status' => ExpenseScan::STATUS_PROCESSING]);

        $page = Livewire::actingAs($admin)
            ->test(ReviewExpenseScan::class, ['record' => $scan->getKey()])
            ->assertSee(__('The AI is reading your expenses — the lines below will appear in a moment.'));

        $scan->items()->create(['company_id' => $company->getKey(), 'description' => 'Tea', 'amount' => 20]);
        $scan->forceFill(['status' => ExpenseScan::STATUS_READY])->save();

        $page->call('refreshScan')->assertRedirect(ReviewExpenseScan::getUrl(['record' => $scan]));

        Livewire::actingAs($admin)
            ->test(ReviewExpenseScan::class, ['record' => $scan->getKey()])
            ->assertDontSee(__('The AI is reading your expenses — the lines below will appear in a moment.'))
            ->assertSee('Tea');
    }

    public function test_scan_photos_are_served_only_to_permitted_users_of_the_same_company(): void
    {
        $company = $this->company();
        $scan = $this->scanWithImage($company);
        $url = route('expense-scans.image', ['scan' => $scan->getKey(), 'index' => 0]);

        $this->actingAs($this->user($company, 'super_admin'))
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($url)
            ->assertOk();

        $this->actingAs($this->user($company, 'sales_staff'))
            ->withSession(['current_company_id' => $company->getKey()])
            ->get($url)
            ->assertForbidden();

        $other = $this->company('other-co');
        $this->actingAs($this->user($other, 'manager'))
            ->withSession(['current_company_id' => $other->getKey()])
            ->get($url)
            ->assertNotFound();
    }

    public function test_settings_page_saves_the_key_encrypted_and_is_super_admin_only(): void
    {
        $company = $this->company();

        $this->actingAs($this->user($company, 'manager'))
            ->withSession(['current_company_id' => $company->getKey()])
            ->get('/admin/settings/expense-scan-settings')
            ->assertForbidden();

        Livewire::actingAs($this->user($company, 'super_admin'))
            ->test(ExpenseScanSettings::class)
            ->fillForm([
                'enabled' => true,
                'api_format' => 'openai',
                'provider' => 'OpenAI',
                'model' => 'gpt-vision-test',
                'api_key' => 'sk-secret',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $config = app(ExpenseScanConfigService::class)->all($company->fresh());
        $this->assertTrue($config['enabled']);
        $this->assertSame('gpt-vision-test', $config['model']);
        $this->assertSame('sk-secret', Crypt::decryptString(data_get($company->fresh()->settings, 'ai_tools.expense_scan.api_key')));
    }
}
