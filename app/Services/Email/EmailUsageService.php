<?php

namespace App\Services\Email;

use App\Models\EmailLog;
use App\Models\StorefrontSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;

/**
 * What Settings → Email delivery shows about email use (owner request 2026-10-24, made provider-neutral
 * 2026-11-02): how many emails went out, how many reached the recipient, bounced, were marked as spam or failed,
 * how much of the monthly free allowance is used, and an estimate of the cost. Everything is counted from this
 * company's Email Log, only the emails that went through the site's own mail service (the Email Log "via", for
 * example `smtp:smtp.mx.cloudflare.net`, see EmailProvider). No service's API gives the free allowance or the
 * price, so the owner types those two numbers in (kept with the site's settings); nothing is assumed. The cost
 * is an estimate, not the provider's bill. Delivered, bounced and spam only exist for a service that reports
 * back (Amazon SES with tracking on); for the others every accepted email stays "sent".
 */
class EmailUsageService
{
    /** The service accepted these (sent, and later delivered, bounced or marked as spam). */
    public const ACCEPTED_STATUSES = [EmailLog::STATUS_SENT, EmailLog::STATUS_DELIVERED, EmailLog::STATUS_BOUNCED, EmailLog::STATUS_COMPLAINED];

    /** From this share of the free allowance the gauge turns amber. */
    public const NEAR_LIMIT_PERCENT = 80;

    /** Email Log kind => the group the owner sees. */
    public const KIND_GROUPS = [
        EmailLog::KIND_ORDER => 'order',
        EmailLog::KIND_TEMPLATE => 'template',
        EmailLog::KIND_LOGIN_CODE => 'codes',
        EmailLog::KIND_RESELLER_PASSWORD_RESET => 'codes',
        EmailLog::KIND_IMS_CODE => 'ims',
        EmailLog::KIND_IMS_INVITATION => 'ims',
        EmailLog::KIND_IMS_MESSAGE => 'ims',
        EmailLog::KIND_OWNER_DIGEST => 'digest',
        EmailLog::KIND_STAFF_REPLY => 'staff',
        EmailLog::KIND_TEST => 'test',
        EmailLog::KIND_OTHER => 'other',
    ];

    /** @return array<string, string> group key => its name */
    public static function groupLabels(): array
    {
        return [
            'order' => __('Order emails'),
            'template' => __('Emails from your own templates'),
            'codes' => __('Login and reset codes'),
            'ims' => __('IMS investor emails'),
            'digest' => __('Owner digest'),
            'staff' => __('Replies from the Email inbox'),
            'test' => __('Test emails'),
            'other' => __('Other'),
        ];
    }

    /**
     * The two numbers the owner types in: free emails each month and the price of 1,000 emails after that (US
     * dollars). Null while not filled in. The values typed on the Amazon SES page before the page became
     * provider-neutral (`ses_*`) are read when the new ones (`email_*`) are not there yet.
     *
     * @return array{free_limit: ?int, price: ?float}
     */
    public static function settingsFor(?StorefrontSetting $setting): array
    {
        $credentials = (array) $setting?->notification_credentials;
        $free = $credentials['email_free_limit'] ?? $credentials['ses_free_limit'] ?? null;
        $price = $credentials['email_price_per_1000'] ?? $credentials['ses_price_per_1000'] ?? null;

        return [
            'free_limit' => is_numeric($free) ? max(0, (int) $free) : null,
            'price' => is_numeric($price) ? max(0.0, (float) $price) : null,
        ];
    }

    /**
     * The usage of one calendar month (providers bill by calendar month) and the four months up to it, of the
     * emails that went through $via (EmailProvider::$via).
     *
     * @param  array{free_limit: ?int, price: ?float}  $settings
     * @return array<string, mixed>
     */
    public function report(int $companyId, string $via, int $monthsBack, array $settings, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $monthsBack = max(0, min(3, $monthsBack));
        $start = $now->startOfMonth()->subMonths($monthsBack);
        $end = $start->addMonth();
        $isCurrent = $monthsBack === 0;
        $daysInMonth = $start->daysInMonth;
        $elapsed = $isCurrent ? $now->day : $daysInMonth;

        $totals = $this->counts($companyId, $via, $start, $end);

        return [
            'month' => ['label' => $start->translatedFormat('F Y'), 'short' => $start->translatedFormat('M'), 'is_current' => $isCurrent, 'days' => $daysInMonth],
            'totals' => $totals,
            'last_24h' => $this->counts($companyId, $via, $now->subDay(), $now->addSecond())['sent'],
            'kinds' => $this->kinds($companyId, $via, $start, $end, $totals['sent']),
            'weeks' => $this->weeks($companyId, $via, $start, $elapsed),
            'months' => $this->months($companyId, $via, $now, $monthsBack, $settings),
            'free' => $this->free($totals['sent'], $settings['free_limit'], $now, $isCurrent, $elapsed, $daysInMonth),
            'cost' => $this->cost($totals['sent'], $settings),
        ];
    }

