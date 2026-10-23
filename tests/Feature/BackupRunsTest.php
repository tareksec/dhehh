<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\DeploymentError;
use App\Models\User;
use App\Services\AppBackupService;
use App\Services\BackupRunRecorder;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class BackupRunsTest extends TestCase
{
    use RefreshDatabase;

    private function sqliteConnection(string $name = 'backup_test'): void
    {
        $path = storage_path("framework/testing/{$name}.sqlite");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'sqlite backup contents');
        Config::set("database.connections.{$name}", ['driver' => 'sqlite', 'database' => $path]);
    }

    private function mysqlConnection(): void
    {
        Config::set('database.connections.mysql_test', [
            'driver' => 'mysql',
            'host' => 'db.internal',
            'port' => 3307,
            'database' => 'erp',
            'username' => 'erp_user',
            'password' => 'p"w\\d#1',
        ]);
        // Any executable file passes the "is mysqldump installed" check.
        Config::set('backup.database.mysqldump_binary', PHP_BINARY);
    }

    /**
     * A DatabaseBackupService whose mysqldump is a stand-in that prints $stdout.
     * It records what the real one would have been given.
     */
    private function dumper(string $stdout, string $stderr = '', int $exitCode = 0): DatabaseBackupService
    {
        return new class(app(BackupRunRecorder::class), $stdout, $stderr, $exitCode) extends DatabaseBackupService
        {
            /** @var array<int, string> */
            public array $command = [];

            public string $optionFile = '';

            public string $optionFileMode = '';

            public function __construct(BackupRunRecorder $runs, private string $stdout, private string $stderr, private int $exitCode)
            {
                parent::__construct($runs);
            }

            protected function runDump(array $command, callable $onOutput, callable $onError): int
            {
                $this->command = $command;
                $path = substr($command[1], strlen('--defaults-extra-file='));
                $this->optionFile = (string) file_get_contents($path);
                $this->optionFileMode = substr(sprintf('%o', fileperms($path)), -4);

                // Hand the output over in small pieces, as a real process would.
                foreach (str_split($this->stdout, 7) as $piece) {
                    $onOutput($piece);
                }

                if ($this->stderr !== '') {
                    $onError($this->stderr);
                }

                return $this->exitCode;
            }
        };
    }

    private function completeDump(): string
    {
        return "-- Server version 8.4\nCREATE TABLE `migrations` (\n  `id` int\n);\nCREATE TABLE `users` (\n  `id` int\n);\n-- Dump completed on 2026-10-23  2:00:00\n";
    }

    public function test_backup_file_name_carries_its_kind(): void
    {
        Storage::fake('local');
        $this->sqliteConnection();

        $backup = app(DatabaseBackupService::class)->create('backup_test', BackupRun::KIND_HOURLY);

        $this->assertMatchesRegularExpression('/^database-backup-\d{8}-\d{6}-backup-test-hourly\.sqlite$/', $backup['name']);
        $this->assertSame('hourly', $backup['kind']);
        $this->assertSame('hourly', DatabaseBackupService::kindOf($backup['name']));
        $this->assertSame('manual', DatabaseBackupService::kindOf('database-backup-20260613-120000-testing.sqlite'));
        $this->assertSame('daily', DatabaseBackupService::kindOf('database-backup-20261023-020000-mysql-daily.sql.gz'));
    }

    public function test_unknown_backup_kind_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(DatabaseBackupService::class)->create('backup_test', 'weekly');
    }

    public function test_each_kind_is_pruned_on_its_own_so_hourly_files_never_push_out_daily_ones(): void
    {
        Storage::fake('local');
        Config::set('backup.database.keep', ['hourly' => 3, 'daily' => 2, 'predeploy' => 2, 'manual' => 2]);
        $disk = Storage::disk('local');

        $put = function (string $kind, int $index, int $minutesAgo) use ($disk): void {
            $path = DatabaseBackupService::DIRECTORY."/database-backup-20261023-1{$index}0000-mysql-{$kind}.sql.gz";
            $disk->put($path, "{$kind} {$index}");
            touch($disk->path($path), now()->subMinutes($minutesAgo)->timestamp);
        };

        foreach (range(1, 6) as $i) {
            $put('hourly', $i, 60 - $i);
        }
        foreach (range(1, 3) as $i) {
            $put('daily', $i, 5000 - $i);
        }
        $put('predeploy', 1, 9000);
        $put('manual', 1, 9500);

        app(DatabaseBackupService::class)->prune();

        $names = collect(app(DatabaseBackupService::class)->all())->groupBy('kind')->map->pluck('name');

        $this->assertCount(3, $names['hourly']);
        $this->assertCount(2, $names['daily']);
        $this->assertCount(1, $names['predeploy']);
        $this->assertCount(1, $names['manual']);
        // The newest hourly files survive, and the oldest daily file is the one dropped.
        $this->assertTrue($names['hourly']->contains('database-backup-20261023-160000-mysql-hourly.sql.gz'));
        $this->assertFalse($names['daily']->contains('database-backup-20261023-110000-mysql-daily.sql.gz'));
    }

    public function test_a_successful_backup_leaves_a_run_with_its_checksum(): void
    {
        Storage::fake('local');
        $this->sqliteConnection();

        $backup = app(DatabaseBackupService::class)->create('backup_test', BackupRun::KIND_DAILY);

        $run = BackupRun::query()->sole();

        $this->assertSame('database', $run->scope);
        $this->assertSame('daily', $run->kind);
        $this->assertSame('success', $run->status);
        $this->assertSame($backup['name'], $run->filename);
        $this->assertSame(hash('sha256', 'sqlite backup contents'), $run->sha256);
        $this->assertSame($backup['size'], $run->size_bytes);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_failed_backup_is_recorded_with_the_reason_and_rethrown(): void
    {
        Storage::fake('local');
        Config::set('database.connections.backup_test', ['driver' => 'sqlite', 'database' => '/nonexistent/db.sqlite']);

        try {
            app(DatabaseBackupService::class)->create('backup_test', BackupRun::KIND_HOURLY);
            $this->fail('The failure should have been thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('could not be found', $exception->getMessage());
        }

        $run = BackupRun::query()->sole();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('could not be found', $run->error);
        $this->assertCount(0, app(DatabaseBackupService::class)->all());
    }

    public function test_only_the_first_failure_in_a_row_of_a_scheduled_backup_alerts_the_super_admins(): void
    {
        Storage::fake('local');
        Config::set('database.connections.backup_test', ['driver' => 'sqlite', 'database' => '/nonexistent/db.sqlite']);
        $service = app(DatabaseBackupService::class);
        $attempt = function (string $kind) use ($service): void {
            try {
                $service->create('backup_test', $kind);
            } catch (RuntimeException) {
            }
        };

        $attempt(BackupRun::KIND_HOURLY);
        $attempt(BackupRun::KIND_HOURLY);
        $attempt(BackupRun::KIND_HOURLY);

        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:database:hourly')->count());

        // A person pressing the button sees the failure on screen; it is not logged as a deploy error.
        $attempt(BackupRun::KIND_MANUAL);
        $this->assertSame(0, DeploymentError::query()->where('source', 'backup:database:manual')->count());

        // After a good run, the next failure is reported again.
        $this->sqliteConnection();
        $service->create('backup_test', BackupRun::KIND_HOURLY);
        Config::set('database.connections.backup_test', ['driver' => 'sqlite', 'database' => '/nonexistent/db.sqlite']);
        $attempt(BackupRun::KIND_HOURLY);

        $this->assertSame(2, DeploymentError::query()->where('source', 'backup:database:hourly')->count());
    }

    public function test_a_backup_still_works_before_the_runs_table_exists(): void
    {
        Storage::fake('local');
        $this->sqliteConnection();
        Schema::drop('backup_runs');

        $backup = app(DatabaseBackupService::class)->create('backup_test', BackupRun::KIND_PREDEPLOY);

        Storage::disk('local')->assertExists($backup['relative_path']);
    }

    public function test_a_run_that_never_finished_is_closed_when_the_next_one_starts(): void
    {
        Storage::fake('local');
        $this->sqliteConnection();
        $stale = BackupRun::query()->create([
            'scope' => 'database',
            'kind' => 'hourly',
            'status' => 'running',
            'started_at' => now()->subHours(3),
        ]);
        $recent = BackupRun::query()->create([
            'scope' => 'database',
            'kind' => 'hourly',
            'status' => 'running',
            'started_at' => now()->subMinutes(2),
        ]);

        app(DatabaseBackupService::class)->create('backup_test', BackupRun::KIND_HOURLY);

        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertStringContainsString('Interrupted', $stale->fresh()->error);
        $this->assertSame('running', $recent->fresh()->status);
    }

    public function test_mysql_dump_is_gzipped_atomically_and_the_password_stays_off_the_command_line(): void
    {
        Storage::fake('local');
        $this->mysqlConnection();
        $service = $this->dumper($this->completeDump());

        $backup = $service->create('mysql_test', BackupRun::KIND_HOURLY);

        $this->assertStringEndsWith('-mysql-test-hourly.sql.gz', $backup['name']);
        $this->assertSame($this->completeDump(), gzdecode(File::get($backup['path'])));
        $this->assertSame(hash_file('sha256', $backup['path']), $backup['sha256']);
        $this->assertSame([], glob(dirname($backup['path']).'/*.part'));

        $this->assertStringStartsWith('--defaults-extra-file=', $service->command[1]);
        $this->assertContains('--single-transaction', $service->command);
        $this->assertContains('--no-tablespaces', $service->command);
        $this->assertContains('--default-character-set=utf8mb4', $service->command);
        $this->assertSame('erp', end($service->command));
        $this->assertStringNotContainsString('p"w', implode(' ', $service->command));
        $this->assertStringContainsString('password="p\\"w\\\\d#1"', $service->optionFile);
        $this->assertStringContainsString('host="db.internal"', $service->optionFile);
        $this->assertStringContainsString('port=3307', $service->optionFile);
        $this->assertSame('0600', $service->optionFileMode);
        $this->assertSame([], glob(sys_get_temp_dir().'/dbbackup*'));
    }

    public function test_a_dump_that_stops_early_never_becomes_a_backup(): void
    {
        Storage::fake('local');
        $this->mysqlConnection();

        try {
            $this->dumper("-- Server version 8.4\nCREATE TABLE `migrations` (\n")->create('mysql_test', BackupRun::KIND_DAILY);
            $this->fail('An incomplete dump must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('stopped before the dump was complete', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->files(DatabaseBackupService::DIRECTORY));
        $this->assertSame('failed', BackupRun::query()->sole()->status);
    }

    public function test_a_failing_mysqldump_reports_its_own_message(): void
    {
        Storage::fake('local');
        $this->mysqlConnection();

        try {
            $this->dumper('', "mysqldump: Got error: 1045: Access denied\n", 2)->create('mysql_test', BackupRun::KIND_MANUAL);
            $this->fail('A failed dump must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Access denied', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->files(DatabaseBackupService::DIRECTORY));
    }

    public function test_a_missing_mysqldump_says_how_to_fix_it(): void
    {
        Storage::fake('local');
        $this->mysqlConnection();
        Config::set('backup.database.mysqldump_binary', 'definitely-not-installed-mysqldump');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Install the MySQL client tools');

        app(DatabaseBackupService::class)->create('mysql_test', BackupRun::KIND_MANUAL);
    }

    public function test_dump_output_is_streamed_from_a_real_process(): void
    {
        $service = new class(app(BackupRunRecorder::class)) extends DatabaseBackupService
        {
            public function stream(array $command, callable $onOutput, callable $onError): int
            {
                return $this->runDump($command, $onOutput, $onError);
            }
        };
        $out = '';
        $err = '';

        $exit = $service->stream(
            [PHP_BINARY, '-r', 'echo "a-line\n"; fwrite(STDERR, "a-warning"); exit(3);'],
            function (string $chunk) use (&$out): void {
                $out .= $chunk;
            },
            function (string $chunk) use (&$err): void {
                $err .= $chunk;
            },
        );

        $this->assertSame(3, $exit);
        $this->assertSame("a-line\n", $out);
        $this->assertSame('a-warning', $err);
    }

    public function test_verify_accepts_a_complete_dump_and_checks_the_recorded_checksum(): void
    {
        Storage::fake('local');
        $this->mysqlConnection();
        $service = $this->dumper($this->completeDump());
        $backup = $service->create('mysql_test', BackupRun::KIND_DAILY);

        $result = $service->verifyRestore($backup['name']);

        $this->assertSame('ok', $result['integrity']);
        $this->assertSame(2, $result['table_count']);
        $this->assertSame('verified', $result['checksum']);

        // The file changes after it was made (bit rot, a bad copy): the checksum catches it.
        File::put($backup['path'], gzencode($this->completeDump()."-- changed\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer matches the checksum');

        $service->verifyRestore($backup['name']);
    }

    public function test_verify_refuses_an_incomplete_dump_or_one_without_migrations(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $service = app(DatabaseBackupService::class);
        $disk->put(DatabaseBackupService::DIRECTORY.'/database-backup-20261023-010000-mysql-daily.sql.gz', gzencode("CREATE TABLE `migrations` (\n);\n"));
        $disk->put(DatabaseBackupService::DIRECTORY.'/database-backup-20261023-020000-mysql-daily.sql.gz', gzencode("CREATE TABLE `users` (\n);\n-- Dump completed on x\n"));

        try {
            $service->verifyRestore('database-backup-20261023-010000-mysql-daily.sql.gz');
            $this->fail('An incomplete dump must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('incomplete', $exception->getMessage());
        }

        $this->expectExceptionMessage('does not contain the migrations table');

        $service->verifyRestore('database-backup-20261023-020000-mysql-daily.sql.gz');
    }

    public function test_backup_database_command_takes_a_kind_and_reports_failures_with_an_exit_code(): void
    {
        Storage::fake('local');
        $this->sqliteConnection();

        $this->artisan('backup:database', ['--connection' => 'backup_test', '--kind' => 'hourly'])
            ->expectsOutputToContain('Database backup created')
            ->assertExitCode(0);
        $this->assertSame('hourly', BackupRun::query()->sole()->kind);

        $this->artisan('backup:database', ['--kind' => 'weekly'])
            ->expectsOutputToContain('--kind must be one of')
            ->assertExitCode(1);

        Config::set('database.connections.broken', ['driver' => 'sqlite', 'database' => '/nonexistent/db.sqlite']);
        $this->artisan('backup:database', ['--connection' => 'broken'])
            ->expectsOutputToContain('Database backup failed')
            ->assertExitCode(1);
    }

    public function test_backup_verify_command_checks_a_compressed_dump(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(DatabaseBackupService::DIRECTORY.'/database-backup-20261023-020000-mysql-daily.sql.gz', gzencode($this->completeDump()));

        $this->artisan('backup:verify')
            ->expectsOutputToContain('Restore drill passed')
            ->assertExitCode(0);

        Storage::disk('local')->put(DatabaseBackupService::DIRECTORY.'/database-backup-20261023-030000-mysql-daily.sql.gz', gzencode("CREATE TABLE `migrations` (\n);\n"));
        touch(Storage::disk('local')->path(DatabaseBackupService::DIRECTORY.'/database-backup-20261023-030000-mysql-daily.sql.gz'), now()->addMinute()->timestamp);

        $this->artisan('backup:verify')
            ->expectsOutputToContain('incomplete')
            ->assertExitCode(1);
    }

    public function test_full_app_backup_leaves_an_app_run_and_a_database_run_of_the_same_kind(): void
    {
        Storage::fake('local');
        $this->sqliteConnection('app_backup_runs');
        Config::set('backup.app.database_connection', 'app_backup_runs');
        Config::set('backup.app.include_paths', []);

        $backup = app(AppBackupService::class)->create(kind: BackupRun::KIND_DAILY);

        $this->assertSame(hash_file('sha256', $backup['path']), $backup['sha256']);
        $this->assertEqualsCanonicalizing(
            ['app:daily:success', 'database:daily:success'],
            BackupRun::query()->get()->map(fn (BackupRun $run): string => "{$run->scope}:{$run->kind}:{$run->status}")->all(),
        );
    }

    public function test_daily_backup_is_scheduled_with_an_overlap_lock_that_expires(): void
    {
        // The schedule is defined when the console kernel boots.
        $this->artisan('schedule:list')->expectsOutputToContain('backup:database')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'backup:database'));

        $this->assertNotNull($event);
        $this->assertStringContainsString('--kind=daily', $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt);
    }

    public function test_production_image_installs_the_mysql_client_that_provides_mysqldump(): void
    {
        $this->assertStringContainsString('default-mysql-client', File::get(base_path('Dockerfile')));
    }

    public function test_backups_page_shows_the_history_with_the_reason_for_a_failure(): void
    {
        BackupRun::query()->create([
            'scope' => 'database',
            'kind' => 'hourly',
            'status' => 'failed',
            'error' => 'mysqldump: Got error: 1045 Access denied',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        BackupRun::query()->create([
            'scope' => 'app',
            'kind' => 'daily',
            'status' => 'success',
            'filename' => 'app-backup-20261023-020000.zip',
            'size_bytes' => 2048,
            'sha256' => str_repeat('ab', 32),
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->get('/admin/settings/backups')
            ->assertOk()
            ->assertSee('Backup history')
            ->assertSee('Access denied')
            ->assertSee('Failed')
            ->assertSee('app-backup-20261023-020000.zip')
            ->assertSee('SHA-256 abababababab');
    }
}
