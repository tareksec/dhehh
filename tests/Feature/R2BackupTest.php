<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\BackupRun;
use App\Models\DeploymentError;
use App\Services\AppBackupService;
use App\Services\BackupEncryptor;
use App\Services\BackupSettingsService;
use App\Services\DatabaseBackupService;
use App\Services\GoogleDriveBackupService;
use App\Services\OffsiteBackupService;
use App\Services\R2BackupService;
use Aws\Exception\AwsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\FakeS3;
use Tests\TestCase;

/**
 * Cloudflare R2 as an off-site place: the settings, the service against an in-memory bucket
 * (FakeS3), and the way OffsiteBackupService uses it together with Google Drive.
 */
class R2BackupTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-encryption-key-123456';

    private const ACCOUNT = '0123456789abcdef0123456789abcdef';

    private FakeS3 $s3;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('backup.r2.part_bytes', 5 * 1024 * 1024);
        $this->work = storage_path('framework/testing/r2-'.str()->random(6));
        File::ensureDirectoryExists($this->work);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);

        parent::tearDown();
    }

    // --- builders --------------------------------------------------------

    /** R2 settings that are complete, switched on, with auto upload. */
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

    /** An R2 service wired to an in-memory bucket, also what the container hands out. */
    private function r2(): R2BackupService
    {
        $this->s3 = new FakeS3;
        $r2 = app(R2BackupService::class)->withClient($this->s3->client());
        $this->app->instance(R2BackupService::class, $r2);

        return $r2;
    }

    private function file(string $name, int $bytes = 2000): string
    {
        $path = $this->work.'/'.$name;
        File::put($path, random_bytes($bytes));

        return $path;
    }

    private function driveSettings(bool $enabled = true, bool $auto = true): void
    {
        app(BackupSettingsService::class)->saveGoogleDrive([
            'enabled' => $enabled, 'auto_upload' => $auto, 'folder_id' => 'folder', 'service_account_path' => '', 'service_account_json' => '',
        ]);
    }

    /** A Google Drive stand-in that keeps what it is sent. */
    private function fakeDrive(): GoogleDriveBackupService
    {
        $drive = new class extends GoogleDriveBackupService
        {
            /** @var array<string, string> */
            public array $sent = [];

            public ?RuntimeException $uploadFails = null;

            public function upload(string $absolutePath, ?string $filename = null, string $mimeType = 'application/zip'): array
            {
                if ($this->uploadFails) {
                    throw $this->uploadFails;
                }

                $this->sent[$filename ?? basename($absolutePath)] = (string) file_get_contents($absolutePath);

                return ['id' => 'drive-file'];
            }

            public function listBackupFiles(): array
            {
                return [];
            }
        };
        $this->app->instance(GoogleDriveBackupService::class, $drive);

        return $drive;
    }

    private function sqliteConnection(): void
    {
        $path = $this->work.'/offsite.sqlite';
        File::put($path, 'sqlite backup contents');
        Config::set('database.connections.r2_test', ['driver' => 'sqlite', 'database' => $path]);
        Config::set('backup.app.database_connection', 'r2_test');
    }

    // --- settings --------------------------------------------------------

    public function test_the_secret_is_stored_encrypted_and_a_blank_keeps_it(): void
    {
        $this->r2Settings();

        $row = AppSetting::query()->where('key', BackupSettingsService::R2_SECRET_ACCESS_KEY)->sole();
        $this->assertTrue($row->is_encrypted);
        $this->assertStringNotContainsString('super-secret', (string) $row->value);
        $this->assertSame('super-secret', app(BackupSettingsService::class)->r2SecretAccessKey());

        $this->r2Settings(['secret_access_key' => '', 'bucket' => 'another-bucket']);

        $this->assertSame('super-secret', app(BackupSettingsService::class)->r2SecretAccessKey(), 'Saving without a secret keeps the stored one.');
        $this->assertSame('another-bucket', app(BackupSettingsService::class)->r2Bucket());
    }

    public function test_the_account_id_or_an_address_becomes_the_endpoint(): void
    {
        $settings = app(BackupSettingsService::class);

        $this->r2Settings(['account' => strtoupper(self::ACCOUNT)]);
        $this->assertSame('https://'.self::ACCOUNT.'.r2.cloudflarestorage.com', $settings->r2Endpoint());

        $this->r2Settings(['account' => 'https://'.self::ACCOUNT.'.eu.r2.cloudflarestorage.com/']);
        $this->assertSame('https://'.self::ACCOUNT.'.eu.r2.cloudflarestorage.com', $settings->r2Endpoint());

        $this->r2Settings(['account' => 'not an account']);
        $this->assertNull($settings->r2Endpoint());
        $this->assertContains('Account ID (32 letters and digits) or the endpoint address (https://...)', app(R2BackupService::class)->missingSettings());
    }

    public function test_the_environment_fills_in_what_was_not_saved_and_a_saved_value_wins(): void
    {
        Config::set('backup.r2.bucket', 'from-env');
        Config::set('backup.r2.prefix', 'env-folder');

        $settings = app(BackupSettingsService::class);
        $this->assertSame('from-env', $settings->r2Bucket());
        $this->assertSame('env-folder', $settings->r2Prefix());

        $this->r2Settings(['bucket' => 'saved', 'prefix' => '/saved/folder/']);
        $this->assertSame('saved', $settings->r2Bucket());
        $this->assertSame('saved/folder', $settings->r2Prefix(), 'Slashes at the ends are dropped.');
    }

    public function test_the_service_says_what_is_missing_and_needs_the_switch_to_count_as_configured(): void
    {
        $r2 = app(R2BackupService::class);

        $this->assertSame(['Account ID', 'Bucket name', 'Access Key ID', 'Secret Access Key'], $r2->missingSettings());
        $this->assertFalse($r2->isConfigured());

        try {
            $r2->assertReady();
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cloudflare R2 backup is not set up: Account ID, Bucket name, Access Key ID, Secret Access Key still to be filled in.', $exception->getMessage());
        }

        $this->r2Settings(['enabled' => false]);
        $this->assertSame([], $r2->missingSettings());
        $this->assertFalse($r2->isConfigured(), 'Complete but switched off.');

        $this->r2Settings();
        $this->assertTrue($r2->isConfigured());
    }

    public function test_the_real_client_uses_the_endpoint_path_style_and_r2_safe_checksums(): void
    {
        $this->r2Settings();
        $r2 = new class(app(BackupSettingsService::class)) extends R2BackupService
        {
            public function exposedClient()
            {
                return $this->client();
            }
        };

        $client = $r2->exposedClient();

        $this->assertSame(self::ACCOUNT.'.r2.cloudflarestorage.com', $client->getEndpoint()->getHost());
        $this->assertSame('auto', $client->getRegion());
        $this->assertTrue($client->getConfig('use_path_style_endpoint'));
        $this->assertSame('when_required', $client->getConfig('request_checksum_calculation'));
        $this->assertSame('when_required', $client->getConfig('response_checksum_validation'));
    }

    // --- the service -----------------------------------------------------

    public function test_a_small_file_goes_up_in_one_request_into_the_folder_and_is_checked(): void
    {
        $this->r2Settings();
        $r2 = $this->r2();
        $path = $this->file('database-backup-20261023-120500-mysql-hourly.sql.gz.enc', 3000);

        $result = $r2->upload($path, basename($path), 'application/octet-stream');

        $this->assertSame('erp-backups/database-backup-20261023-120500-mysql-hourly.sql.gz.enc', $result['id']);
        $this->assertSame(3000, $result['size']);
        $this->assertSame('erp-backups-bucket', $result['bucket']);
        $this->assertSame(file_get_contents($path), $this->s3->objects[$result['id']]);
        $this->assertSame(1, $this->s3->count('PutObject'));
        $this->assertSame(0, $this->s3->count('CreateMultipartUpload'));
        $this->assertSame(1, $this->s3->count('HeadObject'), 'The stored size is checked.');
        $this->assertSame('application/octet-stream', $this->s3->calls[0][1]['ContentType']);
        $this->assertSame('erp-backups-bucket', $this->s3->calls[0][1]['Bucket']);
    }

    public function test_a_blank_folder_means_the_default_folder_and_only_the_environment_can_choose_none(): void
    {
        $this->r2Settings(['prefix' => '']);
        $r2 = $this->r2();
        $path = $this->file('app-backup-20261023-020000-daily.zip.enc');

        $this->assertSame('erp-backups/app-backup-20261023-020000-daily.zip.enc', $r2->upload($path)['id'], 'The bucket is never used at its top level by accident.');

        Config::set('backup.r2.prefix', '');
        $this->r2Settings(['prefix' => '']);
        $this->assertSame('app-backup-20261023-020000-daily.zip.enc', $r2->upload($path)['id']);
    }

    public function test_a_big_file_goes_up_in_parts_and_arrives_whole(): void
    {
        $this->r2Settings();
        $r2 = $this->r2();
        $path = $this->file('app-backup-20261023-020000-daily.zip.enc', 11 * 1024 * 1024);

        $result = $r2->upload($path);

        $this->assertSame(1, $this->s3->count('CreateMultipartUpload'));
        $this->assertSame(3, $this->s3->count('UploadPart'), '5 MiB + 5 MiB + the rest.');
        $this->assertSame(1, $this->s3->count('CompleteMultipartUpload'));
        $this->assertSame(0, $this->s3->count('PutObject'));
        $this->assertSame(hash_file('sha256', $path), hash('sha256', $this->s3->objects[$result['id']]));
        $this->assertSame([], $this->s3->uploads, 'No unfinished upload is left.');
    }

    public function test_a_part_that_fails_is_retried_from_where_the_upload_stopped(): void
    {
        Sleep::fake();
        $this->r2Settings();
        $r2 = $this->r2();
        $this->s3->fail = fn ($command, int $nth): ?string => $command->getName() === 'UploadPart' && $nth === 2 ? 'InternalError' : null;
        $path = $this->file('app-backup-20261023-020000-daily.zip.enc', 11 * 1024 * 1024);

        $result = $r2->upload($path);

        $this->assertSame(hash_file('sha256', $path), hash('sha256', $this->s3->objects[$result['id']]));
        $this->assertSame(1, $this->s3->count('CreateMultipartUpload'), 'The upload was resumed, not started again.');
        $this->assertSame(4, $this->s3->count('UploadPart'), 'Three parts and the one that was repeated.');
    }

    public function test_an_upload_that_keeps_failing_is_aborted_and_leaves_nothing_behind(): void
    {
        Sleep::fake();
        $this->r2Settings();
        $r2 = $this->r2();
        $this->s3->fail = fn ($command): ?string => $command->getName() === 'UploadPart' ? 'InternalError' : null;
        $path = $this->file('app-backup-20261023-020000-daily.zip.enc', 11 * 1024 * 1024);

        try {
            $r2->upload($path);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('Cloudflare R2:', $exception->getMessage());
        }

        $this->assertSame(1, $this->s3->count('AbortMultipartUpload'));
        $this->assertSame([], $this->s3->uploads);
        $this->assertSame([], $this->s3->objects);
    }

    public function test_an_object_of_the_wrong_size_is_removed_and_reported(): void
    {
        $this->r2Settings();
        $r2 = $this->r2();
        $this->s3->shortBy = 1;
        $path = $this->file('database-backup-20261023-120500-mysql-hourly.sql.gz.enc', 3000);

        try {
            $r2->upload($path);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('stored 2999 bytes of 3000', $exception->getMessage());
        }

        $this->assertSame([], $this->s3->objects);
    }

    public function test_listing_returns_the_files_of_the_folder_across_pages_and_nothing_else(): void
    {
        $this->r2Settings();
        $r2 = $this->r2();
        $this->s3->put('erp-backups/database-backup-20261023-100500-mysql-hourly.sql.gz.enc', 'aaa', new \DateTimeImmutable('2026-10-23 10:05:00'));
        $this->s3->put('erp-backups/database-backup-20261023-110500-mysql-hourly.sql.gz.enc', 'bbbb');
        $this->s3->put('erp-backups/app-backup-20261023-020000-daily.zip.enc', 'c');
        $this->s3->put('erp-backups/notes.txt', 'd');
        $this->s3->put('erp-backups/_health/connection-test-1.txt', 'ok');
        $this->s3->put('erp-backups-other/app-backup-20200101-000000-daily.zip', 'x');
        $this->s3->put('media/photo.jpg', 'x');

        $files = collect($r2->listBackupFiles());

        $this->assertSame(
            ['app-backup-20261023-020000-daily.zip.enc', 'database-backup-20261023-100500-mysql-hourly.sql.gz.enc', 'database-backup-20261023-110500-mysql-hourly.sql.gz.enc', 'notes.txt'],
            $files->pluck('name')->sort()->values()->all(),
        );
        $this->assertSame(2, $this->s3->count('ListObjectsV2'), 'Four files are two pages.');

        $first = $files->firstWhere('name', 'database-backup-20261023-100500-mysql-hourly.sql.gz.enc');
        $this->assertSame('erp-backups/database-backup-20261023-100500-mysql-hourly.sql.gz.enc', $first['id']);
        $this->assertSame(3, $first['size']);
        $this->assertStringStartsWith('2026-10-23T10:05:00', $first['createdTime']);
    }

    public function test_a_file_is_downloaded_to_disk_and_a_failed_download_leaves_no_file(): void
    {
        $this->r2Settings();
        $r2 = $this->r2();
        $this->s3->put('erp-backups/a.enc', 'the secret bytes');

        $r2->download('erp-backups/a.enc', $this->work.'/sub/copy.enc');
        $this->assertSame('the secret bytes', file_get_contents($this->work.'/sub/copy.enc'));

        File::put($this->work.'/gone.enc', 'old partial');
        try {
            $r2->download('erp-backups/missing.enc', $this->work.'/gone.enc');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('Cloudflare R2:', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($this->work.'/gone.enc');
    }

    public function test_only_files_inside_the_folder_can_be_downloaded_or_deleted(): void
    {
        $this->r2Settings();
        $r2 = $this->r2();
        $this->s3->put('erp-backups/a.enc', 'x');
        $this->s3->put('media/photo.jpg', 'x');
        $this->s3->put('erp-backups/sub/b.enc', 'x');

        foreach (['media/photo.jpg', 'erp-backups/sub/b.enc', 'erp-backups/../media/photo.jpg', 'erp-backups-other/a.enc'] as $key) {
            try {
                $r2->delete($key);
                $this->fail("[{$key}] must be refused.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('not in the backup folder', $exception->getMessage());
            }

            try {
                $r2->download($key, $this->work.'/x');
                $this->fail("[{$key}] must be refused.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('not in the backup folder', $exception->getMessage());
            }
        }

        $this->assertArrayHasKey('media/photo.jpg', $this->s3->objects);
        $this->assertSame(0, $this->s3->count('DeleteObject'));

        $r2->delete('erp-backups/a.enc');
        $this->assertArrayNotHasKey('erp-backups/a.enc', $this->s3->objects);
    }

    public function test_a_failure_is_explained_in_words_an_admin_can_act_on(): void
    {
        $this->r2Settings();
        $r2 = $this->r2();
        $path = $this->file('database-backup-20261023-120500-mysql-hourly.sql.gz.enc');

        foreach ([
            'AccessDenied' => 'Object Read & Write',
            'NoSuchBucket' => 'the bucket "erp-backups-bucket" was not found',
            'InvalidAccessKeyId' => 'Access Key ID is not known',
            'SignatureDoesNotMatch' => 'Secret Access Key is wrong',
        ] as $code => $expected) {
            $this->s3->fail = fn (): string => $code;

            try {
                $r2->upload($path);
                $this->fail("[{$code}] must fail.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($expected, $exception->getMessage(), $code);
                $this->assertInstanceOf(AwsException::class, $exception->getPrevious());
            }
        }
    }

    public function test_the_connection_test_writes_reads_lists_and_deletes_and_says_what_failed(): void
    {
        $this->r2Settings();
        $r2 = $this->r2();

        $ok = $r2->testConnection();

        $this->assertTrue($ok['ok']);
        $this->assertStringContainsString('wrote, read, listed and deleted', $ok['message']);
        $this->assertSame([], $this->s3->objects, 'The test file is removed again.');
        foreach (['PutObject', 'GetObject', 'ListObjectsV2', 'DeleteObject'] as $operation) {
            $this->assertSame(1, $this->s3->count($operation), $operation);
        }

        $this->s3->fail = fn ($command): ?string => $command->getName() === 'PutObject' ? 'AccessDenied' : null;
        $bad = $r2->testConnection();
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('Object Read & Write', $bad['message']);

        $this->r2Settings(['bucket' => '']);
        $this->assertFalse($r2->testConnection()['ok']);
        $this->assertStringContainsString('Bucket name', $r2->testConnection()['message']);
    }

    // --- with the off-site service ----------------------------------------

    public function test_one_encrypted_copy_goes_to_every_place_that_is_on_and_the_run_says_where(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        $this->sqliteConnection();
        $this->r2Settings();
        $this->r2();
        $this->driveSettings();
        $drive = $this->fakeDrive();

        $backup = app(DatabaseBackupService::class)->create('r2_test', BackupRun::KIND_HOURLY, upload: true);

        $this->assertSame(['r2', 'drive'], array_keys($backup['offsite']['destinations']));
        $this->assertSame([], $backup['offsite']['failed']);
        $name = array_key_first($drive->sent);
        $this->assertStringEndsWith('-hourly.sqlite.enc', $name);
        $this->assertSame($drive->sent[$name], $this->s3->objects['erp-backups/'.$name], 'The same encrypted file went to both.');
        $this->assertNotSame('sqlite backup contents', $drive->sent[$name]);

        $run = BackupRun::query()->sole();
        $this->assertSame('r2,drive', $run->offsite_to);
        $this->assertNotNull($run->offsite_at);
        $this->assertSame(['r2', 'drive'], $run->offsitePlaces());
    }

    public function test_when_one_place_fails_the_backup_still_counts_and_the_failure_is_reported(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        $this->sqliteConnection();
        $this->r2Settings();
        $this->r2();
        $this->s3->fail = fn ($command): ?string => $command->getName() === 'PutObject' && ($command['Key'] ?? '') !== '' && str_contains((string) $command['Key'], 'database-backup') ? 'AccessDenied' : null;
        $this->driveSettings();
        $drive = $this->fakeDrive();

        $backup = app(DatabaseBackupService::class)->create('r2_test', BackupRun::KIND_HOURLY, upload: true);

        $this->assertSame(['drive'], array_keys($backup['offsite']['destinations']));
        $this->assertSame(['r2'], array_keys($backup['offsite']['failed']));
        $this->assertCount(1, $drive->sent);

        $run = BackupRun::query()->sole();
        $this->assertSame('success', $run->status);
        $this->assertSame('drive', $run->offsite_to);
        $this->assertStringContainsString('Not sent to r2: Cloudflare R2: access was refused', (string) $run->note);
        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:offsite:r2')->count());
    }

    public function test_when_every_place_fails_the_upload_is_an_error_that_names_each(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        $this->sqliteConnection();
        $this->r2Settings();
        $this->r2();
        $this->s3->fail = fn ($command): ?string => $command->getName() === 'PutObject' ? 'AccessDenied' : null;
        $this->driveSettings();
        $drive = $this->fakeDrive();
        $drive->uploadFails = new RuntimeException('Google Drive upload failed.');

        try {
            app(DatabaseBackupService::class)->create('r2_test', BackupRun::KIND_HOURLY, upload: true);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('[Cloudflare R2] Cloudflare R2: access was refused', $exception->getMessage());
            $this->assertStringContainsString('[Google Drive] Google Drive upload failed.', $exception->getMessage());
        }

        $run = BackupRun::query()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertNull($run->offsite_at);
    }

    public function test_a_scheduled_backup_goes_only_to_places_with_auto_upload_but_a_person_reaches_all_that_are_on(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        $this->sqliteConnection();
        $this->r2Settings(['auto_upload' => false]);
        $this->r2();
        $this->driveSettings(enabled: true, auto: true);
        $drive = $this->fakeDrive();
        $offsite = app(OffsiteBackupService::class);

        $this->assertTrue($offsite->shouldAutoUpload('database', 'hourly'));
        $this->assertSame(['drive'], array_keys($offsite->autoDestinations()));

        $auto = app(DatabaseBackupService::class)->create('r2_test', BackupRun::KIND_HOURLY, upload: true, automatic: true);
        $this->assertSame(['drive'], array_keys($auto['offsite']['destinations']));
        $this->assertSame([], $this->s3->objects);

        $manual = app(DatabaseBackupService::class)->create('r2_test', BackupRun::KIND_MANUAL, upload: true);
        $this->assertSame(['r2', 'drive'], array_keys($manual['offsite']['destinations']));
        $this->assertCount(1, $this->s3->objects);
        $this->assertCount(2, $drive->sent);

        // No place takes automatic uploads: nothing is scheduled to go off-site.
        $this->r2Settings(['auto_upload' => false]);
        $this->driveSettings(enabled: true, auto: false);
        $this->assertFalse($offsite->shouldAutoUpload('database', 'hourly'));
    }

    public function test_asking_for_an_upload_by_hand_reaches_a_place_that_is_set_up_but_switched_off(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        $this->sqliteConnection();
        $this->r2Settings(['enabled' => false, 'auto_upload' => false]);
        $this->r2();

        $backup = app(DatabaseBackupService::class)->create('r2_test', BackupRun::KIND_MANUAL, upload: true);

        $this->assertSame(['r2'], array_keys($backup['offsite']['destinations']));
        $this->assertCount(1, $this->s3->objects);
    }

    public function test_with_no_place_set_up_the_upload_says_so_and_nothing_is_encrypted(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        $this->sqliteConnection();

        try {
            app(DatabaseBackupService::class)->create('r2_test', BackupRun::KIND_MANUAL, upload: true);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No off-site place is set up', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->files('backups/tmp'));
    }

    public function test_a_place_that_is_not_complete_is_left_out_before_anything_is_encrypted(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        $this->sqliteConnection();
        $this->r2Settings(['bucket' => '']);
        $this->r2();
        $this->driveSettings();
        $drive = $this->fakeDrive();

        $backup = app(DatabaseBackupService::class)->create('r2_test', BackupRun::KIND_HOURLY, upload: true);

        $this->assertSame(['drive'], array_keys($backup['offsite']['destinations']));
        $this->assertStringContainsString('Bucket name', $backup['offsite']['failed']['r2']);
        $this->assertCount(1, $drive->sent);
    }

    public function test_the_unencrypted_guard_applies_to_r2_as_well(): void
    {
        Config::set('backup.encryption.key', null);
        $this->sqliteConnection();
        $this->r2Settings();
        $this->r2();

        try {
            app(DatabaseBackupService::class)->create('r2_test', BackupRun::KIND_HOURLY, upload: true);
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('BACKUP_ENCRYPTION_KEY', $exception->getMessage());
        }

        $this->assertSame([], $this->s3->objects);
    }

    public function test_the_full_app_backup_goes_to_r2_too(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        $this->sqliteConnection();
        $this->r2Settings();
        $this->r2();

        $backup = app(AppBackupService::class)->create(upload: true, kind: BackupRun::KIND_DAILY, automatic: true);

        $this->assertSame(['r2'], array_keys($backup['offsite']['destinations']));
        $this->assertCount(1, array_filter(array_keys($this->s3->objects), fn (string $key): bool => preg_match('#^erp-backups/app-backup-\d{8}-\d{6}-daily\.zip\.enc$#', $key) === 1));
        $this->assertSame('r2', BackupRun::query()->where('scope', 'app')->sole()->offsite_to);
    }

    public function test_each_place_is_trimmed_to_the_newest_copies_of_each_kind_and_only_our_files_go(): void
    {
        $this->r2Settings();
        $this->r2();
        Config::set('backup.offsite.keep.database.hourly', 2);

        foreach (['100500', '110500', '120500', '130500'] as $time) {
            $this->s3->put("erp-backups/database-backup-20261023-{$time}-mysql-hourly.sql.gz.enc");
        }
        $this->s3->put('erp-backups/database-backup-20261023-020000-mysql-daily.sql.gz.enc');
        $this->s3->put('erp-backups/holiday-photos.zip');
        $this->s3->put('erp-backups/notes.txt');
        $this->s3->put('media/database-backup-20200101-000000-mysql-hourly.sql.gz.enc');

        $removed = app(OffsiteBackupService::class)->prune();

        $this->assertSame(2, $removed);
        $this->assertEqualsCanonicalizing([
            'erp-backups/database-backup-20261023-120500-mysql-hourly.sql.gz.enc',
            'erp-backups/database-backup-20261023-130500-mysql-hourly.sql.gz.enc',
            'erp-backups/database-backup-20261023-020000-mysql-daily.sql.gz.enc',
            'erp-backups/holiday-photos.zip',
            'erp-backups/notes.txt',
            'media/database-backup-20200101-000000-mysql-hourly.sql.gz.enc',
        ], array_keys($this->s3->objects));
    }

    public function test_the_newest_database_copy_on_r2_is_found_and_fetched_decrypted(): void
    {
        Config::set('backup.encryption.key', self::KEY);
        $this->r2Settings();
        $this->r2();
        $plain = $this->work.'/plain.sql.gz';
        File::put($plain, (string) gzencode('-- dump'));
        app(BackupEncryptor::class)->encryptFile($plain, $this->work.'/new.enc', self::KEY);
        $this->s3->put('erp-backups/database-backup-20261022-120500-mysql-hourly.sql.gz.enc', 'older');
        $this->s3->put('erp-backups/database-backup-20261023-120500-mysql-hourly.sql.gz.enc', (string) file_get_contents($this->work.'/new.enc'));
        $this->s3->put('erp-backups/app-backup-20261024-020000-daily.zip.enc', 'an archive is not a dump');
        $offsite = app(OffsiteBackupService::class);

        $copy = $offsite->latestDatabaseDump('r2');

        $this->assertSame('database-backup-20261023-120500-mysql-hourly.sql.gz.enc', $copy['name']);
        $this->assertSame('r2', $copy['destination']);

        $path = $offsite->fetch($copy, $this->work.'/fetched');

        $this->assertSame(file_get_contents($plain), file_get_contents($path));
        $this->assertSame([$path], array_map(fn ($file): string => $file->getPathname(), File::files($this->work.'/fetched')), 'The encrypted download is removed.');
    }

    public function test_the_place_to_read_from_is_the_one_named_else_r2_first(): void
    {
        $offsite = app(OffsiteBackupService::class);

        try {
            $offsite->destination();
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No off-site place is set up', $exception->getMessage());
        }

        $this->driveSettings();
        $this->assertSame('drive', $offsite->destination()->key());

        $this->r2Settings();
        $this->assertSame('r2', $offsite->destination()->key(), 'R2 comes first.');
        $this->assertSame('drive', $offsite->destination('drive')->key());

        $this->expectExceptionMessage('Unknown off-site place [s3]');
        $offsite->destination('s3');
    }
}