    /**
     * Estimated cost of $sent emails in a month: the ones past the free allowance, at the price of 1,000. Null while
     * the price is not filled in.
     *
     * @param  array{free_limit: ?int, price: ?float}  $settings
     * @return array{price: ?float, amount: ?float, without_free: ?float, billable: int}
     */
    public function cost(int $sent, array $settings): array
    {
        $billable = max(0, $sent - (int) ($settings['free_limit'] ?? 0));
        $price = $settings['price'];

        return [
            'price' => $price,
            'billable' => $billable,
            'amount' => $price === null ? null : $billable * $price / 1000,
            'without_free' => $price === null ? null : $sent * $price / 1000,
        ];
    }

    /** "$0.12"; a cost under one cent but not zero reads "less than $0.01" so it never looks free. */
    public static function money(?float $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return $amount > 0 && $amount < 0.005 ? __('less than $0.01') : '$'.number_format($amount, 2);
    }

    /**
     * How the free allowance stands: used, left, the share used, whether it is ok, near (80%) or over, when it
     * starts again, and (this month only) where the month will end at the current pace.
     *
     * @return array<string, mixed>
     */
    protected function free(int $sent, ?int $limit, CarbonImmutable $now, bool $isCurrent, int $elapsed, int $daysInMonth): array
    {
        $percent = $limit === null ? null : ($limit === 0 ? ($sent > 0 ? 100.0 : 0.0) : $sent / $limit * 100);
        $state = match (true) {
            $limit === null => 'unset',
            $percent >= 100 && $sent > $limit => 'over',
            $percent >= self::NEAR_LIMIT_PERCENT => 'near',
            default => 'ok',
        };
        $projected = $isCurrent && $sent > 0 ? (int) round($sent / max(1, $elapsed) * $daysInMonth) : null;
        $nextStart = $now->startOfMonth()->addMonth();

        return [
            'limit' => $limit,
            'used' => $sent,
            'left' => $limit === null ? null : max(0, $limit - $sent),
            'over_by' => $limit === null ? 0 : max(0, $sent - $limit),
            'percent' => $percent,
            'state' => $state,
            'resets_on' => $isCurrent ? $nextStart->translatedFormat('M j') : null,
            'resets_in_days' => $isCurrent ? (int) $now->startOfDay()->diffInDays($nextStart) : null,
            'projected' => $projected,
            'projected_over' => $limit !== null && $projected !== null && $projected > $limit,
        ];
    }

    /**
     * By kind of email, the biggest first; a kind with nothing in the period is left out.
     *
     * @return array<int, array{name: string, sent: int, delivered: int, waiting: int, problems: int, failed: int, share: float}>
     */
    protected function kinds(int $companyId, string $via, CarbonInterface $from, CarbonInterface $to, int $totalSent): array
    {
        $groups = [];

        foreach ($this->query($companyId, $via, $from, $to)->selectRaw('kind, '.$this->aggregate())->groupBy('kind')->get() as $row) {
            $key = self::KIND_GROUPS[$row->kind] ?? 'other';
            $counts = $this->normalise($row);
            $groups[$key] ??= $this->normalise(null);

            foreach (['sent', 'delivered', 'waiting', 'problems', 'failed'] as $field) {
                $groups[$key][$field] += $counts[$field];
            }
        }

        $labels = self::groupLabels();

        return collect($groups)
            ->filter(fn (array $counts): bool => $counts['sent'] + $counts['failed'] > 0)
            ->map(fn (array $counts, string $key): array => [
                'name' => $labels[$key],
                'sent' => $counts['sent'],
                'delivered' => $counts['delivered'],
                'waiting' => $counts['waiting'],
                'problems' => $counts['problems'],
                'failed' => $counts['failed'],
                'share' => $totalSent > 0 ? $counts['sent'] / $totalSent * 100 : 0.0,
            ])
            ->sortByDesc('sent')
            ->values()
            ->all();
    }

