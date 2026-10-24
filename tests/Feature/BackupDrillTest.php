<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\DeploymentError;
use App\Services\BackupEncryptor;
use App\Services\BackupRestoreDrill;
use App\Services\BackupRunRecorder;
use App\Services\BackupSettingsService;
use App\Services\DatabaseBackupService;
use App\Services\GoogleDriveBackupService;
use App\Services\OffsiteBackupService;
use App\Services\R2BackupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakeS3;
use Tests\TestCase;

class BackupDrillTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-encryption-key-123456';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('backup.drill.enabled', true);
        Config::set('database.connections.backup_drill', [
            'driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'database' => null,
            'username' => 'drill', 'password' => 'p"a$$', 'unix_socket' => '',
        ]);
    }

    /** A gzip mysqldump with $tables tables, a migrations table and the closing line (unless $complete is false). */
    private function dumpContents(int $tables = 3, bool $complete = true, string $filler = ''): string
    {
        $sql = "-- MySQL dump\n";

        foreach (range(1, $tables - 1) as $i) {
            $sql .= "CREATE TABLE `table_{$i}` (\n  `id` bigint NOT NULL\n);\n";
        }

        $sql .= "CREATE TABLE `migrations` (\n  `id` int NOT NULL\n);\n".$filler;

        return (string) gzencode($sql.($complete ? "-- Dump completed on 2026-10-23\n" : ''));
    }

    private function putDump(string $name, ?string $contents = null, ?int $age = null): string
    {
        $path = DatabaseBackupService::DIRECTORY.'/'.$name;
        Storage::disk('local')->put($path, $contents ?? $this->dumpContents());

        if ($age !== null) {
            touch(Storage::disk('local')->path($path), now()->subMinutes($age)->timestamp);
        }

        return Storage::disk('local')->path($path);
    }

    /** A drill whose scratch database step is replaced, so it needs no MySQL server. */
    private function drill(?\Closure $restore = null): BackupRestoreDrill
    {
        $drill = new class(app(DatabaseBackupService::class), app(OffsiteBackupService::class), app(BackupRunRecorder::class)) extends BackupRestoreDrill
        {
            /** @var array<int, array{path: string, tables: int, contents: string}> */
            public array $restored = [];

            public ?\Closure $restore = null;

            protected function restoreIntoScratch(string $path, int $expectedTables): string
            {
                $this->restored[] = ['path' => $path, 'tables' => $expectedTables, 'contents' => (string) gzdecode((string) file_get_contents($path))];

                if ($this->restore) {
                    return ($this->restore)($path, $expectedTables);
                }

                return "restored into a scratch database in 1 s: {$expectedTables} tables, 5 migrations (latest 2026_01_01_000000_x).";
            }
        };
        $drill->restore = $restore;

        return $drill;
    }

    private function fakeDrive(): GoogleDriveBackupService
    {
        $drive = new class extends GoogleDriveBackupService
        {
            /** @var array<int, array{id: string, name: string}> */
            public array $remote = [];

            /** @var array<string, string> id => file bytes */
            public array $files = [];

            /** A stand-in that is always set up. */
            public function assertReady(): void {}

            public function listBackupFiles(): array
            {
                return $this->remote;
            }

            public function download(string $fileId, string $destination): void
            {
                file_put_contents($destination, $this->files[$fileId]);
            }
        };
        $this->app->instance(GoogleDriveBackupService::class, $drive);

        return $drive;
    }

    public function test_it_refuses_to_run_until_a_drill_user_is_configured_and_records_nothing(): void
    {
        Config::set('backup.drill.enabled', false);

        $this->expectExceptionMessage('BACKUP_DRILL_DB_USERNAME');

        try {
            $this->drill()->run();
        } finally {
            $this->assertSame(0, BackupRun::query()->count());
        }
    }

    public function test_it_restores_the_newest_dump_and_records_what_it_found(): void
    {
        $this->putDump('database-backup-20261023-100500-mysql-hourly.sql.gz', null, 120);
        $newest = $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz', null, 10);
        // Not a compressed dump: never picked even when newer.
        $this->putDump('database-backup-20261023-115900-sqlite-manual.sqlite', 'SQLite format 3', 1);
        $drill = $this->drill();

        $result = $drill->run();

        $this->assertSame('database-backup-20261023-110500-mysql-hourly.sql.gz', $result['name']);
        $this->assertSame([$newest], array_column($drill->restored, 'path'));
        $this->assertSame(3, $drill->restored[0]['tables']);
        $run = BackupRun::query()->sole();
        $this->assertSame(['drill', 'local', 'success'], [$run->scope, $run->kind, $run->status]);
        $this->assertSame('database-backup-20261023-110500-mysql-hourly.sql.gz', $run->filename);
        $this->assertStringContainsString('3 tables, 5 migrations', (string) $run->note);
        $this->assertSame(filesize($newest), $run->size_bytes);
    }

    public function test_a_named_file_is_tested_instead_and_must_be_a_compressed_dump(): void
    {
        $this->putDump('database-backup-20261023-100500-mysql-hourly.sql.gz', null, 120);
        $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz', null, 10);
        $drill = $this->drill();

        $drill->run('database-backup-20261023-100500-mysql-hourly.sql.gz');

        $this->assertSame('database-backup-20261023-100500-mysql-hourly.sql.gz', basename($drill->restored[0]['path']));

        $this->putDump('database-backup-20261023-120000-sqlite-manual.sqlite', 'SQLite format 3');

        foreach (['database-backup-20261023-120000-sqlite-manual.sqlite', 'missing.sql.gz'] as $bad) {
            try {
                $drill->run($bad);
                $this->fail("{$bad} must be refused.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('is not a compressed MySQL dump', $exception->getMessage());
            }
        }

        $this->assertCount(1, $drill->restored);
    }

    public function test_an_empty_backup_folder_is_a_failed_and_alerted_test(): void
    {
        try {
            $this->drill()->run();
            $this->fail('Nothing to test must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no compressed MySQL dump', $exception->getMessage());
        }

        $this->assertSame('failed', BackupRun::query()->sole()->status);
        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:drill:local')->count());
    }

    public function test_a_cut_short_dump_fails_before_the_scratch_database_is_touched(): void
    {
        $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz', $this->dumpContents(complete: false));
        $drill = $this->drill();

        try {
            $drill->run();
            $this->fail('An incomplete dump must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('incomplete', $exception->getMessage());
        }

        $this->assertSame([], $drill->restored);
        $this->assertSame('failed', BackupRun::query()->sole()->status);
    }

    public function test_a_dump_that_fails_to_load_is_recorded_with_the_reason_and_alerts_once(): void
    {
        $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz');
        $drill = $this->drill(fn () => throw new RuntimeException('Loading the dump failed: ERROR 1064 (42000) at line 12'));

        foreach ([1, 2] as $_) {
            try {
                $drill->run();
                $this->fail('A broken load must fail.');
            } catch (RuntimeException) {
            }
        }

        $this->assertSame(2, BackupRun::query()->where('status', 'failed')->count());
        $this->assertStringContainsString('ERROR 1064', (string) BackupRun::query()->latest('id')->value('error'));
        // Like every other backup failure: the first of a row alerts, the repeat does not.
        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:drill:local')->count());
    }

    public function test_the_drive_copy_is_downloaded_decrypted_tested_and_the_staging_file_removed(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        $drive = $this->fakeDrive();

        // Build the "Drive" file the way the app uploads one.
        $plain = $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz', $this->dumpContents(tables: 4));
        app(BackupEncryptor::class)->encryptFile($plain, storage_path('framework/testing/drill-drive.enc'));
        $drive->files = ['f2' => (string) file_get_contents(storage_path('framework/testing/drill-drive.enc')), 'f1' => 'older, never downloaded'];
        $drive->remote = [
            ['id' => 'f1', 'name' => 'database-backup-20261023-100500-mysql-hourly.sql.gz.enc'],
            ['id' => 'f2', 'name' => 'database-backup-20261023-110500-mysql-hourly.sql.gz.enc'],
            ['id' => 'x', 'name' => 'app-backup-20261023-020000-daily.zip.enc'],
            ['id' => 'y', 'name' => 'holiday.sql.gz'],
        ];
        // The local folder is emptied: only the Drive copy exists, as after losing the server.
        Storage::disk('local')->delete(DatabaseBackupService::DIRECTORY.'/database-backup-20261023-110500-mysql-hourly.sql.gz');
        $drill = $this->drill();

        $result = $drill->run(offsite: 'drive');

        $this->assertSame('database-backup-20261023-110500-mysql-hourly.sql.gz', $result['name']);
        $this->assertSame(4, $drill->restored[0]['tables']);
        $this->assertStringContainsString('CREATE TABLE `migrations`', $drill->restored[0]['contents']);
        $this->assertSame([], Storage::disk('local')->files('backups/tmp'));
        $run = BackupRun::query()->sole();
        $this->assertSame(['drill', 'drive', 'success'], [$run->scope, $run->kind, $run->status]);
    }

    public function test_the_r2_copy_is_downloaded_decrypted_tested_and_recorded_as_an_r2_test(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        app(BackupSettingsService::class)->saveR2([
            'enabled' => true, 'account' => '0123456789abcdef0123456789abcdef', 'bucket' => 'b', 'prefix' => 'erp-backups',
            'access_key_id' => 'k', 'secret_access_key' => 's',
        ]);
        $s3 = new FakeS3;
        $this->app->instance(R2BackupService::class, app(R2BackupService::class)->withClient($s3->client()));

        $plain = $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz', $this->dumpContents(tables: 4));
        app(BackupEncryptor::class)->encryptFile($plain, storage_path('framework/testing/drill-r2.enc'));
        $s3->put('erp-backups/database-backup-20261023-110500-mysql-hourly.sql.gz.enc', (string) file_get_contents(storage_path('framework/testing/drill-r2.enc')));
        $s3->put('erp-backups/database-backup-20261023-100500-mysql-hourly.sql.gz.enc', 'older, never downloaded');
        Storage::disk('local')->delete(DatabaseBackupService::DIRECTORY.'/database-backup-20261023-110500-mysql-hourly.sql.gz');
        $drill = $this->drill();

        $result = $drill->run(offsite: 'r2');

        $this->assertSame('database-backup-20261023-110500-mysql-hourly.sql.gz', $result['name']);
        $this->assertSame(4, $drill->restored[0]['tables']);
        $this->assertSame(1, $s3->count('GetObject'), 'Only the newest copy is downloaded.');
        $this->assertSame([], Storage::disk('local')->files('backups/tmp'));
        $run = BackupRun::query()->sole();
        $this->assertSame(['drill', 'r2', 'success'], [$run->scope, $run->kind, $run->status]);

        // The command: --r2 reads R2, and an empty bucket is a recorded, alerted failure.
        $s3->objects = [];
        $this->artisan('backup:drill', ['--r2' => true])
            ->expectsOutputToContain('No database copy was found on Cloudflare R2.')
            ->assertExitCode(1);
        $this->assertSame('r2', BackupRun::query()->latest('id')->first()->kind);
    }

    public function test_the_drive_copy_fails_with_a_wrong_key_nothing_on_drive_or_no_key(): void
    {
        $drive = $this->fakeDrive();
        $plain = $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz');
        app(BackupEncryptor::class)->encryptFile($plain, storage_path('framework/testing/drill-drive.enc'), self::KEY);
        $drive->files = ['f2' => (string) file_get_contents(storage_path('framework/testing/drill-drive.enc'))];
        $drive->remote = [['id' => 'f2', 'name' => 'database-backup-20261023-110500-mysql-hourly.sql.gz.enc']];
        $drill = $this->drill();

        Config::set('backup.encryption.key', 'some-other-key-1234567');
        try {
            $drill->run(offsite: 'drive');
            $this->fail('A wrong key must fail the test of the Drive copy.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('wrong key', $exception->getMessage());
        }

        Config::set('backup.encryption.key', null);
        try {
            $drill->run(offsite: 'drive');
            $this->fail('A missing key must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('BACKUP_ENCRYPTION_KEY is not set', $exception->getMessage());
        }

        $drive->remote = [];
        try {
            $drill->run(offsite: 'drive');
            $this->fail('An empty Drive folder must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No database copy was found on Google Drive', $exception->getMessage());
        }

        $this->assertSame([], $drill->restored);
        $this->assertSame([], Storage::disk('local')->files('backups/tmp'));
        $this->assertSame(3, BackupRun::query()->where('scope', 'drill')->where('kind', 'drive')->where('status', 'failed')->count());
    }

    public function test_the_command_reports_the_result_and_a_missing_setup(): void
    {
        $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz');
        $this->app->instance(BackupRestoreDrill::class, $this->drill());

        $this->artisan('backup:drill')
            ->expectsOutputToContain('Restore test passed: database-backup-20261023-110500-mysql-hourly.sql.gz')
            ->expectsOutputToContain('3 tables, 5 migrations')
            ->assertExitCode(0);

        Config::set('backup.drill.enabled', false);
        $this->artisan('backup:drill')
            ->expectsOutputToContain('Restore test failed')
            ->assertExitCode(1);
    }

    private function scheduledEvent(string $needle): ?object
    {
        return collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, $needle));
    }

    public function test_the_restore_test_is_not_scheduled_until_a_drill_user_is_set_up(): void
    {
        Config::set('backup.drill.enabled', false);

        $this->artisan('schedule:list')->assertSuccessful();

        $this->assertNull($this->scheduledEvent('backup:drill'));
    }

    public function test_the_restore_test_runs_on_sundays_and_each_off_site_copy_is_tested_only_with_its_place_on(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('backup:drill')->assertSuccessful();

        $local = $this->scheduledEvent('backup:drill');
        $r2 = $this->scheduledEvent('backup:drill --r2');
        $drive = $this->scheduledEvent('backup:drill --drive');

        $this->assertSame('30 3 * * 0', $local->expression);
        $this->assertTrue($local->withoutOverlapping);
        $this->assertTrue($local->runInBackground);
        $this->assertTrue($local->onOneServer);
        $this->assertSame('50 3 * * 0', $r2->expression, 'twenty minutes after the local one');
        $this->assertSame('10 4 * * 0', $drive->expression, 'forty minutes after the local one');

        // The "when" test runs at fire time, so a place switched on later is picked up.
        $this->assertFalse($r2->filtersPass($this->app));
        $this->assertFalse($drive->filtersPass($this->app));

        app(BackupSettingsService::class)->saveGoogleDrive([
            'enabled' => true, 'auto_upload' => true, 'folder_id' => 'folder', 'service_account_path' => '', 'service_account_json' => '',
        ]);
        $this->assertTrue($drive->filtersPass($this->app));
        $this->assertFalse($r2->filtersPass($this->app), 'Drive on does not schedule a test of the R2 copy.');

        app(BackupSettingsService::class)->saveR2(['enabled' => true, 'auto_upload' => true]);
        $this->assertTrue($r2->filtersPass($this->app));
    }

    // --- the loading step, with a stand-in for the mysql client ----------

    /** @return array{0: BackupRestoreDrill, 1: string} the drill (load() made public) and the folder the stand-in writes to */
    private function loadingDrill(string $script): array
    {
        $directory = storage_path('framework/testing/drill-load-'.str()->random(6));
        File::ensureDirectoryExists($directory);
        File::put($directory.'/mysql', str_replace('OUT', $directory, $script));
        chmod($directory.'/mysql', 0755);
        Config::set('backup.drill.mysql_binary', $directory.'/mysql');

        $drill = new class(app(DatabaseBackupService::class), app(OffsiteBackupService::class), app(BackupRunRecorder::class)) extends BackupRestoreDrill
        {
            public function loadDump(array $config, string $scratch, string $path): void
            {
                $this->load($config, $scratch, $path);
            }
        };

        return [$drill, $directory];
    }

    public function test_the_dump_is_streamed_into_the_mysql_client_and_the_password_stays_off_the_command_line(): void
    {
        [$drill, $directory] = $this->loadingDrill("#!/bin/sh\necho \"\$@\" > OUT/args\ncp \"\$(echo \"\$1\" | sed 's/--defaults-extra-file=//')\" OUT/option-file\ncat > OUT/stdin\n");
        // About 3 MB, so it crosses several 1 MiB reads.
        $filler = str_repeat("INSERT INTO `table_1` VALUES (1, 'বাংলা টেক্সট');\n", 60000);
        $path = $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz', $this->dumpContents(filler: $filler));
        $expected = (string) gzdecode((string) file_get_contents($path));

        $drill->loadDump((array) config('database.connections.backup_drill'), 'backup_drill_20261023110500_abc123', $path);

        $this->assertGreaterThan(2_000_000, strlen($expected));
        $this->assertSame(hash('sha256', $expected), hash_file('sha256', $directory.'/stdin'));
        $args = trim((string) file_get_contents($directory.'/args'));
        $this->assertStringContainsString('backup_drill_20261023110500_abc123', $args);
        $this->assertStringNotContainsString('a$$', $args);
        $this->assertStringContainsString('p\\"a$$', (string) file_get_contents($directory.'/option-file'));
        // The option file with the password is gone once the load is over.
        $this->assertSame(1, preg_match('/--defaults-extra-file=(\S+)/', $args, $match));
        $this->assertFileDoesNotExist($match[1]);
        File::deleteDirectory($directory);
    }

    public function test_a_failing_mysql_client_gives_its_error_message(): void
    {
        [$drill, $directory] = $this->loadingDrill("#!/bin/sh\ncat > /dev/null\necho 'ERROR 1064 (42000) at line 3: boom' >&2\nexit 1\n");
        $path = $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz');

        try {
            $drill->loadDump((array) config('database.connections.backup_drill'), 'backup_drill_20261023110500_abc123', $path);
            $this->fail('A failing client must fail the load.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Loading the dump failed: ERROR 1064 (42000) at line 3: boom', $exception->getMessage());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_a_missing_mysql_client_says_how_to_fix_it(): void
    {
        [$drill, $directory] = $this->loadingDrill("#!/bin/sh\n");
        Config::set('backup.drill.mysql_binary', $directory.'/not-installed');
        $path = $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz');

        try {
            $drill->loadDump((array) config('database.connections.backup_drill'), 'backup_drill_20261023110500_abc123', $path);
            $this->fail('A missing client must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('BACKUP_MYSQL_BINARY', $exception->getMessage());
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
