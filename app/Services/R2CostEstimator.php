<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What the R2 bucket is likely to cost, from Cloudflare's published prices (config
 * backup.r2.pricing) and what this app actually does with the bucket.
 *
 * It is an estimate and says so on the page: Cloudflare's own bill is the authority. What it
 * counts:
 *  - Storage: Standard class, per GB-month (1 GB = 1,000,000,000 bytes, which gives the
 *    higher figure if Cloudflare counts in 1,024s). Cloudflare averages the highest usage of
 *    each day over the month; the estimate uses the size now, or the size once every kind
 *    has reached its limit ("full schedule"). The free allowance is 10 GB for the whole
 *    Cloudflare account, so other buckets (Cloud Storage media) use it up as well.
 *  - Operations this app makes: per upload one write (or, for a big file, one to start, one
 *    per part and one to finish), one list to trim old copies, one size check; per weekly
 *    restore test one list and one download. Deleting is free. Reads of the R2 Backups page
 *    itself (one list per refresh) are left out: a few a day against 1,000,000 free a month.
 *  - Data going out to the internet is free on R2, so restoring costs nothing extra.
 *
 * What it cannot see: operations made by anything else on the same account, and files in the
 * bucket outside the backup folder.
 */
class R2CostEstimator
{
    private const BYTES_PER_GB = 1_000_000_000;

    private const DAYS_PER_MONTH = 30;

    private const DRILLS_PER_MONTH = 4.35;

    /** Kinds that are never planned for: made by a person or by a restore, not by the schedule. */
    private const UNSCHEDULED = [BackupRun::KIND_MANUAL, BackupRun::KIND_PRERESTORE];

    public function __construct(protected BackupSettingsService $settings) {}

    /** @return array{as_of: string, url: string, storage_per_gb_month: float, class_a_per_million: float, class_b_per_million: float, free_storage_gb: float, free_class_a: int, free_class_b: int, usd_bdt_rate: ?float} */
    public function pricing(): array
    {
        $pricing = (array) config('backup.r2.pricing', []);

        return [
            'as_of' => (string) ($pricing['as_of'] ?? ''),
            'url' => (string) ($pricing['url'] ?? 'https://developers.cloudflare.com/r2/pricing/'),
            'storage_per_gb_month' => (float) ($pricing['storage_per_gb_month'] ?? 0.015),
            'class_a_per_million' => (float) ($pricing['class_a_per_million'] ?? 4.50),
            'class_b_per_million' => (float) ($pricing['class_b_per_million'] ?? 0.36),
            'free_storage_gb' => (float) ($pricing['free_storage_gb'] ?? 10),
            'free_class_a' => (int) ($pricing['free_class_a'] ?? 1_000_000),
            'free_class_b' => (int) ($pricing['free_class_b'] ?? 10_000_000),
            'usd_bdt_rate' => $this->settings->r2UsdBdtRate(),
        ];
    }

    /**
     * The bill for a month with this much stored and this many operations, the free allowance taken off.
     *
     * @return array{storage_gb: float, storage_billable_gb: float, storage_usd: float, class_a: int, class_a_billable: int, class_a_usd: float, class_b: int, class_b_billable: int, class_b_usd: float, total_usd: float, total_bdt: ?float, free_storage_used_percent: float, within_free: bool}
     */
    public function bill(int $storageBytes, int $classA, int $classB): array
    {
        $price = $this->pricing();

        $storageGb = $storageBytes / self::BYTES_PER_GB;
        $storageBillable = max(0.0, $storageGb - $price['free_storage_gb']);
        $aBillable = max(0, $classA - $price['free_class_a']);
        $bBillable = max(0, $classB - $price['free_class_b']);

        $storageUsd = $storageBillable * $price['storage_per_gb_month'];
        $aUsd = $aBillable / 1_000_000 * $price['class_a_per_million'];
        $bUsd = $bBillable / 1_000_000 * $price['class_b_per_million'];
        $total = $storageUsd + $aUsd + $bUsd;

        return [
            'storage_gb' => $storageGb,
            'storage_billable_gb' => $storageBillable,
            'storage_usd' => $storageUsd,
            'class_a' => $classA,
            'class_a_billable' => $aBillable,
            'class_a_usd' => $aUsd,
            'class_b' => $classB,
            'class_b_billable' => $bBillable,
            'class_b_usd' => $bUsd,
            'total_usd' => $total,
            'total_bdt' => $price['usd_bdt_rate'] !== null ? $total * $price['usd_bdt_rate'] : null,
            'free_storage_used_percent' => $price['free_storage_gb'] > 0 ? min(999.0, $storageGb / $price['free_storage_gb'] * 100) : 0.0,
            'within_free' => $total <= 0.0,
        ];
    }

