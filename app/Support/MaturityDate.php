<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The maturity date of an investment or group deposit with a tenor
 * (IMS-V2/PRD.md FR-23): the date the money came in plus the tenor in
 * calendar months; null without a tenor.
 */
final class MaturityDate
{
    public static function from(DateTimeInterface|string|null $investedAt, ?int $tenorMonths): ?string
    {
        if ($investedAt === null || $investedAt === '' || $tenorMonths === null || $tenorMonths <= 0) {
            return null;
        }

        return CarbonImmutable::parse($investedAt)->addMonthsNoOverflow($tenorMonths)->toDateString();
    }
}
