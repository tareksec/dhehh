<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\DeploymentError;
use App\Models\User;
use App\Services\AppBackupService;
use App\Services\BackupEncryptor;
use App\Services\BackupRestoreDrill;
use App\Services\BackupRestoreException;
use App\Services\BackupRestorer;
use App\Services\BackupRunRecorder;
use App\Services\BackupSettingsService;
use App\Services\DatabaseBackupService;
use App\Services\GoogleDriveBackupService;
use App\Services\OffsiteBackupService;
use App\Services\R2BackupService;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\FakeS3;
use Tests\TestCase;
use Throwable;
use ZipArchive;

/**
 * backup:restore never runs against a real MySQL here: the steps that touch the database or the
 * machine (wipe, load, migrate, maintenance mode) are replaced by recorders so the ORDER of the
 * steps, the safety copy and the rollback can be checked. The wipe and the table counts are also
 * run for real on a throwaway SQLite file, and the loader against a stand-in `mysql` script.
 */
class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-encryption-key-123456';

    /** What the stand-ins did, in order. */
    private ArrayObject $log;

    private DatabaseBackupService $databases;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->log = new ArrayObject;
        $this->base = storage_path('framework/testing/restore-base-'.str()->random(6));
        File::ensureDirectoryExists($this->base);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        File::deleteDirectory(storage_path('framework/testing/restore-outside'));

        parent::tearDown();
    }

    // --- builders --------------------------------------------------------

    private function dumpContents(int $tables = 3, bool $complete = true): string
    {
        $sql = "-- MySQL dump\n";

        foreach (range(1, $tables - 1) as $i) {
            $sql .= "CREATE TABLE `table_{$i}` (\n  `id` bigint NOT NULL\n);\n";
        }

        $sql .= "CREATE TABLE `migrations` (\n  `id` int NOT NULL\n);\n";

        return (string) gzencode($sql.($complete ? "-- Dump completed on 2026-10-23\n" : ''));
    }

    private function putDump(string $name = 'database-backup-20261023-120500-mysql-hourly.sql.gz', ?string $contents = null): string
    {
        Storage::disk('local')->put(DatabaseBackupService::DIRECTORY.'/'.$name, $contents ?? $this->dumpContents());

        return Storage::disk('local')->path(DatabaseBackupService::DIRECTORY.'/'.$name);
    }

    /** @param  array<string, string>  $entries */
    private function putArchive(string $name, array $entries): string
    {
        $path = Storage::disk('local')->path('backups/app/'.$name);
        File::ensureDirectoryExists(dirname($path));

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }

        $zip->close();

        return $path;
    }

    private function newDatabases(): DatabaseBackupService
    {
        return new class($this->log, app(BackupRunRecorder::class), app(OffsiteBackupService::class)) extends DatabaseBackupService
        {
            public ?Throwable $createFails = null;

            /** @var ?\Closure(string, int): void */
            public ?\Closure $onLoad = null;

            public int $loads = 0;

            public function __construct(public ArrayObject $log, BackupRunRecorder $runs, OffsiteBackupService $offsite)
            {
                parent::__construct($runs, $offsite);
            }

            public function create(?string $connection = null, string $kind = BackupRun::KIND_MANUAL, bool $upload = false, bool $automatic = false): array
            {
                $this->log[] = "safety:{$connection}:{$kind}";

                if ($this->createFails) {
                    throw $this->createFails;
                }

                $name = "database-backup-20261023-130000-testdb-{$kind}.sql.gz";
                Storage::disk('local')->put(self::DIRECTORY.'/'.$name, (string) gzencode("-- safety copy\n"));

                return ['name' => $name, 'path' => Storage::disk('local')->path(self::DIRECTORY.'/'.$name)];
            }

            public function loadDump(array $config, string $database, string $path, string $binary = 'mysql', float $timeout = 1800): void
            {
                $this->loads++;
                $this->log[] = 'load:'.basename($path);

                if ($this->onLoad) {
                    ($this->onLoad)($path, $this->loads);
                }
            }
        };
    }

    /** A restorer whose database, maintenance mode and file base are stand-ins that write to the log. */
    private function restorer(?BackupRestoreDrill $drill = null): BackupRestorer
    {
        $this->databases = $this->newDatabases();
        $runs = app(BackupRunRecorder::class);
        $offsite = app(OffsiteBackupService::class);

        return new class($this->log, $this->base, $this->databases, new AppBackupService($this->databases, $offsite, $runs), $offsite, app(BackupEncryptor::class), $drill ?? app(BackupRestoreDrill::class), $runs) extends BackupRestorer
        {
            public bool $empty = false;

            public bool $down = false;

            public int $tables = 3;

            public ?Throwable $migrateFails = null;

            /** @var array<int, string> */
            public array $warnings = [];

            public function __construct(public ArrayObject $log, public string $base, ...$services)
            {
                parent::__construct(...$services);
            }

            protected function databaseTarget(): array
            {
                return ['testdb', ['driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'database' => 'erp', 'username' => 'erp', 'password' => 'secret']];
            }

            protected function hasTables(string $connection): bool
            {
                return ! $this->empty;
            }

            protected function wipe(string $connection): void
            {
                $this->log[] = 'wipe';
            }

            protected function summarise(string $connection): array
            {
                return ['tables' => $this->tables, 'migrations' => 5, 'latest' => '2026_01_01_000000_latest'];
            }

            protected function databaseWarnings(string $connection): array
            {
                return $this->warnings;
            }

            protected function migrate(): void
            {
                $this->log[] = 'migrate';

                if ($this->migrateFails) {
                    throw $this->migrateFails;
                }
            }

            protected function clearCaches(): void
            {
                $this->log[] = 'caches';
            }

            protected function isDown(): bool
            {
                return $this->down;
            }

            protected function enterMaintenance(): void
            {
                $this->log[] = 'down';
            }

            protected function leaveMaintenance(): void
            {
                $this->log[] = 'up';
            }

            protected function fileBase(): string
            {
                return $this->base;
            }
        };
    }

    /** @return array<int, string> */
    private function steps(): array
    {
        return $this->log->getArrayCopy();
    }

    private function restoreDatabase(BackupRestorer $restorer, array $source, bool $safety = true, bool $migrate = true, ?int $tables = 3): array
    {
        return $restorer->restore($source, true, false, $safety, $migrate, $tables, fn (string $message) => null);
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

    // --- finding and unpacking the file ----------------------------------

    public function test_a_dump_is_found_by_name_in_the_backup_folder_and_released_afterwards(): void
    {
        $path = $this->putDump();
        $restorer = $this->restorer();

        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        $this->assertSame(BackupRestorer::DUMP, $source['kind']);
        $this->assertSame($path, $source['path']);
        $this->assertSame($path, $source['dump']['path']);
        $this->assertSame('2026-10-23 12:05:00', $source['taken_at']->format('Y-m-d H:i:s'));
        $this->assertDirectoryExists($source['directory']);

        $restorer->release($source);

        $this->assertDirectoryDoesNotExist($source['directory']);
        $this->assertFileExists($path, 'Releasing the staging folder must never delete the backup itself.');
    }

    public function test_a_dump_can_be_given_by_path_from_anywhere(): void
    {
        $outside = $this->base.'/downloads/database-backup-20261023-090000-mysql-daily.sql.gz';
        File::ensureDirectoryExists(dirname($outside));
        File::put($outside, $this->dumpContents());

        $source = $this->restorer()->prepare($outside);

        $this->assertSame(realpath($outside), $source['path']);
        $this->assertSame('database-backup-20261023-090000-mysql-daily.sql.gz', $source['label']);
    }

    public function test_an_encrypted_copy_is_decrypted_with_the_given_key_and_a_wrong_key_leaves_nothing_behind(): void
    {
        $plain = $this->base.'/plain.sql.gz';
        $encrypted = $this->base.'/database-backup-20261023-120500-mysql-hourly.sql.gz.enc';
        File::put($plain, $this->dumpContents());
        app(BackupEncryptor::class)->encryptFile($plain, $encrypted, self::KEY);
        $restorer = $this->restorer();

        $source = $restorer->prepare($encrypted, key: self::KEY);

        $this->assertSame('database-backup-20261023-120500-mysql-hourly.sql.gz.enc', $source['label']);
        $this->assertSame('database-backup-20261023-120500-mysql-hourly.sql.gz', $source['name']);
        $this->assertSame(file_get_contents($plain), file_get_contents($source['path']));
        $restorer->release($source);

        try {
            $restorer->prepare($encrypted, key: 'a-different-key-9876543210');
            $this->fail('A wrong key must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('wrong key', $exception->getMessage());
        }

        $this->assertSame([], File::directories(Storage::disk('local')->path('backups/tmp')), 'A failed prepare removes its staging folder.');
    }

    public function test_the_newest_google_drive_copy_is_fetched_and_decrypted(): void
    {
        config(['backup.encryption.key' => self::KEY]);
        $drive = $this->fakeDrive();
        $plain = $this->base.'/plain.sql.gz';
        $encrypted = $this->base.'/copy.enc';
        File::put($plain, $this->dumpContents());
        app(BackupEncryptor::class)->encryptFile($plain, $encrypted, self::KEY);
        $drive->remote = [
            ['id' => 'old', 'name' => 'database-backup-20261022-120500-mysql-hourly.sql.gz.enc'],
            ['id' => 'new', 'name' => 'database-backup-20261023-120500-mysql-hourly.sql.gz.enc'],
            ['id' => 'app', 'name' => 'app-backup-20261023-020000-daily.zip.enc'],
            ['id' => 'other', 'name' => 'holiday-photos.zip'],
        ];
        $drive->files = ['old' => 'not used', 'new' => (string) file_get_contents($encrypted)];

        $restorer = $this->restorer();
        $source = $restorer->prepare(null, offsite: true);

        $this->assertSame('database-backup-20261023-120500-mysql-hourly.sql.gz.enc', $source['label']);
        $this->assertSame(file_get_contents($plain), file_get_contents($source['path']));
        $restorer->release($source);

        $drive->remote = [];
        $this->expectExceptionMessage('No database copy was found on Google Drive.');
        $restorer->prepare(null, offsite: true);
    }

    public function test_a_file_or_drive_not_both_and_not_neither(): void
    {
        $restorer = $this->restorer();

        foreach ([[null, false], ['x.sql.gz', true]] as [$file, $drive]) {
            try {
                $restorer->prepare($file, $drive);
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('--drive', $exception->getMessage());
            }
        }
    }

    public function test_anything_that_is_not_a_mysql_dump_or_an_app_archive_is_refused_with_a_pointer_to_the_docs(): void
    {
        $this->putDump('database-backup-20261023-120500-sqlite-daily.sqlite', 'sqlite contents');
        $restorer = $this->restorer();

        try {
            $restorer->prepare('database-backup-20261023-120500-sqlite-daily.sqlite');
            $this->fail('A SQLite file must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('docs/backup-restore.md', $exception->getMessage());
        }

        $this->expectExceptionMessage('was not found in the backup folders or as a file');
        $restorer->prepare('no-such-backup.sql.gz');
    }

    public function test_the_dump_inside_a_full_app_archive_is_taken_out_and_the_newest_one_is_used(): void
    {
        $dump = $this->dumpContents();
        $this->putArchive('app-backup-20261023-020000-daily.zip', [
            'database/database-backup-20261023-015900-mysql-daily.sql.gz' => 'older dump',
            'database/database-backup-20261023-020000-mysql-daily.sql.gz' => $dump,
            'database/migrations/2026_01_01_000000_x.php' => '<?php',
            'storage/app/public/a.txt' => 'a',
        ]);
        $restorer = $this->restorer();

        $source = $restorer->prepare('app-backup-20261023-020000-daily.zip');

        $this->assertSame(BackupRestorer::ARCHIVE, $source['kind']);
        $this->assertSame('database-backup-20261023-020000-mysql-daily.sql.gz', $source['dump']['name']);
        $this->assertSame($dump, file_get_contents($source['dump']['path']));

        $this->putArchive('app-backup-20261023-030000-daily.zip', ['storage/app/public/a.txt' => 'a']);
        $this->assertNull($restorer->prepare('app-backup-20261023-030000-daily.zip')['dump']);
    }

    public function test_unpacked_copies_left_by_a_killed_restore_are_removed_once_a_day_old(): void
    {
        $disk = Storage::disk('local');
        $disk->put('backups/tmp/restore-old/leftover.sql.gz', 'old');
        $disk->put('backups/tmp/restore-new/leftover.sql.gz', 'new');
        $disk->put('backups/tmp/other-folder/keep.txt', 'not ours');
        touch($disk->path('backups/tmp/restore-old'), now()->subDays(2)->timestamp);
        $this->putDump();

        $this->restorer()->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        $this->assertDirectoryDoesNotExist($disk->path('backups/tmp/restore-old'));
        $this->assertDirectoryExists($disk->path('backups/tmp/restore-new'));
        $this->assertDirectoryExists($disk->path('backups/tmp/other-folder'));
    }

    // --- checking it first -----------------------------------------------

    public function test_check_reads_the_dump_to_the_end_and_refuses_a_cut_off_one(): void
    {
        $restorer = $this->restorer();
        $this->putDump('database-backup-20261023-120500-mysql-hourly.sql.gz', $this->dumpContents(5));
        $this->putDump('database-backup-20261023-110500-mysql-hourly.sql.gz', $this->dumpContents(5, complete: false));

        $good = $restorer->check($restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz'));
        $this->assertSame(5, $good['tables']);
        $this->assertSame('not recorded', $good['checksum']);
        $this->assertNull($good['files']);

        $this->expectExceptionMessage('does not end with the "Dump completed" line');
        $restorer->check($restorer->prepare('database-backup-20261023-110500-mysql-hourly.sql.gz'));
    }

    public function test_check_refuses_a_dump_that_no_longer_matches_its_recorded_checksum(): void
    {
        $this->putDump();
        BackupRun::query()->create([
            'scope' => 'database', 'kind' => 'hourly', 'status' => 'success',
            'filename' => 'database-backup-20261023-120500-mysql-hourly.sql.gz',
            'sha256' => str_repeat('0', 64), 'started_at' => now(), 'finished_at' => now(),
        ]);
        $restorer = $this->restorer();

        $this->expectExceptionMessage('no longer matches the checksum');
        $restorer->check($restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz'));
    }

    public function test_check_counts_what_an_archive_would_copy_back_and_what_it_would_refuse(): void
    {
        $this->putArchive('app-backup-20261023-020000-daily.zip', [
            'database/database-backup-20261023-020000-mysql-daily.sql.gz' => $this->dumpContents(),
            'storage/app/public/products/a.png' => 'aaaa',
            'storage/app/private/kyc/b.pdf' => 'bb',
            'storage/app/private/backups/database/old.sql.gz' => 'a backup of a backup',
            'storage/app/private/livewire-tmp/t.tmp' => 'temporary',
            'app/Models/User.php' => '<?php',
            '.env' => 'APP_KEY=secret',
            '../evil.txt' => 'climbs out',
            'storage/app/public/../../../evil2.txt' => 'climbs out too',
            '/etc/cron.d/evil' => 'absolute',
        ]);
        $restorer = $this->restorer();

        $files = $restorer->check($restorer->prepare('app-backup-20261023-020000-daily.zip'))['files'];

        $this->assertSame(2, $files['restore']);
        $this->assertSame(6, $files['bytes']);
        $this->assertSame(5, $files['skipped'], 'The database folder, the code, the .env and the excluded folders are never restored.');
        $this->assertSame(3, $files['unsafe']);
    }

    public function test_a_scratch_database_test_is_offered_only_when_the_restore_test_is_set_up(): void
    {
        Config::set('backup.drill.enabled', false);
        $this->assertFalse($this->restorer()->canRehearse());

        Config::set('backup.drill.enabled', true);
        Config::set('database.connections.backup_drill', ['driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'database' => null, 'username' => 'drill', 'password' => 'x']);
        $drill = new class(app(DatabaseBackupService::class), app(OffsiteBackupService::class), app(BackupRunRecorder::class)) extends BackupRestoreDrill
        {
            /** @var array<int, array{string, int}> */
            public array $tried = [];

            protected function restoreIntoScratch(string $path, int $expectedTables): string
            {
                $this->tried[] = [basename($path), $expectedTables];

                return "restored into a scratch database in 1 s: {$expectedTables} tables";
            }
        };
        $restorer = $this->restorer($drill);
        $this->putDump();

        $this->assertTrue($restorer->canRehearse());
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        $this->assertSame('restored into a scratch database in 1 s: 3 tables', $restorer->rehearse($source, 3));
        $this->assertSame([['database-backup-20261023-120500-mysql-hourly.sql.gz', 3]], $drill->tried);
        $this->assertSame(0, BackupRun::query()->where('scope', 'drill')->count(), 'The rehearsal is part of the restore, not a restore test of its own.');
    }

    // --- replacing the database ------------------------------------------

    public function test_the_database_is_replaced_in_a_safe_order_and_the_restore_is_recorded(): void
    {
        $this->putDump();
        // The "running" row a dump always brings back with it.
        BackupRun::query()->create(['scope' => 'database', 'kind' => 'hourly', 'status' => 'running', 'started_at' => now()]);
        $restorer = $this->restorer();
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        $result = $this->restoreDatabase($restorer, $source);

        $this->assertSame([
            'safety:testdb:prerestore',
            'down',
            'wipe',
            'load:database-backup-20261023-120500-mysql-hourly.sql.gz',
            'migrate',
            'caches',
            'up',
        ], $this->steps());
        $this->assertSame(['tables' => 3, 'migrations' => 5, 'latest' => '2026_01_01_000000_latest', 'safety' => 'database-backup-20261023-130000-testdb-prerestore.sql.gz', 'migrated' => true], $result['database']);
        $this->assertSame('lifted', $result['maintenance']);

        $this->assertSame(0, BackupRun::query()->where('status', 'running')->count());
        $run = BackupRun::query()->where('scope', 'restore')->sole();
        $this->assertSame('database', $run->kind);
        $this->assertSame('success', $run->status);
        $this->assertSame('database-backup-20261023-120500-mysql-hourly.sql.gz', $run->filename);
        $this->assertStringContainsString('safety copy database-backup-20261023-130000-testdb-prerestore.sql.gz', (string) $run->note);
    }

    public function test_an_empty_database_needs_no_safety_copy_and_it_can_be_skipped(): void
    {
        $this->putDump();
        $restorer = $this->restorer();
        $restorer->empty = true;
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        $this->restoreDatabase($restorer, $source);
        $this->assertNotContains('safety:testdb:prerestore', $this->steps());

        $this->log->exchangeArray([]);
        $restorer->empty = false;
        $result = $this->restoreDatabase($restorer, $source, safety: false);

        $this->assertNotContains('safety:testdb:prerestore', $this->steps());
        $this->assertNull($result['database']['safety']);
    }

    public function test_pending_migrations_can_be_left_for_later(): void
    {
        $this->putDump();
        $restorer = $this->restorer();

        $result = $this->restoreDatabase($restorer, $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz'), migrate: false);

        $this->assertNotContains('migrate', $this->steps());
        $this->assertNull($result['database']['migrated']);
        $this->assertSame('lifted', $result['maintenance']);
    }

    public function test_if_the_safety_copy_cannot_be_made_nothing_is_touched(): void
    {
        $this->putDump();
        $restorer = $this->restorer();
        $this->databases->createFails = new RuntimeException('disk full');
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        try {
            $this->restoreDatabase($restorer, $source);
            $this->fail('Without a safety copy the restore must not start.');
        } catch (RuntimeException $exception) {
            $this->assertSame('disk full', $exception->getMessage());
        }

        $this->assertSame(['safety:testdb:prerestore'], $this->steps(), 'No maintenance mode, no wipe, no load.');
        $run = BackupRun::query()->where('scope', 'restore')->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('disk full', $run->error);
        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:restore')->count());
    }

    public function test_a_load_that_fails_puts_the_previous_database_back_and_reopens_the_app(): void
    {
        $this->putDump();
        $restorer = $this->restorer();
        $this->databases->onLoad = function (string $path, int $count): void {
            if ($count === 1) {
                throw new RuntimeException('ERROR 1064 at line 6997');
            }
        };
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        try {
            $this->restoreDatabase($restorer, $source);
            $this->fail('The load fails, so the restore must fail.');
        } catch (BackupRestoreException $exception) {
            $this->assertTrue($exception->rolledBack);
            $this->assertSame('database-backup-20261023-130000-testdb-prerestore.sql.gz', $exception->safetyFile);
            $this->assertStringContainsString('ERROR 1064 at line 6997', $exception->getMessage());
        }

        $this->assertSame([
            'safety:testdb:prerestore',
            'down',
            'wipe',
            'load:database-backup-20261023-120500-mysql-hourly.sql.gz',
            'wipe',
            'load:database-backup-20261023-130000-testdb-prerestore.sql.gz',
            'up',
        ], $this->steps());
        $run = BackupRun::query()->where('scope', 'restore')->sole();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('ERROR 1064', (string) $run->error);
        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:restore')->count());
    }

    public function test_when_putting_the_old_database_back_fails_too_the_app_stays_closed_and_names_the_copy(): void
    {
        $this->putDump();
        $restorer = $this->restorer();
        $this->databases->onLoad = function (string $path, int $count): void {
            throw new RuntimeException($count === 1 ? 'first load failed' : 'second load failed');
        };
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        try {
            $this->restoreDatabase($restorer, $source);
            $this->fail('Both loads fail.');
        } catch (BackupRestoreException $exception) {
            $this->assertFalse($exception->rolledBack);
            $this->assertSame('database-backup-20261023-130000-testdb-prerestore.sql.gz', $exception->safetyFile);
            $this->assertStringContainsString('first load failed', $exception->getMessage());
            $this->assertStringContainsString('second load failed', $exception->getMessage());
        }

        $this->assertNotContains('up', $this->steps());
    }

    public function test_without_a_safety_copy_a_failed_load_cannot_be_undone_and_says_so(): void
    {
        $this->putDump();
        $restorer = $this->restorer();
        $this->databases->onLoad = fn () => throw new RuntimeException('load failed');
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        try {
            $this->restoreDatabase($restorer, $source, safety: false);
            $this->fail('The load fails.');
        } catch (BackupRestoreException $exception) {
            $this->assertNull($exception->rolledBack);
            $this->assertNull($exception->safetyFile);
        }

        $this->assertSame(['down', 'wipe', 'load:database-backup-20261023-120500-mysql-hourly.sql.gz'], $this->steps());
    }

    public function test_a_restored_database_with_a_different_number_of_tables_is_rolled_back(): void
    {
        $this->putDump();
        $restorer = $this->restorer();
        $restorer->tables = 99;
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        try {
            $this->restoreDatabase($restorer, $source, tables: 3);
            $this->fail('99 tables is not what the dump defines.');
        } catch (BackupRestoreException $exception) {
            $this->assertTrue($exception->rolledBack);
            $this->assertStringContainsString('has 99 tables but the dump defines 3', $exception->getMessage());
        }
    }

    public function test_failed_migrations_leave_the_restored_data_in_place_but_keep_the_app_closed(): void
    {
        $this->putDump();
        $restorer = $this->restorer();
        $restorer->migrateFails = new RuntimeException('Table x already exists');
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        $result = $this->restoreDatabase($restorer, $source);

        $this->assertFalse($result['database']['migrated']);
        $this->assertSame('left_on', $result['maintenance']);
        $this->assertStringContainsString('Table x already exists', $result['warnings'][0]);
        $this->assertNotContains('up', $this->steps());
        $this->assertSame('success', BackupRun::query()->where('scope', 'restore')->sole()->status);
    }

    public function test_an_app_that_was_already_in_maintenance_mode_is_left_that_way(): void
    {
        $this->putDump();
        $restorer = $this->restorer();
        $restorer->down = true;
        $restorer->warnings = ['a warning from the restored database'];

        $result = $this->restoreDatabase($restorer, $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz'));

        $this->assertNotContains('down', $this->steps());
        $this->assertNotContains('up', $this->steps());
        $this->assertSame('unchanged', $result['maintenance']);
        $this->assertSame(['a warning from the restored database'], $result['warnings']);
    }

    public function test_two_restores_cannot_run_at_the_same_time(): void
    {
        $this->putDump();
        $restorer = $this->restorer();
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');
        $path = Storage::disk('local')->path('backups/restore.lock');
        File::ensureDirectoryExists(dirname($path));
        $other = fopen($path, 'c');
        flock($other, LOCK_EX);

        try {
            $this->restoreDatabase($restorer, $source);
            $this->fail('A second restore must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Another restore is running on this server.', $exception->getMessage());
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }

        $this->assertSame([], $this->steps());
    }

    public function test_a_restore_must_say_what_it_restores_and_the_source_must_hold_it(): void
    {
        $this->putDump();
        $this->putArchive('app-backup-20261023-030000-daily.zip', ['storage/app/public/a.txt' => 'a']);
        $restorer = $this->restorer();
        $dump = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');
        $archiveWithoutDump = $restorer->prepare('app-backup-20261023-030000-daily.zip');
        $progress = fn (string $message) => null;

        $cases = [
            'Choose what to restore' => fn () => $restorer->restore($dump, false, false, true, true, 3, $progress),
            'come from a full app archive' => fn () => $restorer->restore($dump, true, true, true, true, 3, $progress),
            'holds no database dump' => fn () => $restorer->restore($archiveWithoutDump, true, false, true, true, 3, $progress),
        ];

        foreach ($cases as $message => $call) {
            try {
                $call();
                $this->fail("Expected: {$message}");
            } catch (InvalidArgumentException|RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertSame([], $this->steps());
    }

    public function test_only_mysql_databases_are_replaced_and_sqlite_is_pointed_to_the_manual_steps(): void
    {
        $this->putDump();
        $restorer = app(BackupRestorer::class);
        $source = $restorer->prepare('database-backup-20261023-120500-mysql-hourly.sql.gz');

        $this->expectExceptionMessage('docs/backup-restore.md');
        $this->restoreDatabase($restorer, $source);
    }

    // --- uploaded files --------------------------------------------------

    private function archiveSource(BackupRestorer $restorer, array $entries): array
    {
        $this->putArchive('app-backup-20261023-020000-daily.zip', $entries);

        return $restorer->prepare('app-backup-20261023-020000-daily.zip');
    }

    public function test_uploaded_files_are_copied_back_without_deleting_anything_and_the_code_is_never_touched(): void
    {
        File::ensureDirectoryExists($this->base.'/storage/app/public/products');
        File::put($this->base.'/storage/app/public/products/a.png', 'changed since the backup');
        File::put($this->base.'/storage/app/public/products/newer.png', 'uploaded after the backup');
        File::ensureDirectoryExists($this->base.'/app');
        File::put($this->base.'/app/Kept.php', 'current code');
        $restorer = $this->restorer();
        $source = $this->archiveSource($restorer, [
            'database/database-backup-20261023-020000-mysql-daily.sql.gz' => $this->dumpContents(),
            'storage/app/public/products/a.png' => 'original a',
            'storage/app/private/kyc/b.pdf' => 'original b',
            'app/Kept.php' => 'old code from the archive',
            '.env' => 'APP_KEY=from-the-archive',
        ]);

        $result = $restorer->restore($source, false, true, true, true, null, fn (string $message) => null);

        $this->assertSame(['restored' => 2, 'bytes' => 20, 'skipped' => 3, 'unsafe' => 0], $result['files']);
        $this->assertSame('original a', file_get_contents($this->base.'/storage/app/public/products/a.png'));
        $this->assertSame('original b', file_get_contents($this->base.'/storage/app/private/kyc/b.pdf'));
        $this->assertSame('uploaded after the backup', file_get_contents($this->base.'/storage/app/public/products/newer.png'));
        $this->assertSame('current code', file_get_contents($this->base.'/app/Kept.php'));
        $this->assertFileDoesNotExist($this->base.'/.env');
        $this->assertSame([], glob($this->base.'/storage/app/public/products/*.restoring'));
        $this->assertSame(['caches'], $this->steps(), 'Files only: no safety copy, no maintenance mode, no database work; only the caches are cleared.');
        $this->assertSame('files', BackupRun::query()->where('scope', 'restore')->sole()->kind);
    }

    public function test_the_modification_time_of_a_restored_file_comes_from_the_archive(): void
    {
        $path = Storage::disk('local')->path('backups/app/app-backup-20261023-020000-daily.zip');
        File::ensureDirectoryExists(dirname($path));
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('storage/app/public/old.txt', 'x');
        $zip->setMtimeName('storage/app/public/old.txt', Carbon::parse('2026-01-02 03:04:06')->timestamp);
        $zip->close();
        $restorer = $this->restorer();

        $restorer->restore($restorer->prepare('app-backup-20261023-020000-daily.zip'), false, true, true, true, null, fn (string $message) => null);

        $this->assertEqualsWithDelta(Carbon::parse('2026-01-02 03:04:06')->timestamp, filemtime($this->base.'/storage/app/public/old.txt'), 2);
    }

    public function test_names_that_climb_out_of_the_folder_are_refused_and_nothing_is_written_outside(): void
    {
        $restorer = $this->restorer();
        $source = $this->archiveSource($restorer, [
            'storage/app/public/ok.txt' => 'fine',
            '../evil.txt' => 'climbs out',
            'storage/app/public/../../../evil2.txt' => 'climbs out too',
            'storage/app/public/./dot.txt' => 'dot segment',
            'C:/windows/evil.txt' => 'drive letter',
        ]);

        $result = $restorer->restore($source, false, true, true, true, null, fn (string $message) => null);

        $this->assertSame(1, $result['files']['restored']);
        $this->assertSame(4, $result['files']['unsafe']);
        $this->assertFileExists($this->base.'/storage/app/public/ok.txt');
        $this->assertFileDoesNotExist(dirname($this->base).'/evil.txt');
        $this->assertFileDoesNotExist($this->base.'/evil2.txt');
        $this->assertFileDoesNotExist(dirname($this->base).'/evil2.txt');
    }

    public function test_a_link_in_the_way_is_not_followed(): void
    {
        $outside = storage_path('framework/testing/restore-outside');
        File::ensureDirectoryExists($outside);
        File::put($outside.'/target.txt', 'outside file');
        File::ensureDirectoryExists($this->base.'/storage/app/public');
        symlink($outside, $this->base.'/storage/app/public/linked');
        symlink($outside.'/target.txt', $this->base.'/storage/app/public/file-link.txt');
        $restorer = $this->restorer();
        $source = $this->archiveSource($restorer, [
            'storage/app/public/linked/new.txt' => 'through a linked folder',
            'storage/app/public/file-link.txt' => 'over a linked file',
            'storage/app/public/real.txt' => 'real',
        ]);

        $result = $restorer->restore($source, false, true, true, true, null, fn (string $message) => null);

        $this->assertSame(1, $result['files']['restored']);
        $this->assertSame(2, $result['files']['unsafe']);
        $this->assertFileDoesNotExist($outside.'/new.txt');
        $this->assertSame('outside file', file_get_contents($outside.'/target.txt'));
    }

    // --- the real database hooks, on a throwaway SQLite file -------------

    /** @return object a restorer that exposes the protected database steps */
    private function inspector(): object
    {
        Config::set('database.connections.restore_inspect', ['driver' => 'sqlite', 'database' => $this->base.'/inspect.sqlite', 'foreign_key_constraints' => false]);
        File::put($this->base.'/inspect.sqlite', '');

        return new class(app(DatabaseBackupService::class), app(AppBackupService::class), app(OffsiteBackupService::class), app(BackupEncryptor::class), app(BackupRestoreDrill::class), app(BackupRunRecorder::class)) extends BackupRestorer
        {
            public function tablesOf(string $c): array
            {
                return $this->tables($c);
            }

            public function hasTablesOf(string $c): bool
            {
                return $this->hasTables($c);
            }

            public function wipeOf(string $c): void
            {
                $this->wipe($c);
            }

            public function summaryOf(string $c): array
            {
                return $this->summarise($c);
            }

            public function warningsOf(string $c): array
            {
                return $this->databaseWarnings($c);
            }

            public function loadOf(array $config, string $path): void
            {
                $this->load($config, $path);
            }
        };
    }

    public function test_wiping_drops_every_table_and_view_of_the_database_and_summarising_counts_what_is_there(): void
    {
        $inspector = $this->inspector();
        $schema = Schema::connection('restore_inspect');

        $this->assertFalse($inspector->hasTablesOf('restore_inspect'));

        $schema->create('migrations', function ($table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $schema->create('orders', fn ($table) => $table->id());
        DB::connection('restore_inspect')->statement('CREATE VIEW order_ids AS SELECT id FROM orders');
        DB::connection('restore_inspect')->table('migrations')->insert([['migration' => '2026_01_01_000000_a', 'batch' => 1], ['migration' => '2026_01_02_000000_b', 'batch' => 1]]);

        $this->assertTrue($inspector->hasTablesOf('restore_inspect'));
        $this->assertSame(['tables' => 2, 'migrations' => 2, 'latest' => '2026_01_02_000000_b'], $inspector->summaryOf('restore_inspect'));

        $inspector->wipeOf('restore_inspect');

        $this->assertSame([], $inspector->tablesOf('restore_inspect'));
        $this->assertSame([], $schema->getViews());

        $this->expectExceptionMessage('has no migrations table');
        $inspector->summaryOf('restore_inspect');
    }

    public function test_a_backup_from_a_newer_version_of_the_app_is_flagged_and_so_are_queued_jobs_that_came_back(): void
    {
        $inspector = $this->inspector();
        $default = (string) config('database.default');
        DB::table('migrations')->insert(['migration' => '2099_01_01_000000_from_the_future', 'batch' => 999]);

        $this->assertSame([], array_filter($inspector->warningsOf($default), fn (string $warning): bool => str_contains($warning, 'queued job')));
        $warnings = $inspector->warningsOf($default);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('1 migration(s) in the backup are not in this version of the app (latest: 2099_01_01_000000_from_the_future)', $warnings[0]);

        Config::set('queue.default', 'database');
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

        $this->assertStringContainsString('1 queued job(s) came back with the backup', implode(' ', $inspector->warningsOf($default)));
    }

    public function test_the_dump_is_streamed_into_the_configured_mysql_client_for_the_live_database(): void
    {
        $directory = $this->base.'/client';
        File::ensureDirectoryExists($directory);
        File::put($directory.'/mysql', "#!/bin/sh\necho \"\$@\" > {$directory}/args\ncat > {$directory}/stdin\n");
        chmod($directory.'/mysql', 0755);
        Config::set('backup.restore.mysql_binary', $directory.'/mysql');
        Config::set('backup.restore.timeout', 60);
        $path = $this->putDump();
        $inspector = $this->inspector();

        $inspector->loadOf(['driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'database' => 'erp_live', 'username' => 'erp', 'password' => 'pa$$word'], $path);

        $args = trim((string) file_get_contents($directory.'/args'));
        $this->assertStringEndsWith(' erp_live', $args);
        $this->assertStringNotContainsString('pa$$word', $args);
        $this->assertSame(gzdecode((string) file_get_contents($path)), file_get_contents($directory.'/stdin'));
    }

    // --- bookkeeping -----------------------------------------------------

    public function test_finished_runs_are_recorded_after_the_fact_and_unfinished_ones_can_be_discarded(): void
    {
        $recorder = app(BackupRunRecorder::class);
        BackupRun::query()->create(['scope' => 'database', 'kind' => 'hourly', 'status' => 'running', 'started_at' => now()]);
        BackupRun::query()->create(['scope' => 'database', 'kind' => 'hourly', 'status' => 'success', 'filename' => 'kept.sql.gz', 'started_at' => now(), 'finished_at' => now()]);

        $recorder->discardUnfinished();
        $recorder->recordFinished('restore', 'database', ['filename' => 'x.sql.gz', 'size' => 10, 'note' => 'database: 3 tables', 'started_at' => now()->subMinute()]);
        $recorder->recordFinished('restore', 'files', ['status' => 'failed', 'error' => str_repeat('e', 3000)]);

        $this->assertSame(0, BackupRun::query()->where('status', 'running')->count());
        $this->assertSame(1, BackupRun::query()->where('filename', 'kept.sql.gz')->count());
        $this->assertSame('database: 3 tables', BackupRun::query()->where('kind', 'database')->where('scope', 'restore')->value('note'));
        $this->assertSame(2000, strlen((string) BackupRun::query()->where('kind', 'files')->value('error')));
    }

    public function test_the_safety_copy_is_its_own_kind_that_is_pruned_on_its_own(): void
    {
        $this->assertSame('prerestore', BackupRun::kindOfFile('database-backup-20261023-130000-mysql-prerestore.sql.gz'));
        $this->assertContains('prerestore', BackupRun::KINDS);

        Config::set('backup.database.keep.prerestore', 2);
        $disk = Storage::disk('local');

        foreach (range(1, 4) as $i) {
            $path = DatabaseBackupService::DIRECTORY."/database-backup-20261023-13000{$i}-mysql-prerestore.sql.gz";
            $disk->put($path, 'x');
            touch($disk->path($path), now()->subMinutes(10 - $i)->timestamp);
        }

        $disk->put(DatabaseBackupService::DIRECTORY.'/database-backup-20261023-100000-mysql-daily.sql.gz', 'x');
        app(DatabaseBackupService::class)->prune();

        $names = collect(app(DatabaseBackupService::class)->all())->groupBy('kind')->map->pluck('name');
        $this->assertCount(2, $names['prerestore']);
        $this->assertCount(1, $names['daily']);
    }

    public function test_the_backups_page_shows_restores_and_the_safety_copies(): void
    {
        BackupRun::query()->create([
            'scope' => 'restore', 'kind' => 'both', 'status' => 'success',
            'filename' => 'app-backup-20261023-020000-daily.zip', 'note' => 'database: 163 tables; files: 270 copied',
            'started_at' => now(), 'finished_at' => now(),
        ]);
        Storage::disk('local')->put(DatabaseBackupService::DIRECTORY.'/database-backup-20261023-130000-mysql-prerestore.sql.gz', 'x');
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->get('/admin/settings/backups')
            ->assertOk()
            ->assertSee('Restore · Database and files')
            ->assertSee('database: 163 tables; files: 270 copied')
            ->assertSee('Before restore');
    }

    // --- the command -----------------------------------------------------

    /**
     * A restorer for the command tests: it hands back a fixed source and records what the command
     * asked it to do, so only the command's own behaviour is under test.
     */
    private function commandRestorer(array $source = [], array $checked = []): object
    {
        Config::set('database.connections.'.config('database.default').'.database', 'erp');
        Config::set('database.connections.'.config('database.default').'.host', 'db.internal');
        $runs = app(BackupRunRecorder::class);
        $offsite = app(OffsiteBackupService::class);
        $databases = $this->newDatabases();

        $restorer = new class($databases, new AppBackupService($databases, $offsite, $runs), $offsite, app(BackupEncryptor::class), app(BackupRestoreDrill::class), $runs) extends BackupRestorer
        {
            /** @var array<int, array{string, array}> */
            public array $calls = [];

            public array $source = [];

            public array $checked = [];

            public bool $rehearsable = false;

            public ?Throwable $prepareFails = null;

            public ?Throwable $checkFails = null;

            public ?Throwable $rehearseFails = null;

            public ?Throwable $restoreFails = null;

            public array $result = [];

            public function prepare(?string $file, bool|string $offsite = false, ?string $key = null): array
            {
                $this->calls[] = ['prepare', compact('file', 'offsite', 'key')];

                return $this->prepareFails ? throw $this->prepareFails : $this->source;
            }

            public function release(array $source): void
            {
                $this->calls[] = ['release', []];
            }

            public function check(array $source): array
            {
                return $this->checkFails ? throw $this->checkFails : $this->checked;
            }

            public function canRehearse(): bool
            {
                return $this->rehearsable;
            }

            public function rehearse(array $source, int $expectedTables): string
            {
                $this->calls[] = ['rehearse', ['tables' => $expectedTables]];

                return $this->rehearseFails ? throw $this->rehearseFails : 'restored into a scratch database in 2 s: 163 tables';
            }

            public function restore(array $source, bool $database, bool $files, bool $safetyBackup, bool $migrate, ?int $expectedTables, callable $progress): array
            {
                $this->calls[] = ['restore', compact('database', 'files', 'safetyBackup', 'migrate', 'expectedTables')];
                $progress('Doing the restore.');

                return $this->restoreFails ? throw $this->restoreFails : $this->result;
            }
        };

        $restorer->source = $source + [
            'label' => 'database-backup-20261023-120500-mysql-hourly.sql.gz',
            'name' => 'database-backup-20261023-120500-mysql-hourly.sql.gz',
            'kind' => BackupRestorer::DUMP,
            'path' => '/tmp/x.sql.gz',
            'dump' => ['name' => 'database-backup-20261023-120500-mysql-hourly.sql.gz', 'path' => '/tmp/x.sql.gz'],
            'taken_at' => Carbon::parse('2026-10-23 12:05:00'),
            'size' => 4096,
            'directory' => '/tmp/restore-x',
        ];
        $restorer->checked = $checked + ['tables' => 163, 'checksum' => 'verified', 'files' => null];
        $restorer->result = [
            'database' => ['tables' => 163, 'migrations' => 264, 'latest' => '2026_10_23_110000_x', 'safety' => 'database-backup-20261023-130000-mysql-prerestore.sql.gz', 'migrated' => true],
            'files' => null,
            'warnings' => [],
            'maintenance' => 'lifted',
        ];
        $this->app->instance(BackupRestorer::class, $restorer);

        return $restorer;
    }

    private function called(object $restorer, string $name): array
    {
        return collect($restorer->calls)->where(0, $name)->pluck(1)->values()->all();
    }

    public function test_the_command_needs_a_file_or_drive_and_lists_what_is_on_the_server(): void
    {
        $this->commandRestorer();
        $this->putDump('database-backup-20261023-120500-mysql-hourly.sql.gz');

        $this->artisan('backup:restore')
            ->expectsOutputToContain('Say which backup to restore')
            ->expectsOutputToContain('database-backup-20261023-120500-mysql-hourly.sql.gz')
            ->assertExitCode(1);

        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--only' => 'everything'])
            ->expectsOutputToContain('--only must be "database" or "files"')
            ->assertExitCode(1);
    }

    public function test_the_command_refuses_to_replace_the_database_without_the_database_name_typed(): void
    {
        $restorer = $this->commandRestorer();

        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--no-interaction' => true])
            ->expectsOutputToContain('add --confirm=erp')
            ->assertExitCode(1);

        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'wrong'])
            ->expectsOutputToContain('Not confirmed, so nothing was changed.')
            ->assertExitCode(1);

        $this->artisan('backup:restore', ['file' => 'x.sql.gz'])
            ->expectsQuestion('Type erp to continue', 'yes')
            ->expectsOutputToContain('Not confirmed, so nothing was changed.')
            ->assertExitCode(1);

        $this->assertSame([], $this->called($restorer, 'restore'));
        $this->assertCount(3, $this->called($restorer, 'release'), 'The unpacked copy is removed every time.');
    }

    public function test_typing_the_database_name_replaces_the_database_with_the_safe_defaults(): void
    {
        $restorer = $this->commandRestorer();

        $this->artisan('backup:restore', ['file' => 'x.sql.gz'])
            ->expectsOutputToContain('This REPLACES the whole database "erp" with the backup.')
            ->expectsOutputToContain('Everything saved after 23 Oct 2026, 12:05 PM')
            ->expectsQuestion('Type erp to continue', 'erp')
            ->expectsOutputToContain('Restore finished.')
            ->expectsOutputToContain('Maintenance mode is off: the app is online.')
            ->expectsOutputToContain('php artisan backup:restore database-backup-20261023-130000-mysql-prerestore.sql.gz')
            ->assertExitCode(0);

        $this->assertSame([['database' => true, 'files' => false, 'safetyBackup' => true, 'migrate' => true, 'expectedTables' => 163]], $this->called($restorer, 'restore'));
        $this->assertSame([['file' => 'x.sql.gz', 'offsite' => false, 'key' => null]], $this->called($restorer, 'prepare'));
    }

    public function test_the_flags_reach_the_restore_and_confirm_replaces_the_question(): void
    {
        $restorer = $this->commandRestorer();
        Config::set('backup.encryption.key', self::KEY);

        $this->artisan('backup:restore', ['--drive' => true, '--confirm' => 'erp', '--skip-safety-backup' => true, '--no-migrate' => true, '--skip-rehearsal' => true])
            ->assertExitCode(0);

        $this->assertSame([['database' => true, 'files' => false, 'safetyBackup' => false, 'migrate' => false, 'expectedTables' => 163]], $this->called($restorer, 'restore'));
        $this->assertSame([['file' => null, 'offsite' => 'drive', 'key' => null]], $this->called($restorer, 'prepare'));
    }

    public function test_the_r2_flags_and_offsite_choose_the_place_to_read_from(): void
    {
        Config::set('backup.encryption.key', self::KEY);

        foreach ([['--r2' => true, 'expected' => 'r2'], ['--offsite' => true, 'expected' => true], ['--drive' => true, 'expected' => 'drive']] as $case) {
            $restorer = $this->commandRestorer();
            $expected = $case['expected'];
            unset($case['expected']);

            $this->artisan('backup:restore', [...$case, '--confirm' => 'erp', '--skip-safety-backup' => true, '--no-migrate' => true, '--skip-rehearsal' => true])
                ->assertExitCode(0);

            $this->assertSame([['file' => null, 'offsite' => $expected, 'key' => null]], $this->called($restorer, 'prepare'));
        }
    }

    public function test_the_newest_copy_on_r2_is_fetched_and_decrypted_and_r2_is_tried_first(): void
    {
        config(['backup.encryption.key' => self::KEY]);
        app(BackupSettingsService::class)->saveR2([
            'enabled' => true, 'account' => '0123456789abcdef0123456789abcdef', 'bucket' => 'b', 'prefix' => 'erp-backups',
            'access_key_id' => 'k', 'secret_access_key' => 's',
        ]);
        $s3 = new FakeS3;
        $this->app->instance(R2BackupService::class, app(R2BackupService::class)->withClient($s3->client()));
        $drive = $this->fakeDrive();
        $drive->remote = [['id' => 'd', 'name' => 'database-backup-20261024-120500-mysql-hourly.sql.gz.enc']];

        $plain = $this->base.'/plain.sql.gz';
        $encrypted = $this->base.'/copy.enc';
        File::put($plain, $this->dumpContents());
        app(BackupEncryptor::class)->encryptFile($plain, $encrypted, self::KEY);
        $s3->put('erp-backups/database-backup-20261022-120500-mysql-hourly.sql.gz.enc', 'older');
        $s3->put('erp-backups/database-backup-20261023-120500-mysql-hourly.sql.gz.enc', (string) file_get_contents($encrypted));

        $restorer = $this->restorer();

        // Without a name, the first place that is on: R2.
        $source = $restorer->prepare(null, offsite: true);
        $this->assertSame('database-backup-20261023-120500-mysql-hourly.sql.gz.enc', $source['label']);
        $this->assertSame(file_get_contents($plain), file_get_contents($source['path']));
        $restorer->release($source);

        // Named, the other place is read instead.
        $drive->files = ['d' => (string) file_get_contents($encrypted)];
        $source = $restorer->prepare(null, offsite: 'drive');
        $this->assertSame('database-backup-20261024-120500-mysql-hourly.sql.gz.enc', $source['label']);
        $restorer->release($source);

        $s3->objects = [];
        $this->expectExceptionMessage('No database copy was found on Cloudflare R2.');
        $restorer->prepare(null, offsite: 'r2');
    }

    public function test_a_full_app_archive_restores_both_and_only_limits_it(): void
    {
        $archive = ['kind' => BackupRestorer::ARCHIVE, 'label' => 'app-backup-20261023-020000-daily.zip', 'name' => 'app-backup-20261023-020000-daily.zip'];
        $files = ['restore' => 270, 'bytes' => 1572864, 'skipped' => 1905, 'unsafe' => 0];
        $restorer = $this->commandRestorer($archive, ['files' => $files]);
        $restorer->result['files'] = ['restored' => 270, 'bytes' => 1572864, 'skipped' => 1905, 'unsafe' => 0];

        $this->artisan('backup:restore', ['file' => 'a.zip', '--confirm' => 'erp'])
            ->expectsOutputToContain('270 files (1.5 MB) can be copied back; 1905 entries (code, .env, ...) are never restored')
            ->expectsOutputToContain('Files:     270 copied (1.5 MB)')
            ->assertExitCode(0);
        $this->artisan('backup:restore', ['file' => 'a.zip', '--confirm' => 'erp', '--only' => 'database'])->assertExitCode(0);
        // Files only: the word to type is "files", not the database name.
        $this->artisan('backup:restore', ['file' => 'a.zip', '--confirm' => 'erp', '--only' => 'files'])
            ->expectsOutputToContain('Not confirmed')
            ->assertExitCode(1);
        $this->artisan('backup:restore', ['file' => 'a.zip', '--confirm' => 'files', '--only' => 'files'])->assertExitCode(0);

        $this->assertSame([
            ['database' => true, 'files' => true, 'safetyBackup' => true, 'migrate' => true, 'expectedTables' => 163],
            ['database' => true, 'files' => false, 'safetyBackup' => true, 'migrate' => true, 'expectedTables' => 163],
            ['database' => false, 'files' => true, 'safetyBackup' => true, 'migrate' => true, 'expectedTables' => 163],
        ], $this->called($restorer, 'restore'));
    }

    public function test_files_cannot_come_from_a_dump_and_a_dump_less_archive_needs_only_files(): void
    {
        $restorer = $this->commandRestorer();

        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--only' => 'files', '--confirm' => 'files'])
            ->expectsOutputToContain('--only=files needs a full app archive')
            ->assertExitCode(1);

        $restorer->source = ['kind' => BackupRestorer::ARCHIVE, 'dump' => null] + $restorer->source;

        $this->artisan('backup:restore', ['file' => 'a.zip', '--confirm' => 'erp', '--only' => 'database'])
            ->expectsOutputToContain('holds no database dump')
            ->assertExitCode(1);

        $this->assertSame([], $this->called($restorer, 'restore'));
    }

    public function test_the_dump_is_tried_in_a_scratch_database_first_and_a_dump_that_does_not_load_stops_everything(): void
    {
        $restorer = $this->commandRestorer();
        $restorer->rehearsable = true;

        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'erp'])
            ->expectsOutputToContain('Restored into a scratch database in 2 s: 163 tables')
            ->assertExitCode(0);
        $this->assertSame([['tables' => 163]], $this->called($restorer, 'rehearse'));

        $restorer->calls = [];
        $restorer->rehearseFails = new RuntimeException('ERROR 1064 at line 12');

        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'erp'])
            ->expectsOutputToContain('The dump does not load, so the database was not touched: ERROR 1064 at line 12')
            ->assertExitCode(1);
        $this->assertSame([], $this->called($restorer, 'restore'));

        $restorer->calls = [];
        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'erp', '--skip-rehearsal' => true])->assertExitCode(0);
        $this->assertSame([], $this->called($restorer, 'rehearse'));
        $this->assertCount(1, $this->called($restorer, 'restore'));
    }

    public function test_without_a_restore_test_user_the_command_says_there_is_no_scratch_test(): void
    {
        $this->commandRestorer();

        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'erp'])
            ->expectsOutputToContain('No scratch-database test first')
            ->assertExitCode(0);
    }

    public function test_an_encrypted_file_asks_for_the_key_only_when_it_is_not_in_the_environment(): void
    {
        $restorer = $this->commandRestorer();
        Config::set('backup.encryption.key', null);

        $this->artisan('backup:restore', ['file' => 'x.sql.gz.enc', '--confirm' => 'erp'])
            ->expectsQuestion('Backup encryption key (BACKUP_ENCRYPTION_KEY)', 'typed-key-0123456789')
            ->assertExitCode(0);

        Config::set('backup.encryption.key', self::KEY);
        $this->artisan('backup:restore', ['file' => 'x.sql.gz.enc', '--confirm' => 'erp'])->assertExitCode(0);

        $this->assertSame(['typed-key-0123456789', null], array_column($this->called($restorer, 'prepare'), 'key'));
    }

    public function test_a_file_that_cannot_be_read_or_checked_is_reported_before_anything_is_asked(): void
    {
        $restorer = $this->commandRestorer();
        Config::set('backup.encryption.key', self::KEY);
        $restorer->prepareFails = new RuntimeException('The backup could not be decrypted: wrong key, or the file is damaged.');

        $this->artisan('backup:restore', ['file' => 'x.sql.gz.enc', '--confirm' => 'erp'])
            ->expectsOutputToContain('wrong key, or the file is damaged')
            ->assertExitCode(1);
        $this->assertSame([], $this->called($restorer, 'restore'));

        $restorer->prepareFails = null;
        $restorer->checkFails = new RuntimeException('The dump is incomplete: it does not end with the "Dump completed" line.');

        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'erp'])
            ->expectsOutputToContain('The backup is not usable: The dump is incomplete')
            ->assertExitCode(1);
        $this->assertSame([], $this->called($restorer, 'restore'));
        $this->assertCount(1, $this->called($restorer, 'release'), 'The unpacked copy is removed when the check fails; a failed prepare has none to remove.');
    }

    public function test_the_command_tells_what_state_a_failed_restore_left(): void
    {
        $restorer = $this->commandRestorer();

        $restorer->restoreFails = new BackupRestoreException('Restoring failed: boom', true, 'safety.sql.gz');
        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'erp'])
            ->expectsOutputToContain('Restoring failed: boom')
            ->expectsOutputToContain('The previous database was put back from the safety copy safety.sql.gz; nothing was lost.')
            ->assertExitCode(1);

        $restorer->restoreFails = new BackupRestoreException('Restoring failed: boom', false, 'safety.sql.gz');
        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'erp'])
            ->expectsOutputToContain('could NOT be put back automatically')
            ->expectsOutputToContain('php artisan backup:restore safety.sql.gz')
            ->assertExitCode(1);

        $restorer->restoreFails = new BackupRestoreException('Restoring failed: boom');
        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'erp'])
            ->expectsOutputToContain('There was no safety copy')
            ->assertExitCode(1);

        $restorer->restoreFails = new RuntimeException('Another restore is running on this server.');
        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'erp'])
            ->expectsOutputToContain('Restore failed: Another restore is running on this server.')
            ->assertExitCode(1);
    }

    public function test_the_report_shows_warnings_and_a_closed_app(): void
    {
        $restorer = $this->commandRestorer();
        $restorer->result['warnings'] = ['The database was restored but the migrations failed: boom.'];
        $restorer->result['maintenance'] = 'left_on';
        $restorer->result['database']['migrated'] = false;

        $this->artisan('backup:restore', ['file' => 'x.sql.gz', '--confirm' => 'erp'])
            ->expectsOutputToContain('MIGRATIONS FAILED')
            ->expectsOutputToContain('The database was restored but the migrations failed: boom.')
            ->expectsOutputToContain('Maintenance mode is still ON')
            ->assertExitCode(0);
    }
}
