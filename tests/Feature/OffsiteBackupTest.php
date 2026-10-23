<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\DeploymentError;
use App\Models\User;
use App\Services\AppBackupService;
use App\Services\BackupEncryptor;
use App\Services\BackupSettingsService;
use App\Services\DatabaseBackupService;
use App\Services\GoogleDriveBackupService;
use App\Services\OffsiteBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class OffsiteBackupTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-encryption-key-123456';

    private function sqliteConnection(string $name = 'offsite_test'): void
    {
        $path = storage_path("framework/testing/{$name}.sqlite");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'sqlite backup contents');
        Config::set("database.connections.{$name}", ['driver' => 'sqlite', 'database' => $path]);
        Config::set('backup.app.database_connection', $name);
    }

    private function driveSettings(bool $enabled = true, bool $auto = true, string $folder = 'folder'): void
    {
        app(BackupSettingsService::class)->saveGoogleDrive([
            'enabled' => $enabled, 'auto_upload' => $auto, 'folder_id' => $folder, 'service_account_path' => '', 'service_account_json' => '',
        ]);
    }

    /** A Google Drive stand-in: keeps what it is sent, lists $remote, records deletes. */
    private function fakeDrive(): GoogleDriveBackupService
    {
        $drive = new class extends GoogleDriveBackupService
        {
            /** @var array<string, string> name => bytes sent */
            public array $sent = [];

            /** @var array<int, array{id: string, name: string}> */
            public array $remote = [];

            /** @var array<int, string> */
            public array $deleted = [];

            public ?RuntimeException $uploadFails = null;

            public ?RuntimeException $listFails = null;

            public function upload(string $absolutePath, ?string $filename = null, string $mimeType = 'application/zip'): array
            {
                if ($this->uploadFails) {
                    throw $this->uploadFails;
                }

                $this->sent[$filename ?? basename($absolutePath)] = (string) file_get_contents($absolutePath);

                return ['id' => 'drive-file-id'];
            }

            public function listBackupFiles(): array
            {
                if ($this->listFails) {
                    throw $this->listFails;
                }

                return $this->remote;
            }

            public function delete(string $fileId): void
            {
                $this->deleted[] = $fileId;
            }
        };
        $this->app->instance(GoogleDriveBackupService::class, $drive);

        return $drive;
    }

    private function backupFile(string $name = 'database-backup-20261023-120500-mysql-hourly.sql.gz'): string
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/database/'.$name, "\x1f\x8b".'not really a dump but it has a body '.str_repeat('x', 5000));

        return Storage::disk('local')->path('backups/database/'.$name);
    }

    // --- policy ---------------------------------------------------------

    public function test_auto_upload_needs_drive_on_the_switch_on_and_the_kind_to_be_listed(): void
    {
        $offsite = app(OffsiteBackupService::class);

        $this->driveSettings(enabled: true, auto: true);
        $this->assertTrue($offsite->shouldAutoUpload('database', 'hourly'));
        $this->assertTrue($offsite->shouldAutoUpload('database', 'predeploy'));
        $this->assertTrue($offsite->shouldAutoUpload('app', 'daily'));
        // A person pressing the button uploads only when they press Upload; pre-deploy zips stay local.
        $this->assertFalse($offsite->shouldAutoUpload('database', 'manual'));
        $this->assertFalse($offsite->shouldAutoUpload('app', 'predeploy'));
        $this->assertFalse($offsite->shouldAutoUpload('app', 'hourly'));

        $this->driveSettings(enabled: true, auto: false);
        $this->assertFalse($offsite->shouldAutoUpload('database', 'hourly'));

        $this->driveSettings(enabled: false, auto: true);
        $this->assertFalse($offsite->shouldAutoUpload('database', 'hourly'));

        Config::set('backup.offsite.database_kinds', ['daily']);
        $this->driveSettings();
        $this->assertFalse($offsite->shouldAutoUpload('database', 'hourly'));
        $this->assertTrue($offsite->shouldAutoUpload('database', 'daily'));
    }

    public function test_nothing_is_uploaded_unencrypted_by_default(): void
    {
        $drive = $this->fakeDrive();
        $this->driveSettings();
        $path = $this->backupFile();
        Config::set('backup.encryption.key', null);

        try {
            app(OffsiteBackupService::class)->send($path);
            $this->fail('An unencrypted upload must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('BACKUP_ENCRYPTION_KEY', $exception->getMessage());
        }

        $this->assertSame([], $drive->sent);
    }

    public function test_plain_upload_is_possible_only_when_the_owner_switches_the_guard_off(): void
    {
        $drive = $this->fakeDrive();
        $this->driveSettings();
        $path = $this->backupFile();
        Config::set('backup.encryption.key', null);
        Config::set('backup.offsite.require_encryption', false);

        $result = app(OffsiteBackupService::class)->send($path);

        $this->assertFalse($result['encrypted']);
        $this->assertSame(['database-backup-20261023-120500-mysql-hourly.sql.gz'], array_keys($drive->sent));
        $this->assertSame(file_get_contents($path), $drive->sent['database-backup-20261023-120500-mysql-hourly.sql.gz']);
    }

    public function test_the_uploaded_copy_is_encrypted_and_opens_with_the_key_and_the_staging_file_is_removed(): void
    {
        $drive = $this->fakeDrive();
        $this->driveSettings();
        $path = $this->backupFile();
        Config::set('backup.encryption.key', self::KEY);

        $result = app(OffsiteBackupService::class)->send($path);

        $this->assertTrue($result['encrypted']);
        $this->assertSame(['database-backup-20261023-120500-mysql-hourly.sql.gz.enc'], array_keys($drive->sent));

        $sent = $drive->sent['database-backup-20261023-120500-mysql-hourly.sql.gz.enc'];
        $this->assertStringStartsWith('Salted__', $sent);
        $this->assertStringNotContainsString('not really a dump', $sent);
        $this->assertSame([], Storage::disk('local')->files('backups/tmp'));

        $enc = storage_path('framework/testing/received.sql.gz.enc');
        File::ensureDirectoryExists(dirname($enc));
        File::put($enc, $sent);
        app(BackupEncryptor::class)->decryptFile($enc, storage_path('framework/testing/received.sql.gz'));

        $this->assertSame(file_get_contents($path), file_get_contents(storage_path('framework/testing/received.sql.gz')));
    }

    public function test_a_missing_drive_folder_is_reported_before_any_encryption_work(): void
    {
        $drive = $this->fakeDrive();
        $this->driveSettings(folder: '');
        $path = $this->backupFile();
        Config::set('backup.encryption.key', self::KEY);

        try {
            app(OffsiteBackupService::class)->send($path);
            $this->fail('A missing folder must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('folder ID is required', $exception->getMessage());
        }

        $this->assertSame([], $drive->sent);
        $this->assertSame([], Storage::disk('local')->files('backups/tmp'));
    }

    public function test_a_failed_upload_leaves_no_staging_file(): void
    {
        $drive = $this->fakeDrive();
        $drive->uploadFails = new RuntimeException('Google Drive upload failed.');
        $this->driveSettings();
        $path = $this->backupFile();
        Config::set('backup.encryption.key', self::KEY);

        $this->expectExceptionMessage('Google Drive upload failed.');

        try {
            app(OffsiteBackupService::class)->send($path);
        } finally {
            $this->assertSame([], Storage::disk('local')->files('backups/tmp'));
        }
    }

    public function test_backups_page_says_whether_off_site_copies_are_encrypted(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        Config::set('backup.encryption.key', null);
        $this->actingAs($admin)->get('/admin/settings/backups')
            ->assertOk()
            ->assertSee('Off-site encryption')
            ->assertSee('nothing is uploaded until BACKUP_ENCRYPTION_KEY is set');

        Config::set('backup.offsite.require_encryption', false);
        $this->actingAs($admin)->get('/admin/settings/backups')->assertSee('copies are uploaded without encryption');

        Config::set('backup.encryption.key', self::KEY);
        $this->actingAs($admin)->get('/admin/settings/backups')->assertSee('copies are encrypted before they are uploaded');
    }

    // --- remote retention -----------------------------------------------

    public function test_drive_keeps_the_newest_copies_of_each_kind_and_never_touches_foreign_files(): void
    {
        $drive = $this->fakeDrive();
        Config::set('backup.offsite.keep', [
            'database' => ['hourly' => 2, 'daily' => 1, 'predeploy' => 1, 'manual' => 1],
            'app' => ['hourly' => 1, 'daily' => 1, 'predeploy' => 1, 'manual' => 1],
        ]);
        $names = [
            // database hourly: keep the two newest
            'a1' => 'database-backup-20261023-100500-mysql-hourly.sql.gz.enc',
            'a2' => 'database-backup-20261023-110500-mysql-hourly.sql.gz.enc',
            'a3' => 'database-backup-20261023-120500-mysql-hourly.sql.gz.enc',
            // database daily: plain and encrypted mix, keep one
            'b1' => 'database-backup-20261022-020000-mysql-daily.sql.gz',
            'b2' => 'database-backup-20261023-020000-mysql-daily.sql.gz.enc',
            // app daily and a legacy name without a kind (counts as manual)
            'c1' => 'app-backup-20261021-020000-daily.zip.enc',
            'c2' => 'app-backup-20261022-020000-daily.zip.enc',
            'd1' => 'app-backup-20260613-120000.zip',
            'd2' => 'app-backup-20260614-120000.zip',
            // not ours: must survive whatever the retention says
            'x1' => 'holiday-photos.zip',
            'x2' => 'app-backup-notes.txt',
            'x3' => 'database-backup.docx',
            'x4' => 'report-2026.sql.gz',
        ];
        $drive->remote = collect($names)->map(fn (string $name, string $id): array => ['id' => $id, 'name' => $name])->values()->all();

        // A place that is switched off is left alone.
        $this->driveSettings(enabled: false);
        $this->assertSame(0, app(OffsiteBackupService::class)->prune());
        $this->assertSame([], $drive->deleted);

        $this->driveSettings();
        $removed = app(OffsiteBackupService::class)->prune();

        $this->assertEqualsCanonicalizing(['a1', 'b1', 'c1', 'd1'], $drive->deleted);
        $this->assertSame(4, $removed);
    }

    public function test_a_retention_problem_never_fails_the_backup_and_is_reported(): void
    {
        $drive = $this->fakeDrive();
        $drive->listFails = new RuntimeException('Drive is having a bad day.');
        $this->driveSettings();
        $path = $this->backupFile();
        Config::set('backup.encryption.key', self::KEY);

        $result = app(OffsiteBackupService::class)->send($path);

        $this->assertSame(0, $result['pruned']);
        $this->assertSame('drive-file-id', $result['id']);
        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:offsite-prune')->count());
    }

    // --- wired into the backups -----------------------------------------

    public function test_a_database_backup_with_upload_records_one_successful_run_and_sends_the_copy(): void
    {
        $drive = $this->fakeDrive();
        $this->driveSettings();
        $this->sqliteConnection();
        Storage::fake('local');
        Config::set('backup.encryption.key', self::KEY);

        $backup = app(DatabaseBackupService::class)->create('offsite_test', BackupRun::KIND_HOURLY, upload: true);

        $this->assertTrue($backup['offsite']['encrypted']);
        $this->assertSame(['drive'], array_keys($backup['offsite']['destinations']));
        $this->assertCount(1, $drive->sent);
        $this->assertMatchesRegularExpression('/^database-backup-.*-hourly\.sqlite\.enc$/', array_key_first($drive->sent));
        $run = BackupRun::query()->sole();
        $this->assertSame(['database', 'hourly', 'success'], [$run->scope, $run->kind, $run->status]);
        $this->assertNotNull($run->offsite_at, 'the run remembers that its copy reached Drive');

        // A backup that stayed on the server does not claim to be off-site.
        app(DatabaseBackupService::class)->create('offsite_test', BackupRun::KIND_DAILY);
        $this->assertNull(BackupRun::query()->where('kind', 'daily')->sole()->offsite_at);
    }

    public function test_a_run_is_still_recorded_before_the_newer_columns_exist(): void
    {
        // The pre-deploy backup is taken before `migrate` adds columns to backup_runs.
        $this->fakeDrive();
        $this->driveSettings();
        $this->sqliteConnection();
        Storage::fake('local');
        Config::set('backup.encryption.key', self::KEY);
        Schema::dropColumns('backup_runs', ['offsite_at', 'note']);

        app(DatabaseBackupService::class)->create('offsite_test', BackupRun::KIND_HOURLY, upload: true);

        $this->assertSame('success', BackupRun::query()->sole()->status);
    }

    public function test_a_failed_upload_fails_the_run_and_alerts_but_the_local_files_are_still_pruned(): void
    {
        $drive = $this->fakeDrive();
        $drive->uploadFails = new RuntimeException('Google Drive is unreachable.');
        $this->driveSettings();
        $this->sqliteConnection();
        Storage::fake('local');
        Config::set('backup.encryption.key', self::KEY);
        Config::set('backup.database.keep.hourly', 2);
        $disk = Storage::disk('local');

        foreach ([1, 2, 3] as $i) {
            $path = "backups/database/database-backup-20261023-10{$i}000-offsite_test-hourly.sqlite";
            $disk->put($path, "old {$i}");
            touch($disk->path($path), now()->subMinutes(100 - $i)->timestamp);
        }

        try {
            app(DatabaseBackupService::class)->create('offsite_test', BackupRun::KIND_HOURLY, upload: true);
            $this->fail('The upload failure should have been thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Google Drive is unreachable.', $exception->getMessage());
        }

        $run = BackupRun::query()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Google Drive is unreachable.', $run->error);
        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:database:hourly')->count());
        // Drive being down must not let local files pile up: the new one and the newest old one remain.
        $this->assertCount(2, $disk->files('backups/database'));
    }

    public function test_backup_database_command_uploads_only_the_kinds_that_auto_upload_covers(): void
    {
        $drive = $this->fakeDrive();
        $this->driveSettings();
        $this->sqliteConnection();
        Storage::fake('local');
        Config::set('backup.encryption.key', self::KEY);

        // Not asked for, or a kind that stays local: nothing sent.
        $this->artisan('backup:database', ['--kind' => 'hourly', '--connection' => 'offsite_test'])->assertExitCode(0);
        $this->artisan('backup:database', ['--kind' => 'manual', '--auto-upload' => true, '--connection' => 'offsite_test'])->assertExitCode(0);
        $this->assertSame([], $drive->sent);

        $this->artisan('backup:database', ['--kind' => 'hourly', '--auto-upload' => true, '--connection' => 'offsite_test'])
            ->expectsOutputToContain('sent to Google Drive')
            ->assertExitCode(0);
        $this->assertCount(1, $drive->sent);

        // --upload sends whatever the kind.
        $this->artisan('backup:database', ['--kind' => 'manual', '--upload' => true, '--connection' => 'offsite_test'])->assertExitCode(0);
        $this->assertCount(2, $drive->sent);

        // Auto upload switched on but no key: the run fails loudly and says why.
        Config::set('backup.encryption.key', null);
        $this->artisan('backup:database', ['--kind' => 'hourly', '--auto-upload' => true, '--connection' => 'offsite_test'])
            ->expectsOutputToContain('BACKUP_ENCRYPTION_KEY')
            ->assertExitCode(1);
        $this->assertSame(2, count($drive->sent));
    }

    public function test_the_daily_app_archive_is_sent_encrypted_and_includes_private_files(): void
    {
        $drive = $this->fakeDrive();
        $this->driveSettings();
        $this->sqliteConnection();
        Storage::fake('local');
        Config::set('backup.encryption.key', self::KEY);
        // The shipped configuration, not a test one: private documents are part of the archive.
        $this->assertContains('storage/app/private', config('backup.app.include_paths'));
        $this->assertContains('storage/app/private/backups', config('backup.app.exclude_paths'));
        Config::set('backup.app.include_paths', []);

        $this->artisan('backup:app', ['--kind' => 'daily', '--auto-upload' => true])->assertExitCode(0);

        $this->assertCount(1, $drive->sent);
        $this->assertMatchesRegularExpression('/^app-backup-.*-daily\.zip\.enc$/', array_key_first($drive->sent));
        $this->assertSame([], Storage::disk('local')->files('backups/tmp'));
    }

    public function test_private_files_are_zipped_but_the_backup_folder_is_not(): void
    {
        $this->sqliteConnection();
        Storage::fake('local');
        $root = base_path('storage/framework/testing/private-zip');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/companies/1/kyc');
        File::ensureDirectoryExists($root.'/backups/app');
        File::put($root.'/companies/1/kyc/id-card.jpg', 'kyc picture');
        File::put($root.'/backups/app/old.zip', 'an earlier backup');
        Config::set('backup.app.include_paths', ['storage/framework/testing/private-zip']);
        Config::set('backup.app.exclude_paths', ['storage/framework/testing/private-zip/backups']);

        $backup = app(AppBackupService::class)->create();

        $zip = new \ZipArchive;
        $zip->open($backup['path']);
        $this->assertNotFalse($zip->locateName('storage/framework/testing/private-zip/companies/1/kyc/id-card.jpg'));
        $this->assertFalse($zip->locateName('storage/framework/testing/private-zip/backups/app/old.zip'));
        $zip->close();
        File::deleteDirectory($root);
    }

    public function test_a_predeploy_backup_is_sent_off_the_server_too(): void
    {
        $drive = $this->fakeDrive();
        $this->driveSettings();
        $this->sqliteConnection('deploy_backup');
        Storage::fake('local');
        Config::set('backup.encryption.key', self::KEY);

        $migrations = 'storage/framework/testing/offsite-predeploy';
        File::ensureDirectoryExists(base_path($migrations));
        File::put(base_path($migrations.'/2026_01_01_000001_offsite_probe.php'), <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('offsite_probe', fn (Blueprint $table) => $table->id());
                }

                public function down(): void
                {
                    Schema::dropIfExists('offsite_probe');
                }
            };
            PHP);

        try {
            $this->assertSame(0, Artisan::call('deploy:migrate', ['--path' => $migrations]));
        } finally {
            File::deleteDirectory(base_path($migrations));
        }

        $this->assertCount(1, $drive->sent);
        $this->assertMatchesRegularExpression('/^database-backup-.*-predeploy\.sqlite\.enc$/', array_key_first($drive->sent));
    }

    // --- the Drive client itself ----------------------------------------

    private function driveClient(): GoogleDriveBackupService
    {
        Config::set('backup.google_drive.folder_id', 'drive-folder-id');

        return new class extends GoogleDriveBackupService
        {
            protected function accessToken(): string
            {
                return 'test-token';
            }
        };
    }

    private function bigFile(int $size): string
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/app/big.zip.enc', str_repeat('A', $size));

        return Storage::disk('local')->path('backups/app/big.zip.enc');
    }

    /** @return array<int, string> the Content-Range of every piece that was sent to the upload session, in order */
    private function sentRanges(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT')
            ->map(fn (array $pair): string => $pair[0]->header('Content-Range')[0] ?? '(whole file)')
            ->values()
            ->all();
    }

    public function test_a_big_file_is_uploaded_in_pieces(): void
    {
        Config::set('backup.google_drive.chunk_bytes', 262144);
        Http::fake([
            'www.googleapis.com/upload/drive/v3/files*' => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/session/abc']),
            'www.googleapis.com/upload/session/*' => Http::sequence()
                ->push('', 308, ['Range' => 'bytes=0-262143'])
                ->push('', 308, ['Range' => 'bytes=0-524287'])
                ->push(['id' => 'drive-file-id', 'name' => 'big.zip.enc'], 200),
        ]);

        $result = $this->driveClient()->upload($this->bigFile(600000), 'big.zip.enc', 'application/octet-stream');

        $this->assertSame('drive-file-id', $result['id']);
        $this->assertSame(['bytes 0-262143/600000', 'bytes 262144-524287/600000', 'bytes 524288-599999/600000'], $this->sentRanges());
        // Each piece carries only its own bytes.
        $sizes = Http::recorded()->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT')->map(fn (array $pair): int => strlen($pair[0]->body()))->values()->all();
        $this->assertSame([262144, 262144, 75712], $sizes);
    }

    public function test_a_dropped_piece_is_retried_from_where_drive_says_it_stopped(): void
    {
        Sleep::fake();
        Config::set('backup.google_drive.chunk_bytes', 262144);
        Http::fake([
            'www.googleapis.com/upload/drive/v3/files*' => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/session/abc']),
            'www.googleapis.com/upload/session/*' => Http::sequence()
                ->push('', 503)                                      // first piece fails
                ->push('', 308)                                      // "how much do you have?" -> nothing
                ->push('', 308, ['Range' => 'bytes=0-262143'])       // first piece again
                ->push(['id' => 'drive-file-id'], 200),              // second piece completes
        ]);

        $result = $this->driveClient()->upload($this->bigFile(300000), 'big.zip.enc', 'application/octet-stream');

        $this->assertSame('drive-file-id', $result['id']);
        $this->assertSame(['bytes 0-262143/300000', 'bytes */300000', 'bytes 0-262143/300000', 'bytes 262144-299999/300000'], $this->sentRanges());
        Sleep::assertSleptTimes(1);
    }

    public function test_a_permanent_error_stops_at_once(): void
    {
        Sleep::fake();
        Config::set('backup.google_drive.chunk_bytes', 262144);

        Http::fake([
            'www.googleapis.com/upload/drive/v3/files*' => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/session/abc']),
            'www.googleapis.com/upload/session/*' => Http::response(['error' => ['message' => 'Upload session expired']], 404),
        ]);

        try {
            $this->driveClient()->upload($this->bigFile(300000), 'big.zip.enc', 'application/octet-stream');
            $this->fail('An expired session must stop the upload.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Upload session expired', $exception->getMessage());
        }

        $this->assertSame(['bytes 0-262143/300000'], $this->sentRanges());
    }

    public function test_a_connection_that_keeps_failing_gives_up_after_three_tries(): void
    {
        Sleep::fake();
        Config::set('backup.google_drive.chunk_bytes', 262144);
        Http::fake([
            'www.googleapis.com/upload/drive/v3/files*' => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/session/abc']),
            'www.googleapis.com/upload/session/*' => Http::response('', 503),
        ]);

        try {
            $this->driveClient()->upload($this->bigFile(300000), 'big.zip.enc', 'application/octet-stream');
            $this->fail('A connection that keeps failing must give up.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Google Drive upload failed', $exception->getMessage());
        }

        // Three tries of the piece, with a status question between them.
        $this->assertSame(
            ['bytes 0-262143/300000', 'bytes */300000', 'bytes 0-262143/300000', 'bytes */300000', 'bytes 0-262143/300000'],
            $this->sentRanges(),
        );
    }

    public function test_a_small_file_still_goes_in_one_request(): void
    {
        Http::fake([
            'www.googleapis.com/upload/drive/v3/files*' => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/session/abc']),
            'www.googleapis.com/upload/session/*' => Http::response(['id' => 'drive-file-id'], 200),
        ]);

        $this->driveClient()->upload($this->bigFile(1000), 'small.zip.enc', 'application/octet-stream');

        $this->assertSame(['(whole file)'], $this->sentRanges());
    }

    public function test_the_backup_folder_listing_follows_pages_and_stays_inside_the_folder(): void
    {
        Http::fake([
            'www.googleapis.com/drive/v3/files*' => Http::sequence()
                ->push(['files' => [['id' => '1', 'name' => 'a.zip']], 'nextPageToken' => 'page-2'])
                ->push(['files' => [['id' => '2', 'name' => 'b.zip']]]),
        ]);

        $files = $this->driveClient()->listBackupFiles();

        $this->assertSame(['a.zip', 'b.zip'], array_column($files, 'name'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), "'drive-folder-id' in parents")
            && str_contains($request->url(), 'supportsAllDrives=true')
            && ! str_contains($request->url(), 'pageToken'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'pageToken=page-2'));
    }

    public function test_deleting_a_drive_file_treats_gone_as_done_and_surfaces_a_refusal(): void
    {
        Http::fake([
            'www.googleapis.com/drive/v3/files/gone*' => Http::response('', 404),
            'www.googleapis.com/drive/v3/files/ok*' => Http::response('', 204),
            'www.googleapis.com/drive/v3/files/locked*' => Http::response(['error' => ['message' => 'The user does not have sufficient permissions']], 403),
        ]);
        $drive = $this->driveClient();

        $drive->delete('ok');
        $drive->delete('gone');

        try {
            $drive->delete('locked');
            $this->fail('A refusal must not be swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('sufficient permissions', $exception->getMessage());
        }

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'supportsAllDrives=true'));
    }
}
