<?php

namespace App\Services;

use App\Models\SmsLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the SMS dashboard (owner request 2026-10-23), read from the
 * SMS log of the company being viewed (the company scope applies, so "all
 * companies" adds them up). "Sent" means the gateway accepted the message:
 * BulkSMSBD sends no delivery reports, so a handset receiving it is not known.
 */
class SmsUsageService
{
    /**
     * @return array{
     *     counts: array<string, int>,
     *     parts: int,
     *     cost: ?float,
     *     by_kind: array<string, array<string, int>>,
     *     by_day: array<string, array{sent: int, failed: int}>
     * }
     */
    public function summary(CarbonInterface $from, CarbonInterface $to, ?float $pricePerSms = null): array
    {
        $counts = [SmsLog::STATUS_SENT => 0, SmsLog::STATUS_FAILED => 0, SmsLog::STATUS_SKIPPED => 0, SmsLog::STATUS_QUEUED => 0];
        $byKind = [];
        $parts = 0;

        // A sent row with no stored part count (none are left after the migration, but be safe) counts as one part.
        $rows = SmsLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("kind, status, COUNT(*) as total, SUM(CASE WHEN status = 'sent' THEN COALESCE(segments, 1) ELSE 0 END) as parts")
            ->groupBy('kind', 'status')
            ->get();

        foreach ($rows as $row) {
            $counts[$row->status] = ($counts[$row->status] ?? 0) + (int) $row->total;
            $byKind[$row->kind] ??= ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'queued' => 0, 'parts' => 0];
            $byKind[$row->kind][$row->status] = (int) $row->total;
            $byKind[$row->kind]['parts'] += (int) $row->parts;
            $parts += (int) $row->parts;
        }

        $byDay = [];

        SmsLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', [SmsLog::STATUS_SENT, SmsLog::STATUS_FAILED])
            ->select(DB::raw('DATE(created_at) as day'), 'status', DB::raw('COUNT(*) as total'))
            ->groupBy('day', 'status')
            ->orderBy('day')
            ->get()
            ->each(function ($row) use (&$byDay): void {
                $byDay[$row->day] ??= ['sent' => 0, 'failed' => 0];
                $byDay[$row->day][$row->status] = (int) $row->total;
            });

        return [
            'counts' => $counts,
            'parts' => $parts,
            'cost' => $pricePerSms !== null ? round($parts * $pricePerSms, 2) : null,
            'by_kind' => $byKind,
            'by_day' => $byDay,
        ];
    }
}
