<?php

namespace App\Services;

use App\Models\BackupRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Are the backups actually happening? One answer for the dashboard card, the
 * `backup:check` alert and the /health/backups address an outside monitor can poll.
 *
 * Each check looks at the newest successful run in `backup_runs` and compares its age
 * with what the schedule promises (config backup.health). A backup that was never made
 * is not an alarm in the first minutes after a deploy, so "nothing recorded yet" is
 * UNKNOWN until backups have been around for longer than the allowed age.
 *
 * What this cannot do: if the scheduler itself has stopped, nothing inside the app runs
 * to notice. That is what /health/backups with an outside uptime monitor is for.
 */
class BackupHealth
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const DANGER = 'danger';

    /** Nothing to judge yet, or the check is not set up. */
    public const UNKNOWN = 'unknown';

    public function __construct(protected BackupSettingsService $settings) {}

    /**
     * @return array<int, array{key: string, label: string, state: string, detail: string, at: ?CarbonInterface, optional: bool}>
     */
    public function checks(): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        $checks = [];

        if (config('backup.hourly.enabled', true)) {
            $checks[] = $this->ageCheck(
                'hourly',
                __('Hourly database backup'),
                BackupRun::SCOPE_DATABASE,
                BackupRun::KIND_HOURLY,
                now()->subMinutes((int) config('backup.health.hourly_max_age_minutes', 150)),
                (int) config('backup.health.hourly_max_age_minutes', 150).' '.__('minutes'),
            );
        }

        $fullApp = (bool) config('backup.daily.full_app', true);
        $checks[] = $this->ageCheck(
            'daily',
            $fullApp ? __('Daily full app backup') : __('Daily database backup'),
            $fullApp ? BackupRun::SCOPE_APP : BackupRun::SCOPE_DATABASE,
            BackupRun::KIND_DAILY,
            now()->subHours((int) config('backup.health.daily_max_age_hours', 30)),
            (int) config('backup.health.daily_max_age_hours', 30).' '.__('hours'),
        );

        if ($this->settings->googleDriveEnabled() && $this->settings->googleDriveAutoUpload()) {
            $checks[] = $this->offsiteCheck();
        }

        $checks[] = $this->drillCheck();

        return $checks;
    }

    /** The worst state among the checks that count (an optional check that is not set up does not). */
    public function overall(): string
    {
        $states = collect($this->checks())
            ->reject(fn (array $check): bool => $check['optional'] && $check['state'] === self::UNKNOWN)
            ->pluck('state');

        return match (true) {
            $states->isEmpty() => self::UNKNOWN,
            $states->contains(self::DANGER) => self::DANGER,
            $states->contains(self::WARNING) => self::WARNING,
            $states->contains(self::UNKNOWN) => self::UNKNOWN,
            default => self::OK,
        };
    }

    /** @return array{key: string, label: string, state: string, detail: string, at: ?CarbonInterface, optional: bool} */
    protected function ageCheck(string $key, string $label, string $scope, string $kind, CarbonInterface $limit, string $allowed): array
    {
        $success = $this->latest($scope, $kind, BackupRun::STATUS_SUCCESS);

        if ($success !== null && $success->finished_at->gte($limit)) {
            return $this->result($key, $label, self::OK, __('Last one :ago.', ['ago' => $success->finished_at->diffForHumans()]), $success->finished_at);
        }

        if ($success !== null) {
            return $this->result($key, $label, self::DANGER, __('The last good one was :ago (more than :allowed ago).', [
                'ago' => $success->finished_at->diffForHumans(),
                'allowed' => $allowed,
            ]).$this->failureNote($scope, $kind, $success->finished_at), $success->finished_at);
        }

        $first = BackupRun::query()->min('created_at');

        if ($first === null) {
            return $this->result($key, $label, self::UNKNOWN, __('No backup has been recorded yet. Check that the scheduler (php artisan schedule:run every minute) is running.'), null);
        }

        // Backups of other kinds exist and this one never succeeded: past the allowed age that is a fault.
        if (Carbon::parse($first)->lt($limit)) {
            return $this->result($key, $label, self::DANGER, __('No successful one has been recorded.').$this->failureNote($scope, $kind, null), null);
        }

        return $this->result($key, $label, self::UNKNOWN, __('Waiting for the first one.'), null);
    }

    /** @return array{key: string, label: string, state: string, detail: string, at: ?CarbonInterface, optional: bool} */
    protected function offsiteCheck(): array
    {
        $label = __('Copy on Google Drive');

        // Recorded since the update that added backup health; before its migration there is nothing to read.
        if (! Schema::hasColumn('backup_runs', 'offsite_at')) {
            return $this->result('offsite', $label, self::UNKNOWN, __('Waiting for the database update.'), null);
        }

        $hourly = config('backup.hourly.enabled', true) && in_array(BackupRun::KIND_HOURLY, (array) config('backup.offsite.database_kinds', []), true);
        $limit = $hourly
            ? now()->subMinutes((int) config('backup.health.hourly_max_age_minutes', 150))
            : now()->subHours((int) config('backup.health.daily_max_age_hours', 30));

        $latest = BackupRun::query()
            ->where('status', BackupRun::STATUS_SUCCESS)
            ->whereNotNull('offsite_at')
            ->max('offsite_at');

        if ($latest !== null && Carbon::parse($latest)->gte($limit)) {
            return $this->result('offsite', $label, self::OK, __('Last one :ago.', ['ago' => Carbon::parse($latest)->diffForHumans()]), Carbon::parse($latest));
        }

        $failure = BackupRun::query()
            ->where('status', BackupRun::STATUS_FAILED)
            ->where('error', 'like', '%Google Drive%')
            ->latest('id')
            ->first();
        $note = $failure !== null ? ' '.__('Last problem: :error', ['error' => Str::limit((string) $failure->error, 160)]) : '';

        if ($latest !== null) {
            return $this->result('offsite', $label, self::DANGER, __('The last copy reached Google Drive :ago.', ['ago' => Carbon::parse($latest)->diffForHumans()]).$note, Carbon::parse($latest));
        }

        $first = BackupRun::query()->min('created_at');

        return $first !== null && Carbon::parse($first)->lt($limit)
            ? $this->result('offsite', $label, self::DANGER, __('No copy has reached Google Drive yet.').$note, null)
            : $this->result('offsite', $label, self::UNKNOWN, __('Waiting for the first copy.'), null);
    }

    /** @return array{key: string, label: string, state: string, detail: string, at: ?CarbonInterface, optional: bool} */
    protected function drillCheck(): array
    {
        $label = __('Restore test');

        if (! (bool) config('backup.drill.enabled')) {
            return $this->result('drill', $label, self::UNKNOWN, __('Not set up. It loads the newest backup into a scratch database to prove it can be restored (see docs/backup-restore.md).'), null, optional: true);
        }

        $days = (int) config('backup.health.drill_max_age_days', 9);
        $success = BackupRun::query()
            ->where('scope', BackupRun::SCOPE_DRILL)
            ->where('status', BackupRun::STATUS_SUCCESS)
            ->latest('finished_at')
            ->first();
        $failed = $this->latest(BackupRun::SCOPE_DRILL, null, BackupRun::STATUS_FAILED);

        if ($failed !== null && ($success === null || $failed->finished_at->gt($success->finished_at))) {
            return $this->result('drill', $label, self::DANGER, __('The last restore test failed :ago: :error', [
                'ago' => $failed->finished_at->diffForHumans(),
                'error' => Str::limit((string) $failed->error, 200),
            ]), $failed->finished_at);
        }

        if ($success !== null && $success->finished_at->gte(now()->subDays($days))) {
            return $this->result('drill', $label, self::OK, __('Last passed :ago.', ['ago' => $success->finished_at->diffForHumans()]), $success->finished_at);
        }

        if ($success !== null) {
            return $this->result('drill', $label, self::WARNING, __('The last restore test was :ago. It should run every week.', ['ago' => $success->finished_at->diffForHumans()]), $success->finished_at);
        }

        return $this->result('drill', $label, self::UNKNOWN, __('Waiting for the first restore test.'), null);
    }

    /** A hint at why, when an attempt failed after the last good one. */
    protected function failureNote(string $scope, ?string $kind, ?CarbonInterface $after): string
    {
        $failure = $this->latest($scope, $kind, BackupRun::STATUS_FAILED);

        if ($failure === null || ($after !== null && $failure->finished_at->lte($after))) {
            return '';
        }

        return ' '.__('Last problem: :error', ['error' => Str::limit((string) $failure->error, 200)]);
    }

    protected function latest(string $scope, ?string $kind, string $status): ?BackupRun
    {
        return BackupRun::query()
            ->where('scope', $scope)
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->where('status', $status)
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();
    }

    /** @return array{key: string, label: string, state: string, detail: string, at: ?CarbonInterface, optional: bool} */
    protected function result(string $key, string $label, string $state, string $detail, ?CarbonInterface $at, bool $optional = false): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail, 'at' => $at, 'optional' => $optional];
    }

    protected function tableExists(): bool
    {
        try {
            return Schema::hasTable('backup_runs');
        } catch (Throwable) {
            return false;
        }
    }
}
