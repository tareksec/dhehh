<?php

namespace App\Reports;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The filters every report understands. Dates are parsed defensively: a bad
 * or reversed date range never throws, it falls back to today / is swapped.
 */
final class ReportFilters
{
    public function __construct(
        public readonly CarbonInterface $from,
        public readonly CarbonInterface $to,
        public readonly ?int $supplierId = null,
        public readonly ?int $productId = null,
    ) {}

    public static function make(mixed $from = null, mixed $to = null, ?int $supplierId = null, ?int $productId = null): self
    {
        $start = self::parse($from);
        $end = self::parse($to);

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return new self($start->startOfDay(), $end->endOfDay(), $supplierId ?: null, $productId ?: null);
    }

    /** Filters for one report: an "as of" report has a single date, so the start follows it. */
    public static function forReport(ReportDefinition $report, mixed $from = null, mixed $to = null, ?int $supplierId = null, ?int $productId = null): self
    {
        return self::make($report->usesAsOfDate() ? $to : $from, $to, $supplierId, $productId);
    }

    public function key(): string
    {
        return implode('|', [
            $this->from->toDateString(),
            $this->to->toDateString(),
            $this->supplierId,
            $this->productId,
        ]);
    }

    private static function parse(mixed $value): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return now();
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return now();
        }
    }
}
