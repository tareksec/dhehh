<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\DeploymentError;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeployMigrateCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Outside database/migrations on purpose -- a migration dropped there
     * would run for every other test in the suite too, not just this one.
     */
    protected string $tempMigrationPath = 'storage/framework/testing/deploy-migrate-failure';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->tempMigrationPath));

        parent::tearDown();
    }

    public function test_it_runs_pending_migrations_and_exits_successfully(): void
    {
        // RefreshDatabase has already applied every real migration, so this
        // exercises the genuine "nothing pending" success path end-to-end.
        $exitCode = Artisan::call('deploy:migrate');

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('deployment_errors', 0);
    }

    public function test_it_reports_a_failing_migration_instead_of_failing_the_command(): void
    {
        $this->useWorkingBackupConnection();
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->writeFailingMigration();

        $exitCode = Artisan::call('deploy:migrate', ['--path' => $this->tempMigrationPath]);

        $this->assertSame(0, $exitCode, 'A broken migration must not prevent the app from starting.');

        $this->assertDatabaseHas('deployment_errors', ['source' => 'migration']);
        $error = \App\Models\DeploymentError::query()->sole();
        $this->assertStringContainsString('simulated migration failure', $error->message);

        $this->assertSame(1, $superAdmin->fresh()->notifications()->count());
    }

    public function test_nothing_is_backed_up_when_no_migration_is_pending(): void
    {
        Storage::fake('local');

        Artisan::call('deploy:migrate');

        $this->assertSame(0, BackupRun::query()->count());
        $this->assertSame([], app(DatabaseBackupService::class)->all());
    }

    public function test_a_database_backup_is_taken_before_pending_migrations_run(): void
    {
        $this->useWorkingBackupConnection();
        $this->writePendingMigration();

        $exitCode = Artisan::call('deploy:migrate', ['--path' => $this->tempMigrationPath]);

        $this->assertSame(0, $exitCode);
        $run = BackupRun::query()->sole();
        $this->assertSame(['database', 'predeploy', 'success'], [$run->scope, $run->kind, $run->status]);
        Storage::disk('local')->assertExists(DatabaseBackupService::DIRECTORY.'/'.$run->filename);
        $this->assertTrue(Schema::hasTable('predeploy_probe'), 'the pending migration must still run');
        $this->assertSame(0, DeploymentError::query()->count());
    }

    public function test_a_failed_backup_is_reported_but_never_stops_the_migration(): void
    {
        Storage::fake('local');
        Config::set('database.connections.deploy_backup', ['driver' => 'sqlite', 'database' => '/nonexistent/db.sqlite']);
        Config::set('backup.app.database_connection', 'deploy_backup');
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->writePendingMigration();

        $exitCode = Artisan::call('deploy:migrate', ['--path' => $this->tempMigrationPath]);

        $this->assertSame(0, $exitCode, 'A failing backup must not prevent the app from starting.');
        $this->assertSame('failed', BackupRun::query()->sole()->status);
        $this->assertTrue(Schema::hasTable('predeploy_probe'), 'the migration must still run');
        $this->assertSame(1, DeploymentError::query()->where('source', 'backup:database:predeploy')->count());
        $this->assertSame(1, $superAdmin->fresh()->notifications()->count());
    }

    /** A working database to back up, so a test about migrations does not also trip over the backup. */
    protected function useWorkingBackupConnection(): void
    {
        Storage::fake('local');
        $database = storage_path('framework/testing/deploy-backup-source.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::put($database, 'database before the migration');
        Config::set('database.connections.deploy_backup', ['driver' => 'sqlite', 'database' => $database]);
        Config::set('backup.app.database_connection', 'deploy_backup');
    }

    protected function writePendingMigration(): void
    {
        File::ensureDirectoryExists(base_path($this->tempMigrationPath));

        File::put(
            base_path($this->tempMigrationPath.'/2026_01_01_000001_predeploy_probe_test.php'),
            <<<'PHP'
                <?php

                use Illuminate\Database\Migrations\Migration;
                use Illuminate\Database\Schema\Blueprint;
                use Illuminate\Support\Facades\Schema;

                return new class extends Migration
                {
                    public function up(): void
                    {
                        Schema::create('predeploy_probe', function (Blueprint $table) {
                            $table->id();
                        });
                    }

                    public function down(): void
                    {
                        Schema::dropIfExists('predeploy_probe');
                    }
                };
                PHP,
        );
    }

    protected function writeFailingMigration(): void
    {
        File::ensureDirectoryExists(base_path($this->tempMigrationPath));

        File::put(
            base_path($this->tempMigrationPath.'/2026_01_01_000000_deploy_migrate_failure_test.php'),
            <<<'PHP'
                <?php

                use Illuminate\Database\Migrations\Migration;

                return new class extends Migration
                {
                    public function up(): void
                    {
                        throw new \RuntimeException('simulated migration failure');
                    }

                    public function down(): void
                    {
                        //
                    }
                };
                PHP,
        );
    }
}