    /**
     * The month in four parts (days 1-7, 8-14, 15-21 and the rest) up to $elapsed days: sent and delivered of each,
     * with the top of the chart rounded to a tidy number.
     *
     * @return array{rows: array<int, array{range: string, sent: int, delivered: int}>, top: int}
     */
    protected function weeks(int $companyId, string $via, CarbonImmutable $start, int $elapsed): array
    {
        $month = $start->translatedFormat('M');
        $rows = [];

        foreach ([[1, 7], [8, 14], [15, 21], [22, $start->daysInMonth]] as [$first, $last]) {
            $last = min($last, $elapsed);

            if ($first > $last) {
                break;
            }

            $counts = $this->counts($companyId, $via, $start->addDays($first - 1), $start->addDays($last));
            $rows[] = [
                'range' => $first === $last ? $month.' '.$first : $month.' '.$first.'–'.$last,
                'sent' => $counts['sent'],
                'delivered' => $counts['delivered'],
            ];
        }

        // The top is the biggest week rounded up to its leading digit (650 -> 700), and even, because the middle
        // gridline sits at half of it.
        $max = max(1, ...array_column($rows, 'sent'));
        $unit = 10 ** (int) floor(log10($max));
        $top = (int) (ceil($max / $unit) * $unit);

        return ['rows' => $rows, 'top' => $top % 2 === 0 ? $top : $top + 1];
    }

    /**
     * The four months up to the one shown, newest first, each with its cost.
     *
     * @param  array{free_limit: ?int, price: ?float}  $settings
     * @return array<int, array{label: string, is_current: bool, selected: bool, sent: int, failed: int, percent: ?float, over_by: int, cost: ?float}>
     */
    protected function months(int $companyId, string $via, CarbonImmutable $now, int $monthsBack, array $settings): array
    {
        $rows = [];

        for ($i = 0; $i < 4; $i++) {
            $start = $now->startOfMonth()->subMonths($i);
            $counts = $this->counts($companyId, $via, $start, $start->addMonth());
            $limit = $settings['free_limit'];

            $rows[] = [
                'label' => $start->translatedFormat('M Y'),
                'is_current' => $i === 0,
                'selected' => $i === $monthsBack,
                'sent' => $counts['sent'],
                'failed' => $counts['failed'],
                'percent' => $limit === null ? null : ($limit === 0 ? ($counts['sent'] > 0 ? 100.0 : 0.0) : $counts['sent'] / $limit * 100),
                'over_by' => $limit === null ? 0 : max(0, $counts['sent'] - $limit),
                'cost' => $this->cost($counts['sent'], $settings)['amount'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{sent: int, delivered: int, waiting: int, bounced: int, complained: int, problems: int, failed: int, attempts: int}
     */
    protected function counts(int $companyId, string $via, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->normalise($this->query($companyId, $via, $from, $to)->selectRaw($this->aggregate())->first());
    }

    /** The emails of one company that went through $via, created from $from up to (not including) $to. */
    protected function query(int $companyId, string $via, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return EmailLog::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('via', $via)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->toBase();
    }

    /** The counts of every status, as one SELECT list (the values are the model's own constants, never input). */
    protected function aggregate(): string
    {
        $accepted = "'".implode("','", self::ACCEPTED_STATUSES)."'";
        $count = fn (string $condition): string => "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END)";

        return implode(', ', [
            $count("status IN ({$accepted})").' AS sent',
            $count("status = '".EmailLog::STATUS_DELIVERED."'").' AS delivered',
            $count("status = '".EmailLog::STATUS_SENT."'").' AS waiting',
            $count("status = '".EmailLog::STATUS_BOUNCED."'").' AS bounced',
            $count("status = '".EmailLog::STATUS_COMPLAINED."'").' AS complained',
            $count("status = '".EmailLog::STATUS_FAILED."'").' AS failed',
        ]);
    }

    /**
     * @return array{sent: int, delivered: int, waiting: int, bounced: int, complained: int, problems: int, failed: int, attempts: int}
     */
    protected function normalise(?object $row): array
    {
        $number = fn (string $field): int => (int) ($row->{$field} ?? 0);

        return [
            'sent' => $number('sent'),
            'delivered' => $number('delivered'),
            'waiting' => $number('waiting'),
            'bounced' => $number('bounced'),
            'complained' => $number('complained'),
            'problems' => $number('bounced') + $number('complained'),
            'failed' => $number('failed'),
            'attempts' => $number('sent') + $number('failed'),
        ];
    }
}
