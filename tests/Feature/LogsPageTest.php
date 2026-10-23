<?php

namespace Tests\Feature;

use App\Filament\Pages\Logs;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\CourierStatusLogs\CourierStatusLogResource;
use App\Filament\Resources\DeploymentErrors\DeploymentErrorResource;
use App\Filament\Resources\MobileCrashReports\MobileCrashReportResource;
use App\Filament\Resources\SmsLogs\SmsLogResource;
use App\Livewire\LogTable;
use App\Models\Company;
use App\Models\DeploymentError;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\CompanyContext;
use App\Support\LogCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings → Logs (owner request 2026-10-23): every log is a tab on one page,
 * shown only to people who may open that log, and the old sidebar entries are
 * gone while the logs' own pages keep working.
 */
class LogsPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Logs Co', 'slug' => 'logs-co', 'invoice_prefix' => 'LGS', 'currency' => 'BDT', 'timezone' => 'UTC', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
    }

    public function test_a_super_admin_sees_every_log_as_a_tab(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $this->assertSame(
            ['sms', 'audit', 'courier_status', 'courier_webhooks', 'meta_events', 'deploy_errors', 'mobile_crashes'],
            array_keys(LogCatalog::visible()),
        );

        Livewire::test(Logs::class)
            ->assertOk()
            ->assertSee('Courier webhooks')
            ->assertSee('Deploy errors')
            ->assertSee('Mobile crashes');
    }

    public function test_the_logs_menu_replaces_the_separate_log_entries(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $this->assertTrue(Logs::shouldRegisterNavigation());
        $this->assertSame('Logs', Logs::getNavigationLabel());

        foreach ([AuditLogResource::class, SmsLogResource::class, DeploymentErrorResource::class, MobileCrashReportResource::class] as $resource) {
            $this->assertFalse($resource::shouldRegisterNavigation(), $resource.' still has its own sidebar entry');
        }
    }

    public function test_every_tab_renders_its_table_for_a_super_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        SmsLog::query()->create(['company_id' => $this->company->id, 'kind' => SmsLog::KIND_TEST, 'phone' => '01712345678', 'status' => SmsLog::STATUS_SENT]);
        DeploymentError::query()->create(['source' => 'migrate', 'message' => 'It broke', 'details' => 'trace', 'occurred_at' => now()]);

        foreach (array_keys(LogCatalog::visible()) as $key) {
            Livewire::test(LogTable::class, ['log' => $key])->assertOk();
        }

        Livewire::test(LogTable::class, ['log' => 'sms'])->assertSee('01712345678');
        Livewire::test(LogTable::class, ['log' => 'deploy_errors'])->assertSee('It broke');
    }

    public function test_a_log_the_user_may_not_open_is_not_a_tab_and_cannot_be_embedded(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));

        $visible = array_keys(LogCatalog::visible());
        $this->assertNotContains('deploy_errors', $visible);
        $this->assertNotContains('mobile_crashes', $visible);
        $this->assertNotContains('courier_webhooks', $visible);

        Livewire::test(LogTable::class, ['log' => 'deploy_errors'])->assertForbidden();
        Livewire::test(LogTable::class, ['log' => 'no_such_log'])->assertForbidden();
    }

    public function test_staff_without_settings_access_do_not_get_the_logs_page(): void
    {
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        $this->actingAs($staff);
        $this->assertFalse(Logs::canAccess());
        $this->actingAs($staff)->get('/admin/settings/logs')->assertForbidden();

        // Their own courier log pages are unchanged.
        $this->assertTrue(CourierStatusLogResource::canViewAny());
    }

    public function test_the_sms_tab_warns_when_the_queue_worker_is_not_running(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        SmsLog::query()->create(['company_id' => $this->company->id, 'kind' => SmsLog::KIND_ORDER_PLACED, 'status' => SmsLog::STATUS_QUEUED]);
        $this->travel(10)->minutes();

        Livewire::test(LogTable::class, ['log' => 'sms'])->assertSee('queue worker does not seem to be running');
    }

    public function test_the_phone_layout_of_an_sms_row_escapes_what_the_gateway_said(): void
    {
        $row = new SmsLog([
            'kind' => SmsLog::KIND_ORDER_PLACED, 'status' => SmsLog::STATUS_FAILED, 'phone' => '01712345678',
            'reason' => 'gateway_refused', 'gateway_code' => 1011, 'detail' => '<script>alert(1)</script>',
        ]);

        $html = SmsLogResource::summaryHtml($row);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Not sent: gateway', $html);
        $this->assertStringContainsString('01712345678', $html);
    }

    public function test_the_logs_own_pages_still_open(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $error = DeploymentError::query()->create(['source' => 'migrate', 'message' => 'It broke', 'details' => 'trace', 'occurred_at' => now()]);

        $this->actingAs($admin)->get(DeploymentErrorResource::getUrl('view', ['record' => $error], panel: 'admin'))->assertOk();
        $this->actingAs($admin)->get(SmsLogResource::getUrl(panel: 'admin'))->assertOk();
    }
}
