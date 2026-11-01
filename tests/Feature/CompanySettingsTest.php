<?php

namespace Tests\Feature;

use App\Filament\Clusters\CompanyManagement;
use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\CompanyDeletionService;
use App\Services\CompanySettingsService;
use App\Services\CompanyStorageService;
use App\Services\StorageSettingsService;
use Filament\Pages\Enums\SubNavigationPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_company_can_be_deleted_without_being_recreated(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $mainCompany = $admin->defaultCompany();
        $replacement = Company::query()->create([
            'name' => 'Live Company',
            'slug' => 'live-company',
            'invoice_prefix' => 'LIVE',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $this->actingAs($admin);

        Livewire::test(EditCompany::class, ['record' => $mainCompany->getKey()])
            ->assertActionExists('delete')
            ->callAction('delete', data: ['confirmation' => $mainCompany->name]);

        $this->assertDatabaseMissing('companies', ['id' => $mainCompany->getKey()]);
        $this->assertSame($replacement->getKey(), Company::defaultCompany()?->getKey());
        $this->assertDatabaseMissing('companies', ['slug' => 'main-company']);
    }

    public function test_company_deletion_is_blocked_while_business_records_remain(): void
    {
        $company = Company::query()->create([
            'name' => 'Company With Data',
            'slug' => 'company-with-data',
            'invoice_prefix' => 'CWD',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        Product::query()->create([
            'company_id' => $company->getKey(),
            'name' => 'Protected Product',
            'sku' => 'PROTECTED-001',
            'price' => 100,
            'cost_price' => 50,
            'stock' => 1,
            'unit' => 'pcs',
            'reorder_level' => 0,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $this->assertFalse(app(CompanyDeletionService::class)->delete($company));
        $this->assertDatabaseHas('companies', ['id' => $company->getKey()]);
        $this->assertDatabaseCount('accounts', 6);
    }

    public function test_company_management_uses_a_top_cluster_page_selector(): void
    {
        $this->assertSame(CompanyManagement::class, CompanyResource::getCluster());
        $this->assertSame(CompanyManagement::class, CompanySettings::getCluster());
        $this->assertSame(SubNavigationPosition::Top, CompanyManagement::getSubNavigationPosition());
    }

    public function test_company_create_form_excludes_storefront_domain_controls_and_saves_from_the_header(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($admin->defaultCompany());
        $this->actingAs($admin);

        $component = Livewire::test(CreateCompany::class)
            ->assertFormFieldDoesNotExist('domain')
            ->assertFormFieldDoesNotExist('domain_verified');

        $headerActions = collect($component->instance()->getCachedHeaderActions());

        $this->assertSame(['saveChanges'], $headerActions->map->getName()->all());
        $this->assertSame('Save changes', $headerActions->first()->getLabel());
        $this->assertSame(['mod+s'], $headerActions->first()->getKeyBindings());
        $this->assertTrue($headerActions->first()->canSubmitForm());
        $this->assertSame('create', $headerActions->first()->getFormToSubmit());
        $this->assertSame('form', $headerActions->first()->getFormId());

        $component
            ->fillForm([
                'name' => 'Header Action Company',
                'slug' => 'header-action-company',
                'business_type' => 'Retail',
                'invoice_prefix' => 'HAC',
                'dashboard_color' => '#F59E0B',
                'currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::query()->where('slug', 'header-action-company')->firstOrFail();

        $this->assertNull($company->domain);
        $this->assertFalse($company->domain_verified);
    }

    public function test_company_edit_form_excludes_storefront_domain_controls_and_preserves_domain_state(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $company = Company::query()->create([
            'name' => 'Verified Domain Company',
            'slug' => 'verified-domain-company',
            'business_type' => 'Retail',
            'domain' => 'verified.example.test',
            'domain_verified' => true,
            'invoice_prefix' => 'VDC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($admin->defaultCompany());
        $this->actingAs($admin);

        $component = Livewire::test(EditCompany::class, ['record' => $company->getKey()])
            ->assertFormFieldDoesNotExist('domain')
            ->assertFormFieldDoesNotExist('domain_verified');

        $headerActions = collect($component->instance()->getCachedHeaderActions());

        $this->assertSame(['view', 'delete', 'saveChanges'], $headerActions->map->getName()->all());
        $this->assertSame('Save changes', $headerActions->last()->getLabel());
        $this->assertSame(['mod+s'], $headerActions->last()->getKeyBindings());
        $this->assertTrue($headerActions->last()->canSubmitForm());
        $this->assertSame('save', $headerActions->last()->getFormToSubmit());
        $this->assertSame('form', $headerActions->last()->getFormId());

        $component
            ->set('data.business_type', 'Updated Retail')
            ->call('save')
            ->assertHasNoFormErrors();

        $company->refresh();

        $this->assertSame('Updated Retail', $company->business_type);
        $this->assertSame('verified.example.test', $company->domain);
        $this->assertTrue($company->domain_verified);
    }

    public function test_company_settings_service_saves_profile(): void
    {
        $settings = app(CompanySettingsService::class);

        $settings->save([
            'name' => 'ZamZam Trading',
            'address' => 'Dhaka, Bangladesh',
            'phone' => '+8801700000000',
            'email' => 'accounts@example.com',
            'currency' => 'USD',
            'timezone' => 'Asia/Dhaka',
            'date_format' => 'Y-m-d',
        ]);

        $profile = $settings->profile();

        $this->assertSame('ZamZam Trading', $profile['name']);
        $this->assertSame('Dhaka, Bangladesh', $profile['address']);
        $this->assertSame('USD', $profile['currency']);
        $this->assertSame('Asia/Dhaka', $profile['timezone']);
        $this->assertSame('Y-m-d', $profile['date_format']);
    }

    public function test_company_settings_page_requires_settings_permission(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $salesStaff = User::factory()->create([
            'role' => 'sales_staff',
            'is_active' => true,
        ]);

        $this->actingAs($salesStaff)
            ->get('/admin/company-management/company-settings')
            ->assertForbidden();

        $this->actingAs($admin)
            ->withSession(['current_company_id' => $admin->defaultCompany()->getKey()])
            ->get('/admin/company-management/company-settings')
            ->assertOk()
            ->assertSee('Business Profile')
            ->assertSee('Branding')
            ->assertSee('Invoice Settings')
            ->assertSee('View companies')
            ->assertSee('Save changes')
            ->assertSee('.fi-page-header-main-ctn > .fi-header', escape: false)
            ->assertSee('position: sticky;', escape: false);

        $this->actingAs($admin)
            ->withSession(['current_company_id' => 'all', 'current_company_selection_explicit' => true])
            ->get('/admin/company-management/company-settings')
            ->assertOk()
            ->assertSee('Select a company to edit settings')
            ->assertSee('top-bar company switcher')
            ->assertSee('View companies')
            ->assertDontSee('Save changes');
    }

    public function test_company_settings_uses_the_sticky_page_header_for_its_actions(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $company = $admin->defaultCompany();
        app(CompanyContext::class)->set($company);
        $this->actingAs($admin);

        $component = Livewire::test(CompanySettings::class);
        $headerActions = collect($component->instance()->getCachedHeaderActions());
        $saveAction = $headerActions->firstWhere(fn ($action): bool => $action->getName() === 'saveChanges');

        $this->assertSame(['viewCompanies', 'saveChanges'], $headerActions->map->getName()->all());
        $this->assertNotNull($saveAction);
        $this->assertSame('Save changes', $saveAction->getLabel());
        $this->assertSame(['mod+s'], $saveAction->getKeyBindings());
        $this->assertTrue($saveAction->canSubmitForm());
        $this->assertSame('save', $saveAction->getFormToSubmit());
        $this->assertSame('company-settings-form', $saveAction->getFormId());
        $this->assertTrue($saveAction->isVisible());

        app(CompanyContext::class)->all();

        $allCompaniesComponent = Livewire::test(CompanySettings::class);
        $allCompaniesSaveAction = collect($allCompaniesComponent->instance()->getCachedHeaderActions())
            ->firstWhere(fn ($action): bool => $action->getName() === 'saveChanges');

        $this->assertNotNull($allCompaniesSaveAction);
        $this->assertFalse($allCompaniesSaveAction->isVisible());
    }

    public function test_company_management_cluster_routes_to_companies_and_shows_both_pages(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $company = $admin->defaultCompany();

        $this->actingAs($admin)
            ->withSession(['current_company_id' => $company->getKey()])
            ->get('/admin/company-management')
            ->assertRedirect(route('filament.admin.company-management.resources.companies.index'));

        $this->actingAs($admin)
            ->withSession(['current_company_id' => $company->getKey()])
            ->get('/admin/company-management/companies')
            ->assertOk()
            ->assertSee('Companies')
            ->assertSee('Company Settings');

        $this->actingAs($admin)
            ->withSession(['current_company_id' => 'all', 'current_company_selection_explicit' => true])
            ->get('/admin/company-management/companies')
            ->assertOk()
            ->assertSee('Companies')
            ->assertSee('Company Settings');

        $this->actingAs($admin)
            ->get('/admin/companies')
            ->assertRedirect('/admin/company-management/companies');

        $this->actingAs($admin)
            ->get('/admin/company-settings')
            ->assertRedirect('/admin/company-management/company-settings');
    }

    public function test_companies_list_follows_the_header_company_selection(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $selectedCompany = $admin->defaultCompany();
        $otherCompany = Company::query()->create([
            'name' => 'Other Listed Company',
            'slug' => 'other-listed-company',
            'invoice_prefix' => 'OLC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        $this->actingAs($admin);
        app(CompanyContext::class)->set($selectedCompany);

        Livewire::test(ListCompanies::class)
            ->assertCanSeeTableRecords([$selectedCompany])
            ->assertCanNotSeeTableRecords([$otherCompany])
            ->assertCountTableRecords(1);

        app(CompanyContext::class)->all();

        Livewire::test(ListCompanies::class)
            ->assertCanSeeTableRecords([$selectedCompany, $otherCompany])
            ->assertCountTableRecords(2);
    }

    public function test_company_settings_all_companies_mode_cannot_save(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $company = $admin->defaultCompany();
        $originalName = $company->name;

        app(CompanyContext::class)->all();
        $this->actingAs($admin);

        Livewire::test(CompanySettings::class)
            ->assertSet('companyId', null)
            ->set('data.name', 'Must Not Be Saved')
            ->call('save')
            ->assertStatus(404);

        $this->assertSame($originalName, $company->fresh()->name);
    }

    public function test_admin_panel_uses_company_name_as_brand(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $company = $admin->defaultCompany();

        app(CompanySettingsService::class)->save([
            'name' => 'ZamZam ERP',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'date_format' => 'd M Y',
        ], $company);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('ZamZam ERP')
            ->assertDontSee('>Laravel<', false);
    }

    public function test_admin_panel_uses_light_and_dark_company_logos(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $company = $admin->defaultCompany();
        $storage = app(CompanyStorageService::class);
        $lightLogo = $storage->putPublic($company, 'company', 'light-logo.png', 'light-logo');
        $darkLogo = $storage->putPublic($company, 'company', 'dark-logo.png', 'dark-logo');

        app(CompanySettingsService::class)->save([
            'name' => 'ZamZam ERP',
            'logo' => $lightLogo,
            'dark_logo' => $darkLogo,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'date_format' => 'd M Y',
        ], $company);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($lightLogo))
            ->assertSee(Storage::disk('public')->url($darkLogo));
    }

    public function test_admin_login_uses_the_active_default_company_logos_without_authentication(): void
    {
        Storage::fake('public');

        $company = Company::defaultCompany();
        $storage = app(CompanyStorageService::class);
        $lightLogo = $storage->putPublic($company, 'company', 'login-light-logo.png', 'login-light-logo');
        $darkLogo = $storage->putPublic($company, 'company', 'login-dark-logo.png', 'login-dark-logo');

        app(CompanySettingsService::class)->save([
            'name' => 'Login Brand Company',
            'logo' => $lightLogo,
            'dark_logo' => $darkLogo,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'date_format' => 'd M Y',
        ], $company);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Login Brand Company')
            ->assertSee(Storage::disk('public')->url($lightLogo))
            ->assertSee(Storage::disk('public')->url($darkLogo));
    }

    public function test_invoice_print_uses_company_settings(): void
    {
        $company = Company::defaultCompany();
        app(CompanySettingsService::class)->save([
            'name' => 'ZamZam Trading',
            'address' => 'Dhaka, Bangladesh',
            'phone' => '+8801700000000',
            'email' => 'accounts@example.com',
            'currency' => 'USD',
            'timezone' => 'Asia/Dhaka',
            'date_format' => 'Y-m-d',
        ], $company);

        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Invoice Customer']);
        $product = Product::query()->create([
            'name' => 'Invoice Product',
            'sku' => 'INV-BRAND-001',
            'price' => 100,
            'sale_price' => 100,
            'stock' => 10,
        ]);
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'order_date' => '2026-06-14',
            'status' => 'draft',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        $this->actingAs($user)
            ->get(route('orders.print', $order))
            ->assertOk()
            ->assertSee('ZamZam Trading')
            ->assertSee('Dhaka, Bangladesh')
            ->assertSee('Unit Price (USD)')
            ->assertSee('100')
            ->assertDontSee('Discount')
            ->assertDontSee('VAT')
            ->assertDontSee('>Paid<', false)
            ->assertSee('invoice-print-button')
            ->assertSee('window.print()');
    }

    public function test_invoice_print_hides_zero_adjustments_and_shows_paid_as_negative_when_present(): void
    {
        $company = Company::defaultCompany();
        app(CompanySettingsService::class)->save([
            'name' => 'ZamZam Trading',
            'currency' => 'USD',
            'timezone' => 'Asia/Dhaka',
            'date_format' => 'Y-m-d',
        ], $company);

        $user = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Invoice Customer']);
        $product = Product::query()->create([
            'name' => 'Invoice Product',
            'sku' => 'INV-PAID-001',
            'price' => 100,
            'sale_price' => 100,
            'stock' => 10,
        ]);
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'order_date' => '2026-06-14',
            'status' => 'draft',
            'discount' => 10,
            'vat' => 5,
            'paid_amount' => 20,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        $this->actingAs($user)
            ->get(route('orders.print', $order))
            ->assertOk()
            ->assertSee('Discount')
            ->assertSee('VAT')
            ->assertSee('<td class="t-label">Paid</td>', false)
            ->assertSee('-20')
            ->assertSee('75');
    }

    public function test_company_settings_livewire_save_updates_profile(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $company = $admin->defaultCompany();
        app(CompanyContext::class)->set($company);

        $this->actingAs($admin);

        Livewire::test(CompanySettings::class)
            ->fillForm([
                'name' => 'Livewire Trading',
                'address' => 'Chattogram, Bangladesh',
                'phone' => '+8801800000000',
                'email' => 'info@example.com',
                'currency' => 'BDT',
                'timezone' => 'Asia/Dhaka',
                'date_format' => 'Y-m-d',
                'invoice_prefix' => 'LWT',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $profile = app(CompanySettingsService::class)->profile($company->fresh());

        $this->assertSame('Livewire Trading', $profile['name']);
        $this->assertSame('Chattogram, Bangladesh', $profile['address']);
        $this->assertSame('BDT', $profile['currency']);
        $this->assertSame('Y-m-d', $profile['date_format']);
        $this->assertSame('LWT', $profile['invoice_prefix']);
    }

    public function test_company_settings_page_saves_nested_invoice_values_only_for_the_mounted_company(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $first = $admin->defaultCompany();
        $second = Company::query()->create([
            'name' => 'Second Invoice Company',
            'slug' => 'second-invoice-company',
            'invoice_prefix' => 'SIC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $settings = app(CompanySettingsService::class);
        $settings->saveInvoice(['hotline' => '01800000000'], $second);
        app(CompanyContext::class)->set($first);
        $this->actingAs($admin);

        Livewire::test(CompanySettings::class)
            ->assertSet('companyId', $first->getKey())
            ->set('data.invoice_prefix', 'FIRST')
            ->set('data.invoice.hotline', '01700000000')
            ->set('data.invoice.thank_you', 'First company thanks')
            ->set('data.invoice.show_weight', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('FIRST', $first->fresh()->invoice_prefix);
        $this->assertSame('01700000000', $settings->invoice($first->fresh())['hotline']);
        $this->assertSame('First company thanks', $settings->invoice($first->fresh())['thank_you']);
        $this->assertFalse($settings->invoice($first->fresh())['show_weight']);
        $this->assertSame('SIC', $second->fresh()->invoice_prefix);
        $this->assertSame('01800000000', $settings->invoice($second->fresh())['hotline']);

        app(CompanyContext::class)->set($second->fresh());

        Livewire::test(CompanySettings::class)
            ->set('data.invoice_prefix', 'FIRST')
            ->set('data.invoice.hotline', '01900000000')
            ->call('save')
            ->assertHasFormErrors(['invoice_prefix' => 'unique']);

        $this->assertSame('SIC', $second->fresh()->invoice_prefix);
        $this->assertSame('01800000000', $settings->invoice($second->fresh())['hotline']);
    }

    public function test_company_settings_rejects_a_company_context_change_after_mount(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $first = $admin->defaultCompany();
        $second = Company::query()->create([
            'name' => 'Context Switch Company',
            'slug' => 'context-switch-company',
            'invoice_prefix' => 'CSC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($first);
        $this->actingAs($admin);

        $page = Livewire::test(CompanySettings::class)
            ->assertSet('companyId', $first->getKey())
            ->set('data.name', 'Stale Company Name');

        app(CompanyContext::class)->set($second);

        $page->call('save')->assertStatus(409);

        $this->assertNotSame('Stale Company Name', $first->fresh()->name);
        $this->assertSame('Context Switch Company', $second->fresh()->name);
    }

    public function test_company_settings_service_rejects_a_duplicate_invoice_prefix(): void
    {
        $first = Company::defaultCompany();
        $second = Company::query()->create([
            'name' => 'Unique Prefix Company',
            'slug' => 'unique-prefix-company',
            'invoice_prefix' => 'UPC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(CompanySettingsService::class)->save([
            'name' => $second->name,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'invoice_prefix' => $first->invoice_prefix,
        ], $second);
    }

    public function test_company_creation_defers_logo_upload_until_the_company_storage_root_exists(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($admin->defaultCompany());
        $this->actingAs($admin);

        Livewire::test(CreateCompany::class)
            ->assertFormFieldDisabled('logo');
    }

    public function test_superadmin_can_edit_and_reactivate_an_inactive_company(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $company = Company::query()->create([
            'name' => 'Inactive Company',
            'slug' => 'inactive-company',
            'invoice_prefix' => 'INA',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => false,
        ]);
        $this->actingAs($admin);

        $this->assertTrue(CompanyResource::canView($company));
        $this->assertTrue(CompanyResource::canEdit($company));

        Livewire::test(EditCompany::class, ['record' => $company->getKey()])
            ->set('data.is_active', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($company->fresh()->is_active);
    }

    public function test_company_settings_livewire_save_validates_email(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($admin->defaultCompany());

        $this->actingAs($admin);

        Livewire::test(CompanySettings::class)
            ->fillForm([
                'name' => 'Invalid Email Company',
                'email' => 'not-an-email',
            ])
            ->call('save')
            ->assertHasFormErrors(['email']);
    }

    public function test_company_settings_livewire_uploads_logo(): void
    {
        Storage::fake('public');
        $storageSettings = app(StorageSettingsService::class);
        $r2Settings = [
            'enabled' => false,
            'access_key_id' => 'test-access-key',
            'secret_access_key' => 'test-secret-key',
            'public_bucket' => 'test-public-bucket',
            'endpoint' => 'https://example.r2.cloudflarestorage.com',
            'public_url' => 'https://cdn.example.test',
        ];
        $storageSettings->save($r2Settings);
        AppSetting::setValue(StorageSettingsService::PUBLIC_TOPOLOGY_LOCKED, '1');
        $storageSettings->forgetCachedSettings();
        $storageSettings->save([...$r2Settings, 'enabled' => true]);
        Storage::fake('r2_public');

        $company = Company::query()->create([
            'name' => 'Logo Company',
            'slug' => 'logo-company',
            'invoice_prefix' => 'LOG',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        app(CompanyContext::class)->set($company);

        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        Livewire::test(CompanySettings::class)
            ->fillForm([
                'name' => 'Logo Company',
                'logo' => UploadedFile::fake()->image('logo.png'),
                'dark_logo' => UploadedFile::fake()->image('dark-logo.png'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $profile = app(CompanySettingsService::class)->profile($company->fresh());

        $this->assertNotEmpty($profile['logo']);
        $this->assertNotEmpty($profile['dark_logo']);
        $this->assertNotSame($profile['dark_logo'], AppSetting::getValue('company.dark_logo'));
        $this->assertStringStartsWith($company->storageRoot().'/public/company/', $profile['logo']);
        $this->assertStringStartsWith($company->storageRoot().'/public/company/', $profile['dark_logo']);

        $disk = app(CompanyStorageService::class)->publicDiskName();
        $this->assertSame('r2_public', $disk);
        Storage::disk($disk)->assertExists($profile['logo']);
        Storage::disk($disk)->assertExists($profile['dark_logo']);
        Storage::disk('public')->assertMissing($profile['logo']);
        Storage::disk('public')->assertMissing($profile['dark_logo']);
    }

    public function test_remote_company_logo_path_is_a_bounded_pdf_safe_data_uri(): void
    {
        $storageSettings = app(StorageSettingsService::class);
        $r2Settings = [
            'enabled' => false,
            'access_key_id' => 'test-access-key',
            'secret_access_key' => 'test-secret-key',
            'public_bucket' => 'test-public-bucket',
            'endpoint' => 'https://example.r2.cloudflarestorage.com',
            'public_url' => 'https://cdn.example.test',
        ];
        $storageSettings->save($r2Settings);
        AppSetting::setValue(StorageSettingsService::PUBLIC_TOPOLOGY_LOCKED, '1');
        $storageSettings->forgetCachedSettings();
        $storageSettings->save([...$r2Settings, 'enabled' => true]);
        Storage::fake('r2_public');

        $company = Company::query()->create([
            'name' => 'Remote Logo Company',
            'slug' => 'remote-logo-company',
            'invoice_prefix' => 'RLC',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);
        $storage = app(CompanyStorageService::class);
        $logo = $storage->putPublic($company, 'company', 'logo.png', 'remote-logo-bytes');
        $company->forceFill(['logo' => $logo])->save();

        $this->assertSame(
            'data:image/png;base64,'.base64_encode('remote-logo-bytes'),
            app(CompanySettingsService::class)->logoPath($company),
        );
    }

    public function test_admin_login_ignores_an_invalid_global_logo_path(): void
    {
        AppSetting::setValue(CompanySettingsService::LOGO, 'companies/not-a-storage-key/public/company/logo.png');
        AppSetting::setValue(CompanySettingsService::DARK_LOGO, 'companies/not-a-storage-key/public/company/dark-logo.png');

        $this->get('/admin/login')->assertOk();
    }

    public function test_companies_page_ignores_invalid_company_logo_paths(): void
    {
        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $company = $admin->defaultCompany();
        $settings = (array) $company->settings;
        $settings['dark_logo'] = 'companies/not-a-storage-key/public/company/dark-logo.png';
        $company->forceFill([
            'logo' => 'companies/not-a-storage-key/public/company/logo.png',
            'settings' => $settings,
        ])->save();

        $this->actingAs($admin)
            ->withSession(['current_company_id' => $company->getKey()])
            ->get('/admin/company-management/companies')
            ->assertOk()
            ->assertSee($company->name);

        $profile = app(CompanySettingsService::class)->profile($company->fresh());

        $this->assertNull($profile['logo_url']);
        $this->assertNull($profile['dark_logo_url']);
        $this->assertNull($profile['logo_path']);
        $this->assertNull($profile['dark_logo_path']);
        $this->assertSame(
            'companies/not-a-storage-key/public/company/logo.png',
            $company->fresh()->logo,
        );
    }
}