    /**
     * R2 operations one upload of a file this big makes: [class A, class B].
     *
     * @return array{0: int, 1: int}
     */
    public function operationsPerUpload(int $bytes): array
    {
        $partBytes = max(5 * 1024 * 1024, (int) config('backup.r2.part_bytes', 16 * 1024 * 1024));
        $parts = max(1, (int) ceil($bytes / $partBytes));

        // Write (start + one per part + finish for a big file) + one list to trim old copies; one size check.
        return [($parts > 1 ? $parts + 2 : 1) + 1, 1];
    }

    /**
     * Operations the app made this month, from the backup history: the uploads that reached R2
     * and the restore tests that read from it.
     *
     * @return array{uploads: int, drills: int, class_a: int, class_b: int}
     */
    public function monthToDate(): array
    {
        $none = ['uploads' => 0, 'drills' => 0, 'class_a' => 0, 'class_b' => 0];

        try {
            if (! Schema::hasTable('backup_runs') || ! Schema::hasColumn('backup_runs', 'offsite_to')) {
                return $none;
            }

            $start = now()->startOfMonth();
            $a = 0;
            $b = 0;
            $uploads = 0;

            BackupRun::query()
                ->where('status', BackupRun::STATUS_SUCCESS)
                ->where('finished_at', '>=', $start)
                ->where('offsite_to', 'like', '%'.R2BackupService::KEY.'%')
                ->get(['size_bytes'])
                ->each(function (BackupRun $run) use (&$a, &$b, &$uploads): void {
                    [$ra, $rb] = $this->operationsPerUpload((int) $run->size_bytes);
                    $a += $ra;
                    $b += $rb;
                    $uploads++;
                });

            $drills = BackupRun::query()
                ->where('scope', BackupRun::SCOPE_DRILL)
                ->where('kind', BackupRun::DRILL_R2)
                ->where('finished_at', '>=', $start)
                ->count();

            return ['uploads' => $uploads, 'drills' => $drills, 'class_a' => $a + $drills, 'class_b' => $b + $drills];
        } catch (Throwable) {
            return $none;
        }
    }

    /**
     * What the schedule puts in the bucket once every kind has reached its limit, and the
     * operations a month of it makes. The size of one copy is the average of the copies already
     * in R2 ($groups, from R2UsageReport) or else of the latest runs in the backup history.
     *
     * @param  array<int, array<string, mixed>>  $groups  R2UsageReport groups
     * @param  float  $scale  1.0 = backups as big as they are now; 2.0 = twice as big
     * @param  array<string, int>|null  $sizes  copy size by scope ("database", "app") to use instead of the measured one (the examples)
     * @return array{has_data: bool, rows: array<int, array{scope: string, kind: string, keep: int, avg_bytes: int, uploads_per_month: int, steady_bytes: int}>, steady_bytes: int, class_a: int, class_b: int}
     */
    public function forecast(array $groups = [], float $scale = 1.0, ?array $sizes = null): array
    {
        $rows = [];
        $a = 0;
        $b = 0;

        foreach ($this->plan() as $item) {
            $average = $sizes[$item['scope']] ?? $this->averageBytes($item['scope'], $item['kind'], $groups);

            if ($average === null) {
                continue;
            }

            $average = (int) round($average * $scale);
            [$ua, $ub] = $this->operationsPerUpload($average);

            $rows[] = [
                'scope' => $item['scope'],
                'kind' => $item['kind'],
                'keep' => $item['keep'],
                'avg_bytes' => $average,
                'uploads_per_month' => $item['uploads_per_month'],
                'steady_bytes' => $average * $item['keep'],
            ];

            $a += $ua * $item['uploads_per_month'];
            $b += $ub * $item['uploads_per_month'];
        }

        if (config('backup.drill.enabled') && $rows !== []) {
            $a += (int) ceil(self::DRILLS_PER_MONTH);
            $b += (int) ceil(self::DRILLS_PER_MONTH);
        }

        return [
            'has_data' => $rows !== [],
            'rows' => $rows,
            'steady_bytes' => (int) array_sum(array_column($rows, 'steady_bytes')),
            'class_a' => $a,
            'class_b' => $b,
        ];
    }

