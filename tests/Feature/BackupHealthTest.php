<?php

namespace Tests\Feature;

use App\Filament\Widgets\BackupHealthWidget;
use App\Models\BackupRun;
use App\Models\DeploymentError;
use App\Models\User;
use App\Services\BackupHealth;
use App\Services\BackupSettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class BackupHealthTest extends TestCase
{
    use RefreshDatabase;

    private function record(string $scope, string $kind, string $status = 'success', ?Carbon $finished = null, array $extra = [], ?Carbon $created = null): BackupRun
    {
        $finished ??= now();
        $run = BackupRun::query()->create([
            'scope' => $scope,
            'kind' => $kind,
            'status' => $status,
            'error' => $status === 'failed' ? 'boom' : null,
            'started_at' => $finished->copy()->subMinute(),
            'finished_at' => $finished,
            ...$extra,
        ]);

        if ($created !== null) {
            $run->forceFill(['created_at' => $created])->save();
        }

        return $run;
    }

    /** @return array{key: string, label: string, state: string, detail: string, at: mixed, optional: bool} */
    private function check(string $key): array
    {
        $check = collect(app(BackupHealth::class)->checks())->firstWhere('key', $key);
        $this->assertNotNull($check, "There is no [{$key}] check.");

        return $check;
    }

    private function driveOn(): void
    {
        app(BackupSettingsService::class)->saveGoogleDrive([
            'enabled' => true, 'auto_upload' => true, 'folder_id' => 'folder', 'service_account_path' => '', 'service_account_json' => '',
        ]);
    }

    // --- the checks --------------------------------------------------------

    public function test_with_nothing_recorded_it_is_unknown_and_points_at_the_scheduler(): void
    {
        $this->assertSame('unknown', $this->check('hourly')['state']);
        $this->assertStringContainsString('schedule:run', $this->check('hourly')['detail']);
        $this->assertSame('unknown', app(BackupHealth::class)->overall());
    }

    public function test_fresh_backups_are_ok(): void
    {
        $this->record('database', 'hourly', finished: now()->subMinutes(20));
        $this->record('app', 'daily', finished: now()->subHours(5));

        $this->assertSame('ok', $this->check('hourly')['state']);
        $this->assertStringContainsString('20 minutes ago', $this->check('hourly')['detail']);
        $this->assertSame('ok', $this->check('daily')['state']);
        // The restore test is not set up: it neither counts against the overall state nor is hidden.
        $this->assertSame('unknown', $this->check('drill')['state']);
        $this->assertTrue($this->check('drill')['optional']);
        $this->assertSame('ok', app(BackupHealth::class)->overall());
    }

    public function test_an_overdue_backup_is_danger_and_says_why_the_last_try_failed(): void
    {
        $this->record('database', 'hourly', finished: now()->subHours(5));
        $this->record('database', 'hourly', 'failed', finished: now()->subHour(), extra: ['error' => 'mysqldump: Access denied']);

        $check = $this->check('hourly');

        $this->assertSame('danger', $check['state']);
        $this->assertStringContainsString('The last good one was 5 hours ago', $check['detail']);
        $this->assertStringContainsString('mysqldump: Access denied', $check['detail']);
        $this->assertSame('danger', app(BackupHealth::class)->overall());
    }

    public function test_a_kind_that_never_succeeded_is_only_an_alarm_once_backups_have_been_around_for_a_while(): void
    {
        // Just deployed: the first backup is minutes old, the hourly one has not had its turn.
        $this->record('database', 'predeploy', created: now()->subMinutes(10));
        $this->assertSame('unknown', $this->check('hourly')['state']);

        // Backups of another kind have existed for hours and the hourly one never worked.
        BackupRun::query()->delete();
        $this->record('database', 'predeploy', created: now()->subHours(6));
        $this->record('database', 'hourly', 'failed');

        $this->assertSame('danger', $this->check('hourly')['state']);
        $this->assertStringContainsString('No successful one has been recorded.', $this->check('hourly')['detail']);
        $this->assertStringContainsString('boom', $this->check('hourly')['detail']);
    }

    public function test_switches_decide_which_checks_exist(): void
    {
        Config::set('backup.hourly.enabled', false);
        Config::set('backup.daily.full_app', false);
        $this->record('database', 'daily', finished: now()->subHours(3));

        $keys = array_column(app(BackupHealth::class)->checks(), 'key');

        $this->assertNotContains('hourly', $keys);
        $this->assertSame('Daily database backup', $this->check('daily')['label']);
        $this->assertSame('ok', $this->check('daily')['state']);
        $this->assertNotContains('offsite', $keys, 'no Drive, no off-site check');
    }

    public function test_the_off_site_copy_is_judged_by_when_it_arrived_not_when_the_backup_ran(): void
    {
        $this->driveOn();
        $this->record('database', 'hourly', finished: now()->subMinutes(10));

        // The backup ran, but nothing ever reached Drive and backups are old enough to expect it.
        BackupRun::query()->update(['created_at' => now()->subHours(6)]);
        $this->record('database', 'hourly', 'failed', extra: ['error' => 'Google Drive upload failed.']);
        $this->assertSame('danger', $this->check('offsite')['state']);
        $this->assertStringContainsString('No copy has reached Google Drive yet.', $this->check('offsite')['detail']);
        $this->assertStringContainsString('Google Drive upload failed.', $this->check('offsite')['detail']);

        $this->record('database', 'hourly', finished: now()->subMinutes(30), extra: ['offsite_at' => now()->subMinutes(30)]);
        $this->assertSame('ok', $this->check('offsite')['state']);

        BackupRun::query()->update(['offsite_at' => now()->subHours(8)]);
        $this->assertSame('danger', $this->check('offsite')['state']);
        $this->assertStringContainsString('The last copy reached Google Drive 8 hours ago.', $this->check('offsite')['detail']);
    }

    public function test_the_restore_test_is_ok_then_a_warning_then_danger_when_it_fails(): void
    {
        Config::set('backup.drill.enabled', true);
        $this->assertSame('unknown', $this->check('drill')['state']);
        // Set up but not run yet: it counts, unlike a restore test nobody asked for.
        $this->assertFalse($this->check('drill')['optional']);

        $this->record('drill', 'local', finished: now()->subDays(3));
        $this->assertSame('ok', $this->check('drill')['state']);

        BackupRun::query()->delete();
        $this->record('drill', 'local', finished: now()->subDays(12));
        $this->assertSame('warning', $this->check('drill')['state']);

        $this->record('drill', 'drive', 'failed', finished: now()->subHour(), extra: ['error' => 'Loading the dump failed: ERROR 1064']);
        $this->assertSame('danger', $this->check('drill')['state']);
        $this->assertStringContainsString('ERROR 1064', $this->check('drill')['detail']);
    }

    public function test_without_the_table_there_is_nothing_to_judge_and_nothing_breaks(): void
    {
        Schema::drop('backup_runs');

        $this->assertSame([], app(BackupHealth::class)->checks());
        $this->assertSame('unknown', app(BackupHealth::class)->overall());
        $this->assertFalse(BackupHealthWidget::canView());
    }

    // --- backup:check -------------------------------------------------------

    public function test_the_check_command_is_quiet_when_all_is_well(): void
    {
        $this->record('database', 'hourly');
        $this->record('app', 'daily');

        $this->artisan('backup:check')->expectsOutputToContain('Hourly database backup')->assertExitCode(0);

        $this->assertSame(0, DeploymentError::query()->count());
    }

    public function test_an_overdue_backup_alerts_once_then_reminds_after_twelve_hours_and_starts_over_after_recovery(): void
    {
        $this->record('database', 'hourly', finished: now()->subHours(6));
        $this->record('app', 'daily');

        $this->artisan('backup:check')->assertExitCode(1);
        $this->artisan('backup:check')->assertExitCode(1);
        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:health:hourly')->count(), 'the same problem is not reported every 30 minutes');
        $this->assertStringContainsString('Hourly database backup', (string) DeploymentError::query()->value('message'));

        $this->travel(13)->hours();
        $this->record('app', 'daily');
        $this->artisan('backup:check')->assertExitCode(1);
        $this->assertSame(2, DeploymentError::query()->where('source', 'backup:health:hourly')->count(), 'still overdue after 12 hours: a reminder');

        // Fixed: the next problem is a new alert, not swallowed by the old reminder window.
        $this->record('database', 'hourly');
        $this->artisan('backup:check')->assertExitCode(0);
        $this->travel(3)->hours();
        $this->record('app', 'daily');
        $this->artisan('backup:check')->assertExitCode(1);
        $this->assertSame(3, DeploymentError::query()->where('source', 'backup:health:hourly')->count());
        $this->assertNotNull(Cache::get('backup-health-alerted:hourly'));
    }

    public function test_a_failed_restore_test_alerts_through_the_same_command(): void
    {
        Config::set('backup.drill.enabled', true);
        $this->record('database', 'hourly');
        $this->record('app', 'daily');
        $this->record('drill', 'local', 'failed', extra: ['error' => 'Loading the dump failed']);

        $this->artisan('backup:check')->assertExitCode(1);

        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:health:drill')->count());
    }

    public function test_the_check_runs_every_thirty_minutes_without_overlapping_itself(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('backup:check')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'backup:check'));

        $this->assertNotNull($event);
        $this->assertSame('*/30 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
    }

    // --- /health/backups -----------------------------------------------------

    public function test_the_monitor_address_is_off_without_a_token_and_hidden_behind_it(): void
    {
        $this->get('/health/backups?token=anything')->assertNotFound();

        Config::set('backup.health.token', 'monitor-secret');
        $this->get('/health/backups')->assertNotFound();
        $this->get('/health/backups?token=wrong')->assertNotFound();
    }

    public function test_the_monitor_gets_503_when_a_backup_is_overdue_and_200_when_all_is_fresh(): void
    {
        Config::set('backup.health.token', 'monitor-secret');

        // Nothing recorded: a backup monitor must not report "fine".
        $response = $this->get('/health/backups?token=monitor-secret')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unknown');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->record('database', 'hourly', finished: now()->subHours(4));
        $this->record('app', 'daily');
        $this->get('/health/backups?token=monitor-secret')
            ->assertStatus(503)
            ->assertJsonPath('status', 'danger')
            ->assertJsonPath('checks.hourly', 'danger')
            ->assertJsonPath('checks.daily', 'ok');

        $this->record('database', 'hourly');
        $this->withHeader('Authorization', 'Bearer monitor-secret')->get('/health/backups')
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        // Details such as file names or error text never leave through this address.
        $this->assertStringNotContainsString('boom', (string) $this->get('/health/backups?token=monitor-secret')->getContent());
    }

    // --- dashboard card and history -------------------------------------------

    public function test_the_card_is_for_people_who_manage_backups_only(): void
    {
        $this->record('database', 'hourly');
        $accountant = User::factory()->create(['role' => 'accountant', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($accountant);
        $this->assertFalse(BackupHealthWidget::canView());

        $this->actingAs($admin);
        $this->assertTrue(BackupHealthWidget::canView());

        Livewire::test(BackupHealthWidget::class)
            ->assertSee('Backup health')
            ->assertSee('Hourly database backup')
            ->assertSee('Not set up');
    }

    public function test_the_card_shows_an_overdue_backup_in_words(): void
    {
        $this->record('database', 'hourly', finished: now()->subHours(7));
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin);

        Livewire::test(BackupHealthWidget::class)
            ->assertSee('Overdue')
            ->assertSee('The last good one was 7 hours ago');
    }

    public function test_the_history_lists_restore_tests_and_marks_copies_sent_to_drive(): void
    {
        $this->record('database', 'hourly', extra: [
            'filename' => 'database-backup-20261023-120500-mysql-hourly.sql.gz',
            'sha256' => str_repeat('cd', 32),
            'offsite_at' => now(),
        ]);
        $this->record('drill', 'drive', extra: [
            'filename' => 'database-backup-20261023-120500-mysql-hourly.sql.gz',
            'note' => 'Restored into a scratch database in 9 s: 162 tables, 264 migrations (latest 2026_10_23_110000_x).',
        ]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get('/admin/settings/backups')
            ->assertOk()
            ->assertSee('Restore test')
            ->assertSee('Google Drive copy')
            ->assertSee('Sent to Drive')
            ->assertSee('162 tables, 264 migrations');
    }
}
