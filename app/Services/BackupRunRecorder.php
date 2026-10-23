<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wraps one backup so it leaves a `backup_runs` row: running, then success
 * (file name, size, checksum) or failed (the reason).
 *
 * Writing the record must never be the reason a backup does not happen: a
 * pre-deploy backup runs before `migrate`, when the table may not exist yet,
 * and a broken database fails the backup itself anyway. So every database
 * call here is guarded and a problem is only logged.
 *
 * A scheduled or deploy backup that fails is also reported to the Super
 * Admins (Deploy Error Logs + bell), but only for the first failure in a row:
 * an hourly job that keeps failing must not send 24 alerts a day.
 */
class BackupRunRecorder
{
    /**
     * @param  callable(): array{name?: string, size?: int, sha256?: string|null, note?: string, google_drive?: array}  $work
     * @return array<string, mixed> what $work returned
     */
    public function record(string $scope, string $kind, callable $work): array
    {
        $previousFailed = (bool) $this->safely(fn (): bool => $this->lastFinishedStatus($scope, $kind) === BackupRun::STATUS_FAILED);
        $run = $this->safely(fn (): ?BackupRun => $this->start($scope, $kind));

        try {
            $result = $work();
        } catch (Throwable $exception) {
            $this->safely(fn () => $run?->update([
                'status' => BackupRun::STATUS_FAILED,
                'error' => Str::limit($exception->getMessage(), 2000, ''),
                'finished_at' => now(),
            ]));

            if ($kind !== BackupRun::KIND_MANUAL && ! $previousFailed) {
                $this->safely(fn () => app(DeploymentErrorReporter::class)->report(
                    "backup:{$scope}:{$kind}",
                    $exception,
                    ['scope' => $scope, 'kind' => $kind],
                ));
            }

            throw $exception;
        }

        $this->safely(function () use ($run, $result): void {
            $values = [
                'status' => BackupRun::STATUS_SUCCESS,
                'filename' => $result['name'] ?? null,
                'size_bytes' => $result['size'] ?? null,
                'sha256' => $result['sha256'] ?? null,
                'finished_at' => now(),
            ];

            // Columns added after the table: a deploy takes its pre-migration backup before
            // they exist, and that run must still be recorded as a success.
            if (isset($result['google_drive']) && Schema::hasColumn('backup_runs', 'offsite_at')) {
                $values['offsite_at'] = now();
            }

            if (filled($result['note'] ?? null) && Schema::hasColumn('backup_runs', 'note')) {
                $values['note'] = Str::limit((string) $result['note'], 500, '');
            }

            $run?->update($values);
        });

        return $result;
    }

    /** The checksum recorded when this file was made, or null when none was recorded. */
    public function recordedChecksum(string $filename): ?string
    {
        return $this->safely(function () use ($filename): ?string {
            if (! Schema::hasTable('backup_runs')) {
                return null;
            }

            return BackupRun::query()
                ->where('filename', $filename)
                ->where('status', BackupRun::STATUS_SUCCESS)
                ->whereNotNull('sha256')
                ->latest('id')
                ->value('sha256');
        });
    }

    protected function start(string $scope, string $kind): ?BackupRun
    {
        if (! Schema::hasTable('backup_runs')) {
            return null;
        }

        // A run that never finished (the container restarted in the middle of
        // it) would stay "running" for ever: close it before starting a new one.
        BackupRun::query()
            ->where('scope', $scope)
            ->where('kind', $kind)
            ->where('status', BackupRun::STATUS_RUNNING)
            ->where('started_at', '<', now()->subSeconds((int) config('backup.database.timeout', 900) + 300))
            ->update([
                'status' => BackupRun::STATUS_FAILED,
                'error' => 'Interrupted before it finished (the server may have restarted).',
                'finished_at' => now(),
            ]);

        return BackupRun::query()->create([
            'scope' => $scope,
            'kind' => $kind,
            'status' => BackupRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    protected function lastFinishedStatus(string $scope, string $kind): ?string
    {
        if (! Schema::hasTable('backup_runs')) {
            return null;
        }

        return BackupRun::query()
            ->where('scope', $scope)
            ->where('kind', $kind)
            ->whereIn('status', [BackupRun::STATUS_SUCCESS, BackupRun::STATUS_FAILED])
            ->latest('id')
            ->value('status');
    }

    protected function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            Log::warning('Could not record the backup run: '.$exception->getMessage());

            return null;
        }
    }
}