    /**
     * "If your backups were N times bigger": the bill and the size at full schedule.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<int, int|float>  $factors
     * @return array<int, array{factor: float, steady_bytes: int, bill: array<string, mixed>}>
     */
    public function scenarios(array $groups = [], array $factors = [1, 2, 5, 10]): array
    {
        return array_map(function (int|float $factor) use ($groups): array {
            $forecast = $this->forecast($groups, (float) $factor);

            return [
                'factor' => (float) $factor,
                'steady_bytes' => $forecast['steady_bytes'],
                'bill' => $this->bill($forecast['steady_bytes'], $forecast['class_a'], $forecast['class_b']),
            ];
        }, $factors);
    }

    /**
     * The bill for a schedule like this one if a database copy were $databaseBytes and a full app
     * archive $appBytes: for the page to show before there is any history to measure.
     *
     * @return array{database_bytes: int, app_bytes: int, steady_bytes: int, bill: array<string, mixed>}
     */
    public function example(int $databaseBytes, int $appBytes): array
    {
        $forecast = $this->forecast([], 1.0, ['database' => $databaseBytes, 'app' => $appBytes]);

        return [
            'database_bytes' => $databaseBytes,
            'app_bytes' => $appBytes,
            'steady_bytes' => $forecast['steady_bytes'],
            'bill' => $this->bill($forecast['steady_bytes'], $forecast['class_a'], $forecast['class_b']),
        ];
    }

    /**
     * The copies the schedule sends to R2 and how many are kept of each.
     *
     * @return array<int, array{scope: string, kind: string, keep: int, uploads_per_month: int}>
     */
    protected function plan(): array
    {
        $databaseKinds = (array) config('backup.offsite.database_kinds', []);
        $appKinds = (array) config('backup.offsite.app_kinds', []);
        $fullApp = (bool) config('backup.daily.full_app', true);
        $plan = [];

        $add = function (string $scope, string $kind, int $uploads) use (&$plan): void {
            if (! in_array($kind, self::UNSCHEDULED, true)) {
                $plan[] = [
                    'scope' => $scope,
                    'kind' => $kind,
                    'keep' => max(1, (int) config("backup.offsite.keep.{$scope}.{$kind}", 10)),
                    'uploads_per_month' => $uploads,
                ];
            }
        };

        if (config('backup.hourly.enabled', true) && in_array(BackupRun::KIND_HOURLY, $databaseKinds, true)) {
            $add(BackupRun::SCOPE_DATABASE, BackupRun::KIND_HOURLY, 24 * self::DAYS_PER_MONTH);
        }

        if (! $fullApp && in_array(BackupRun::KIND_DAILY, $databaseKinds, true)) {
            $add(BackupRun::SCOPE_DATABASE, BackupRun::KIND_DAILY, self::DAYS_PER_MONTH);
        }

        if ($fullApp && in_array(BackupRun::KIND_DAILY, $appKinds, true)) {
            $add(BackupRun::SCOPE_APP, BackupRun::KIND_DAILY, self::DAYS_PER_MONTH);
        }

        if (in_array(BackupRun::KIND_PREDEPLOY, $databaseKinds, true)) {
            $add(BackupRun::SCOPE_DATABASE, BackupRun::KIND_PREDEPLOY, $this->deploysLastMonth());
        }

        return $plan;
    }

    /** Pre-deploy backups made in the last 30 days: how often the app is updated. */
    protected function deploysLastMonth(): int
    {
        try {
            if (! Schema::hasTable('backup_runs')) {
                return 0;
            }

            return BackupRun::query()
                ->where('scope', BackupRun::SCOPE_DATABASE)
                ->where('kind', BackupRun::KIND_PREDEPLOY)
                ->where('status', BackupRun::STATUS_SUCCESS)
                ->where('finished_at', '>=', now()->subDays(self::DAYS_PER_MONTH))
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    protected function averageBytes(string $scope, string $kind, array $groups): ?int
    {
        foreach ($groups as $group) {
            if ($group['scope'] === $scope && $group['kind'] === $kind && (int) $group['avg_bytes'] > 0) {
                return (int) $group['avg_bytes'];
            }
        }

        try {
            if (! Schema::hasTable('backup_runs')) {
                return null;
            }

            $average = BackupRun::query()
                ->where('scope', $scope)
                ->where('kind', $kind)
                ->where('status', BackupRun::STATUS_SUCCESS)
                ->whereNotNull('size_bytes')
                ->latest('id')
                ->limit(5)
                ->pluck('size_bytes')
                ->avg();

            return $average ? (int) round($average) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
