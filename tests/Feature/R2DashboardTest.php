<?php

namespace Tests\Feature;

use App\Filament\Pages\R2Backups;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\BackupSettingsService;
use App\Services\R2BackupService;
use App\Services\R2CostEstimator;
use App\Services\R2UsageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeS3;
use Tests\TestCase;

/**
 * The R2 bill estimate (R2CostEstimator), what is in the bucket (R2UsageReport) and the
 * R2 Backups page that shows both at a glance.
 */
class R2DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = '0123456789abcdef0123456789abcdef';

    private FakeS3 $s3;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('backup.r2.part_bytes', 16 * 1024 * 1024);
        $this->work = storage_path('framework/testing/r2dash-'.str()->random(6));
        File::ensureDirectoryExists($this->work);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);

        parent::tearDown();
    }

    // --- builders --------------------------------------------------------

    private function r2Settings(array $override = []): void
    {
        app(BackupSettingsService::class)->saveR2([
            'enabled' => true,
            'auto_upload' => true,
            'account' => self::ACCOUNT,
            'bucket' => 'erp-backups-bucket',
            'prefix' => 'erp-backups',
            'access_key_id' => 'AKIATEST',
            'secret_access_key' => 'super-secret',
            ...$override,
        ]);
    }

    private function r2(): FakeS3
    {
        $this->s3 = new FakeS3;
        $this->app->instance(R2BackupService::class, app(R2BackupService::class)->withClient($this->s3->client()));

        return $this->s3;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function estimator(): R2CostEstimator
    {
        return app(R2CostEstimator::class);
    }

    private function runRow(string $scope, string $kind, int $size, array $extra = []): BackupRun
    {
        return BackupRun::query()->create([
            'scope' => $scope,
            'kind' => $kind,
            'status' => 'success',
            'filename' => "{$scope}-{$kind}",
            'size_bytes' => $size,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            ...$extra,
        ]);
    }

    // --- the bill ----------------------------------------------------------

    public function test_inside_the_free_allowance_the_bill_is_nothing(): void
    {
        $bill = $this->estimator()->bill(5_000_000_000, 10_000, 100_000);

        $this->assertEqualsWithDelta(5.0, $bill['storage_gb'], 0.0001);
        $this->assertSame(0.0, $bill['total_usd']);
        $this->assertTrue($bill['within_free']);
        $this->assertEqualsWithDelta(50.0, $bill['free_storage_used_percent'], 0.001);

        $edge = $this->estimator()->bill(10_000_000_000, 1_000_000, 10_000_000);
        $this->assertSame(0.0, $edge['total_usd'], 'Exactly the allowance is still free.');
    }

    public function test_storage_beyond_the_allowance_is_billed_per_gigabyte(): void
    {
        $bill = $this->estimator()->bill(100_000_000_000, 0, 0);

        $this->assertEqualsWithDelta(90.0, $bill['storage_billable_gb'], 0.0001);
        $this->assertEqualsWithDelta(1.35, $bill['storage_usd'], 0.0001);
        $this->assertEqualsWithDelta(1.35, $bill['total_usd'], 0.0001);
        $this->assertFalse($bill['within_free']);
    }

    public function test_operations_beyond_the_allowance_are_billed_per_million(): void
    {
        $bill = $this->estimator()->bill(0, 2_000_000, 12_000_000);

        $this->assertSame(1_000_000, $bill['class_a_billable']);
        $this->assertSame(2_000_000, $bill['class_b_billable']);
        $this->assertEqualsWithDelta(4.50, $bill['class_a_usd'], 0.0001);
        $this->assertEqualsWithDelta(0.72, $bill['class_b_usd'], 0.0001);
        $this->assertEqualsWithDelta(5.22, $bill['total_usd'], 0.0001);
    }

    public function test_prices_come_from_the_config_and_taka_only_with_a_rate(): void
    {
        $this->assertNull($this->estimator()->bill(100_000_000_000, 0, 0)['total_bdt']);

        app(BackupSettingsService::class)->saveR2(['usd_bdt_rate' => '120']);
        Config::set('backup.r2.pricing.storage_per_gb_month', 0.02);
        Config::set('backup.r2.pricing.free_storage_gb', 20);

        $bill = $this->estimator()->bill(120_000_000_000, 0, 0);

        $this->assertEqualsWithDelta(2.0, $bill['total_usd'], 0.0001, '100 billable GB at $0.02.');
        $this->assertEqualsWithDelta(240.0, $bill['total_bdt'], 0.001);
        $this->assertSame(120.0, $this->estimator()->pricing()['usd_bdt_rate']);
    }

    public function test_an_upload_counts_one_write_or_start_parts_and_finish_plus_a_list_and_a_check(): void
    {
        $estimator = $this->estimator();

        $this->assertSame([2, 1], $estimator->operationsPerUpload(1_000_000), 'One write, one list, one size check.');
        $this->assertSame([2, 1], $estimator->operationsPerUpload(16 * 1024 * 1024), 'Exactly one part is still one write.');
        $this->assertSame([3 + 2 + 1, 1], $estimator->operationsPerUpload(40 * 1024 * 1024), 'Start + 3 parts + finish + the list.');
    }

    public function test_the_month_so_far_counts_the_uploads_that_reached_r2_and_the_tests_that_read_it(): void
    {
        $this->runRow('database', 'hourly', 1_000_000, ['offsite_to' => 'r2,drive', 'offsite_at' => now()]);
        $this->runRow('database', 'hourly', 1_000_000, ['offsite_to' => 'r2', 'offsite_at' => now()]);
        $this->runRow('app', 'daily', 40 * 1024 * 1024, ['offsite_to' => 'r2', 'offsite_at' => now()]);
        $this->runRow('database', 'hourly', 1_000_000, ['offsite_to' => 'drive', 'offsite_at' => now()]);
        $this->runRow('database', 'hourly', 1_000_000, ['offsite_to' => 'r2', 'offsite_at' => now(), 'finished_at' => now()->subMonths(2)]);
        $this->runRow('drill', 'r2', 1_000_000);

        $month = $this->estimator()->monthToDate();

        $this->assertSame(3, $month['uploads']);
        $this->assertSame(1, $month['drills']);
        $this->assertSame(2 + 2 + 6 + 1, $month['class_a'], 'The uploads, plus one list for the test.');
        $this->assertSame(1 + 1 + 1 + 1, $month['class_b'], 'The size checks, plus one download for the test.');
    }

    public function test_the_full_schedule_is_forecast_from_the_size_of_the_copies_already_there(): void
    {
        $groups = [
            ['scope' => 'database', 'kind' => 'hourly', 'avg_bytes' => 10_000_000],
            ['scope' => 'app', 'kind' => 'daily', 'avg_bytes' => 100_000_000],
        ];
        $estimator = $this->estimator();

        $forecast = $estimator->forecast($groups);

        $this->assertTrue($forecast['has_data']);
        $byKind = collect($forecast['rows'])->keyBy(fn (array $row): string => $row['scope'].'|'.$row['kind']);
        $this->assertSame(48 * 10_000_000, $byKind['database|hourly']['steady_bytes']);
        $this->assertSame(720, $byKind['database|hourly']['uploads_per_month']);
        $this->assertSame(7 * 100_000_000, $byKind['app|daily']['steady_bytes']);
        $this->assertSame(30, $byKind['app|daily']['uploads_per_month']);
        $this->assertSame(48 * 10_000_000 + 7 * 100_000_000, $forecast['steady_bytes']);
        [$hourlyA, $hourlyB] = $estimator->operationsPerUpload(10_000_000);
        [$dailyA, $dailyB] = $estimator->operationsPerUpload(100_000_000);
        $this->assertSame(720 * $hourlyA + 30 * $dailyA, $forecast['class_a']);
        $this->assertSame(720 * $hourlyB + 30 * $dailyB, $forecast['class_b']);
    }

    public function test_the_forecast_follows_the_switches_and_never_plans_for_manual_copies(): void
    {
        Config::set('backup.hourly.enabled', false);
        Config::set('backup.daily.full_app', false);
        Config::set('backup.offsite.database_kinds', ['daily', 'manual']);
        Config::set('backup.offsite.app_kinds', []);
        $groups = [
            ['scope' => 'database', 'kind' => 'hourly', 'avg_bytes' => 10],
            ['scope' => 'database', 'kind' => 'daily', 'avg_bytes' => 5_000_000],
            ['scope' => 'database', 'kind' => 'manual', 'avg_bytes' => 5_000_000],
            ['scope' => 'app', 'kind' => 'daily', 'avg_bytes' => 5_000_000],
        ];

        $forecast = $this->estimator()->forecast($groups);

        $this->assertSame([['database', 'daily']], array_map(fn (array $row): array => [$row['scope'], $row['kind']], $forecast['rows']));
        $this->assertSame(30 * 5_000_000, $forecast['steady_bytes']);
    }

    public function test_the_size_of_a_copy_falls_back_to_the_backup_history(): void
    {
        $this->runRow('database', 'hourly', 8_000_000);
        $this->runRow('database', 'hourly', 12_000_000);
        $this->runRow('app', 'daily', 80_000_000);

        $forecast = $this->estimator()->forecast([]);

        $byKind = collect($forecast['rows'])->keyBy(fn (array $row): string => $row['scope'].'|'.$row['kind']);
        $this->assertSame(10_000_000, $byKind['database|hourly']['avg_bytes']);
        $this->assertSame(80_000_000, $byKind['app|daily']['avg_bytes']);
        $this->assertFalse($byKind->has('database|predeploy'), 'Nothing to measure for a kind that never ran.');
    }

    public function test_with_nothing_to_measure_there_is_no_forecast_but_there_are_examples(): void
    {
        $estimator = $this->estimator();

        $this->assertFalse($estimator->forecast([])['has_data']);

        $small = $estimator->example(50_000_000, 500_000_000);
        // 48 hourly + 10 before-deploy database copies and 7 daily archives.
        $this->assertSame(58 * 50_000_000 + 7 * 500_000_000, $small['steady_bytes']);
        $this->assertSame(0.0, $small['bill']['total_usd'], 'About 6 GB fits in the free 10 GB.');

        $large = $estimator->example(1_000_000_000, 10_000_000_000);
        $this->assertSame(58 * 1_000_000_000 + 7 * 10_000_000_000, $large['steady_bytes']);
        $this->assertEqualsWithDelta((128 - 10) * 0.015, $large['bill']['storage_usd'], 0.0001);
    }

    public function test_growth_scenarios_scale_the_size_and_so_the_bill(): void
    {
        $groups = [['scope' => 'database', 'kind' => 'hourly', 'avg_bytes' => 100_000_000]];

        $scenarios = collect($this->estimator()->scenarios($groups));

        $this->assertSame([1.0, 2.0, 5.0, 10.0], $scenarios->pluck('factor')->all());
        $this->assertSame(48 * 100_000_000, $scenarios[0]['steady_bytes']);
        $this->assertSame(48 * 1_000_000_000, $scenarios[3]['steady_bytes']);
        $this->assertSame(0.0, $scenarios[0]['bill']['total_usd']);
        $this->assertEqualsWithDelta((48 - 10) * 0.015, $scenarios[3]['bill']['storage_usd'], 0.0001);
    }

    // --- what is in the bucket -----------------------------------------------

    public function test_the_report_groups_the_copies_by_type_and_counts_other_files_apart(): void
    {
        $this->r2Settings();
        $s3 = $this->r2();
        $s3->put('erp-backups/database-backup-20261023-100500-mysql-hourly.sql.gz.enc', str_repeat('a', 100), new \DateTimeImmutable('2026-10-23 10:05:00'));
        $s3->put('erp-backups/database-backup-20261023-110500-mysql-hourly.sql.gz.enc', str_repeat('a', 300), new \DateTimeImmutable('2026-10-23 11:05:00'));
        $s3->put('erp-backups/app-backup-20261023-020000-daily.zip.enc', str_repeat('b', 1000), new \DateTimeImmutable('2026-10-23 02:00:00'));
        $s3->put('erp-backups/notes.txt', str_repeat('c', 50));

        $report = app(R2UsageReport::class)->snapshot();

        $this->assertSame('ready', $report['state']);
        $this->assertSame(4, $report['count']);
        $this->assertSame(1450, $report['bytes'], 'Everything in the folder is billed, ours or not.');
        $this->assertSame(['count' => 1, 'bytes' => 50], $report['other']);

        $groups = collect($report['groups'])->keyBy(fn (array $group): string => $group['scope'].'|'.$group['kind']);
        $this->assertSame(['database|hourly', 'app|daily'], $groups->keys()->all(), 'Database before app.');
        $this->assertSame(2, $groups['database|hourly']['count']);
        $this->assertSame(400, $groups['database|hourly']['bytes']);
        $this->assertSame(200, $groups['database|hourly']['avg_bytes']);
        $this->assertSame(48, $groups['database|hourly']['keep']);
        $this->assertStringStartsWith('2026-10-23T11:05:00', $groups['database|hourly']['newest_at']);
        $this->assertStringStartsWith('2026-10-23T10:05:00', $groups['database|hourly']['oldest_at']);

        $this->assertSame(
            ['database-backup-20261023-110500-mysql-hourly.sql.gz.enc', 'database-backup-20261023-100500-mysql-hourly.sql.gz.enc', 'app-backup-20261023-020000-daily.zip.enc'],
            array_column($report['files'], 'name'),
            'Newest first, and only our own files.',
        );
        $this->assertTrue($report['files'][0]['encrypted']);
    }

    public function test_the_reading_is_kept_for_a_while_and_refresh_reads_again(): void
    {
        $this->r2Settings();
        $s3 = $this->r2();
        $s3->put('erp-backups/database-backup-20261023-100500-mysql-hourly.sql.gz.enc', 'a');
        $usage = app(R2UsageReport::class);

        $this->assertSame(1, $usage->snapshot()['count']);
        $s3->put('erp-backups/database-backup-20261023-110500-mysql-hourly.sql.gz.enc', 'a');
        $this->assertSame(1, $usage->snapshot()['count'], 'Reused: a page view must not cost a request each time.');
        $this->assertSame(1, $s3->count('ListObjectsV2'));

        $this->assertSame(2, $usage->snapshot(refresh: true)['count']);
    }

    public function test_a_broken_connection_is_a_state_of_the_report_not_an_exception(): void
    {
        $this->r2Settings();
        $s3 = $this->r2();
        $s3->fail = fn (): string => 'AccessDenied';

        $report = app(R2UsageReport::class)->snapshot();

        $this->assertSame('error', $report['state']);
        $this->assertStringContainsString('access was refused', (string) $report['error']);
        $this->assertSame(0, $report['bytes']);

        $this->assertSame('not_set_up', (function () {
            $this->r2Settings(['bucket' => '']);
            Cache::flush();

            return app(R2UsageReport::class)->snapshot()['state'];
        })());
    }

    // --- the page ------------------------------------------------------------

    public function test_the_page_is_for_people_who_manage_backups_only(): void
    {
        $accountant = User::factory()->create(['role' => 'accountant', 'is_active' => true]);

        $this->actingAs($accountant)->get('/admin/settings/r2-backups')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/settings/r2-backups')->assertOk();
    }

    public function test_before_setup_it_shows_the_steps_and_what_typical_backups_would_cost(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings/r2-backups')
            ->assertOk()
            ->assertSee('R2 Backup Storage')
            ->assertSee('Not set up')
            ->assertSee('Set up Cloudflare R2')
            ->assertSee('What will it cost?')
            ->assertSee('Examples with this backup schedule')
            ->assertSee('Check Cloudflare');
    }

    public function test_with_a_bucket_it_shows_the_size_the_free_allowance_the_copies_and_the_bill(): void
    {
        $this->r2Settings();
        $s3 = $this->r2();
        foreach (['100500', '110500', '120500'] as $time) {
            $s3->put("erp-backups/database-backup-20261023-{$time}-mysql-hourly.sql.gz.enc", str_repeat('a', 2_000_000));
        }
        $s3->put('erp-backups/app-backup-20261023-020000-daily.zip.enc', str_repeat('b', 10_000_000));

        $this->actingAs($this->admin())->get('/admin/settings/r2-backups')
            ->assertOk()
            ->assertSee('Connected')
            ->assertSee('Stored in R2')
            ->assertSee('16 MB')
            ->assertSee('4 files')
            ->assertSee('0.2%')
            ->assertSee('Inside the free allowance')
            ->assertSee('Copies by type')
            ->assertSee('Hourly · Database')
            ->assertSee('Daily · Full app')
            ->assertSee('3 / 48')
            ->assertSee('Latest files')
            ->assertSee('database-backup-20261023-120500-mysql-hourly.sql.gz.enc')
            ->assertSee('Encrypted')
            ->assertSee('erp-backups-bucket/erp-backups')
            ->assertSee('If your backups grow')
            ->assertDontSee('Examples with this backup schedule');
    }

    public function test_over_the_free_allowance_the_page_says_so_and_shows_the_bill_in_taka_with_a_rate(): void
    {
        $this->r2Settings(['usd_bdt_rate' => '120']);
        $this->r2();
        Cache::put(R2UsageReport::CACHE_KEY, [
            'state' => 'ready', 'error' => null, 'fetched_at' => now()->toIso8601String(),
            'count' => 3, 'bytes' => 110_000_000_000, 'groups' => [], 'files' => [], 'other' => ['count' => 0, 'bytes' => 0],
        ], now()->addMinutes(5));

        $this->actingAs($this->admin())->get('/admin/settings/r2-backups')
            ->assertOk()
            ->assertSee('Over the free allowance')
            ->assertSee('$ 1.5')
            ->assertSee('৳ 180');
    }

    public function test_a_broken_connection_shows_a_message_and_not_an_error_page(): void
    {
        $this->r2Settings();
        $s3 = $this->r2();
        $s3->fail = fn (): string => 'AccessDenied';

        $this->actingAs($this->admin())->get('/admin/settings/r2-backups')
            ->assertOk()
            ->assertSee('Problem')
            ->assertSee('access was refused');
    }

    public function test_configure_saves_the_settings_and_checks_what_is_typed(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(R2Backups::class)
            ->callAction('configureR2', [
                'enabled' => true,
                'auto_upload' => true,
                'account' => 'not valid',
                'bucket' => 'Bad Bucket!',
                'prefix' => 'a b',
                'access_key_id' => 'AKIA',
                'secret_access_key' => 'shh',
                'usd_bdt_rate' => '',
            ])
            ->assertHasActionErrors(['account', 'bucket', 'prefix']);

        Livewire::test(R2Backups::class)
            ->callAction('configureR2', [
                'enabled' => true,
                'auto_upload' => true,
                'account' => self::ACCOUNT,
                'bucket' => 'erp-backups-bucket',
                'prefix' => 'erp-backups',
                'access_key_id' => 'AKIATEST',
                'secret_access_key' => 'shh-secret',
                'usd_bdt_rate' => '122',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('R2 settings saved');

        $settings = app(BackupSettingsService::class);
        $this->assertTrue($settings->r2Enabled());
        $this->assertTrue($settings->r2AutoUpload());
        $this->assertSame('erp-backups-bucket', $settings->r2Bucket());
        $this->assertSame('shh-secret', $settings->r2SecretAccessKey());
        $this->assertSame(122.0, $settings->r2UsdBdtRate());
        $this->assertSame([], app(R2BackupService::class)->missingSettings());
    }

    public function test_configure_warns_when_it_is_switched_on_but_not_complete(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(R2Backups::class)
            ->callAction('configureR2', [
                'enabled' => true,
                'auto_upload' => false,
                'account' => self::ACCOUNT,
                'bucket' => '',
                'prefix' => 'erp-backups',
                'access_key_id' => '',
                'secret_access_key' => '',
                'usd_bdt_rate' => '',
            ])
            ->assertNotified('R2 settings saved, but not complete');
    }

    public function test_test_connection_tells_whether_it_works(): void
    {
        $this->r2Settings();
        $s3 = $this->r2();
        $this->actingAs($this->admin());

        Livewire::test(R2Backups::class)
            ->callAction('testR2Connection')
            ->assertNotified('R2 connection works');

        $s3->fail = fn ($command): ?string => $command->getName() === 'PutObject' ? 'AccessDenied' : null;

        Livewire::test(R2Backups::class)
            ->callAction('testR2Connection')
            ->assertNotified('R2 connection failed');
    }

    public function test_back_up_now_makes_a_database_backup_and_sends_it_off_site(): void
    {
        Config::set('backup.encryption.key', 'test-encryption-key-123456');
        $path = $this->work.'/now.sqlite';
        File::put($path, 'sqlite backup contents');
        Config::set('database.connections.r2_now', ['driver' => 'sqlite', 'database' => $path]);
        Config::set('backup.app.database_connection', 'r2_now');
        $this->r2Settings();
        $s3 = $this->r2();
        $this->actingAs($this->admin());

        Livewire::test(R2Backups::class)
            ->callAction('backupToR2')
            ->assertNotified('Backup sent off-site');

        $this->assertCount(1, array_filter(array_keys($s3->objects), fn (string $key): bool => str_ends_with($key, '-manual.sqlite.enc')));
        $this->assertSame('r2', BackupRun::query()->sole()->offsite_to);
    }

    public function test_back_up_now_is_not_offered_until_r2_is_set_up(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(R2Backups::class)->assertActionDisabled('backupToR2');

        $this->r2Settings();
        $this->r2();

        Livewire::test(R2Backups::class)->assertActionEnabled('backupToR2');
    }

    public function test_the_backups_page_points_to_the_r2_page(): void
    {
        $this->actingAs($this->admin())->get('/admin/settings/backups')
            ->assertOk()
            ->assertSee('Cloudflare R2')
            ->assertSee('Open R2 Backups')
            ->assertSee('Not configured');

        $this->r2Settings();

        $this->actingAs($this->admin())->get('/admin/settings/backups')
            ->assertOk()
            ->assertSee('Configured');
    }
}
