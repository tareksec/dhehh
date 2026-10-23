<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What is in the R2 backup folder right now: how many copies, how big, which kinds, the newest
 * files. Read from R2 itself (one list request per 1,000 files, which R2 bills as a Class A
 * operation), so the answer is kept for a few minutes and the R2 Backups page has a Refresh
 * button; every upload and trim forgets it.
 */
class R2UsageReport
{
    public const CACHE_KEY = 'backup.r2.usage';

    /** Minutes a good answer is reused. */
    private const TTL_MINUTES = 10;

    /** Seconds a failed answer is reused, so a broken connection is not hammered by every page view. */
    private const ERROR_TTL_SECONDS = 30;

    private const LATEST_FILES = 15;

    public function __construct(
        protected R2BackupService $r2,
        protected OffsiteBackupService $offsite,
    ) {}

    /**
     * @return array{state: string, error: ?string, fetched_at: ?string, count: int, bytes: int, groups: array<int, array<string, mixed>>, files: array<int, array<string, mixed>>, other: array{count: int, bytes: int}}
     */
    public function snapshot(bool $refresh = false): array
    {
        if ($this->r2->missingSettings() !== []) {
            return $this->empty('not_set_up');
        }

        if ($refresh) {
            $this->forget();
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $report = $this->build();
            Cache::put(self::CACHE_KEY, $report, now()->addMinutes(self::TTL_MINUTES));
        } catch (Throwable $exception) {
            $report = [...$this->empty('error'), 'error' => $exception->getMessage(), 'fetched_at' => now()->toIso8601String()];
            Cache::put(self::CACHE_KEY, $report, now()->addSeconds(self::ERROR_TTL_SECONDS));
        }

        return $report;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    protected function build(): array
    {
        $ours = collect();
        $other = ['count' => 0, 'bytes' => 0];

        foreach ($this->r2->listBackupFiles() as $file) {
            $described = $this->offsite->describe($file);
            $size = (int) ($file['size'] ?? 0);

            if ($described === null) {
                $other['count']++;
                $other['bytes'] += $size;

                continue;
            }

            $ours->push([
                'name' => $described['name'],
                'scope' => $described['scope'],
                'kind' => $described['kind'],
                'size' => $size,
                'at' => $file['createdTime'] ?? null,
                'encrypted' => str_ends_with($described['name'], BackupEncryptor::EXTENSION),
            ]);
        }

        $scopeOrder = [BackupRun::SCOPE_DATABASE => 0, BackupRun::SCOPE_APP => 1];

        $groups = $ours
            ->groupBy(fn (array $file): string => $file['scope'].'|'.$file['kind'])
            ->map(function ($files): array {
                $sorted = $files->sortBy('at')->values();
                $first = $files->first();

                return [
                    'scope' => $first['scope'],
                    'kind' => $first['kind'],
                    'count' => $files->count(),
                    'bytes' => (int) $files->sum('size'),
                    'avg_bytes' => (int) round($files->avg('size')),
                    'oldest_at' => $sorted->first()['at'] ?? null,
                    'newest_at' => $sorted->last()['at'] ?? null,
                    'keep' => max(1, (int) config("backup.offsite.keep.{$first['scope']}.{$first['kind']}", 10)),
                ];
            })
            ->sortBy(fn (array $group): string => ($scopeOrder[$group['scope']] ?? 9).'-'.(array_search($group['kind'], BackupRun::KINDS, true) ?: 0))
            ->values()
            ->all();

        return [
            'state' => 'ready',
            'error' => null,
            'fetched_at' => now()->toIso8601String(),
            'count' => $ours->count() + $other['count'],
            'bytes' => (int) $ours->sum('size') + $other['bytes'],
            'groups' => $groups,
            'files' => $ours->sortByDesc(fn (array $file): int => $file['at'] ? Carbon::parse($file['at'])->getTimestamp() : 0)->take(self::LATEST_FILES)->values()->all(),
            'other' => $other,
        ];
    }

    /** @return array<string, mixed> */
    protected function empty(string $state): array
    {
        return ['state' => $state, 'error' => null, 'fetched_at' => null, 'count' => 0, 'bytes' => 0, 'groups' => [], 'files' => [], 'other' => ['count' => 0, 'bytes' => 0]];
    }
}
