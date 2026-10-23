<?php

namespace App\Console\Commands;

use App\Services\BackupHealth;
use App\Services\DeploymentErrorReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class CheckBackupHealth extends Command
{
    /** While a check stays stale it is reported again after this many hours, not every run. */
    private const REMIND_AFTER_HOURS = 12;

    protected $signature = 'backup:check';

    protected $description = 'Tell the Super Admins when a backup is overdue (exit code 1 when one is)';

    public function handle(BackupHealth $health, DeploymentErrorReporter $reporter): int
    {
        $checks = $health->checks();
        $overdue = false;

        foreach ($checks as $check) {
            $remembered = "backup-health-alerted:{$check['key']}";

            if ($check['state'] === BackupHealth::DANGER) {
                $overdue = true;

                // Cache::add is false when the alert was already sent within the reminder window.
                if (Cache::add($remembered, true, now()->addHours(self::REMIND_AFTER_HOURS))) {
                    try {
                        $reporter->report("backup:health:{$check['key']}", new RuntimeException("{$check['label']}: {$check['detail']}"), ['check' => $check['key']]);
                    } catch (Throwable) {
                        // Reporting is best effort; the exit code and the dashboard card still say it.
                    }
                }
            } elseif ($check['state'] === BackupHealth::OK) {
                Cache::forget($remembered);
            }
        }

        $this->table(['Check', 'State', 'Detail'], array_map(
            fn (array $check): array => [$check['label'], $check['state'], $check['detail']],
            $checks,
        ));

        return $overdue ? self::FAILURE : self::SUCCESS;
    }
}
