<?php

namespace App\Support;

use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\CustomerBlacklist;
use App\Models\Order;
use App\Services\CustomerRiskService;
use App\Services\CustomerRiskSettingsService;
use App\Services\ExternalCourierFraudService;

/**
 * Turns one ExternalCourierFraudService::checkByPhone() result into what the
 * fraud-check screens show (summary tiles, Safe/Risky verdict, one card per
 * courier). Shared by the admin and reseller Fraud Checker pages and the
 * fraud-check card on both create-order forms, so they always agree.
 *
 * The verdict uses the owner's existing `external_fraud_low_ratio_threshold`
 * Customer Risk setting — no scoring rules of its own.
 */
class FraudCheckReport
{
    /**
     * @param  array<string, mixed>  $result  checkByPhone() output
     * @param  string  $phone  normalized 01XXXXXXXXX number the result belongs to
     */
    public function __construct(
        public readonly array $result,
        public readonly string $phone,
        public readonly int $companyId,
    ) {}

    /**
     * @return array{total: int, success: int, cancel: int, ratio: float|null, reports: int}
     */
    public function summary(): array
    {
        $couriers = collect($this->courierResults());

        return [
            'total' => (int) $couriers->sum('total'),
            'success' => (int) $couriers->sum('success'),
            'cancel' => (int) $couriers->sum('cancel'),
            'ratio' => $this->result['overall_success_ratio'] ?? null,
            'reports' => (int) $couriers->sum(fn (array $stats): int => (int) ($stats['reports'] ?? 0)),
        ];
    }

    /**
     * @return array{key: string, label: string, description: string}
     */
    public function verdict(): array
    {
        $ratio = $this->summary()['ratio'];
        $threshold = $this->threshold();

        return match (true) {
            $ratio === null => [
                'key' => 'none',
                'label' => __('No courier history'),
                'description' => __('None of the connected couriers has a delivery record for this number yet.'),
            ],
            $ratio < $threshold => [
                'key' => 'risky',
                'label' => __('Risky'),
                'description' => __('Success rate is below your :threshold% review threshold.', ['threshold' => $threshold]),
            ],
            default => [
                'key' => 'safe',
                'label' => __('Safe'),
                'description' => __('Success rate meets your :threshold% review threshold.', ['threshold' => $threshold]),
            ],
        };
    }

    public function threshold(): int
    {
        return app(CustomerRiskSettingsService::class)->int('external_fraud_low_ratio_threshold');
    }

    /**
     * One row per fraud-checkable courier, in a stable order, whether or not
     * it answered — so a missing card always says why. $onlyConfigured drops
     * the couriers the company never set up (the reseller portal has no use
     * for "set this up" cards).
     *
     * @return array<string, array{label: string, configured: bool, stats: array<string, int>|null, ratio: float|null}>
     */
    public function courierCards(bool $onlyConfigured = false): array
    {
        $results = $this->courierResults();

        return collect(app(ExternalCourierFraudService::class)->configuredDrivers($this->companyId))
            ->when($onlyConfigured, fn ($drivers) => $drivers->filter())
            ->map(function (bool $configured, string $driver) use ($results): array {
                $stats = $results[$driver] ?? null;
                $total = (int) ($stats['total'] ?? 0);

                return [
                    'label' => CourierProvider::DRIVERS[$driver] ?? ucfirst($driver),
                    'configured' => $configured,
                    'stats' => $stats,
                    'ratio' => $total > 0 ? round(((int) $stats['success'] / $total) * 100, 1) : null,
                ];
            })
            // Configured couriers first, so the cards with data lead.
            ->sortByDesc('configured')
            ->all();
    }

    /**
     * Couriers that answered, for one-line summaries.
     *
     * @return array<string, array<string, int>>
     */
    public function courierResults(): array
    {
        return collect($this->result)
            ->except('overall_success_ratio')
            ->filter(fn ($stats): bool => is_array($stats))
            ->all();
    }

    /**
     * This store's own orders for the number (every matching customer
     * record), or null when it isn't a customer yet. Admin-only: it names
     * the store's customer.
     *
     * @return array{customer: Customer, total: int, completed: int, cancelled: int, returned: int}|null
     */
    public function storeHistory(): ?array
    {
        $customerIds = self::matchingCustomersQuery($this->phone)->pluck('id');

        if ($customerIds->isEmpty()) {
            return null;
        }

        $counts = Order::query()
            ->whereIn('customer_id', $customerIds)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'customer' => Customer::query()->find($customerIds->first()),
            'total' => (int) $counts->sum(),
            'completed' => (int) ($counts[Order::STATUS_COMPLETED] ?? 0),
            'cancelled' => (int) ($counts[Order::STATUS_CANCELLED] ?? 0),
            'returned' => (int) ($counts[Order::STATUS_RETURNED] ?? 0),
        ];
    }

    /**
     * Active blacklist entry for the number — global or this company's.
     * CustomerBlacklist has no CompanyScope on purpose, so the company
     * filter is explicit here. Admin-only: the reason is internal.
     */
    public function blacklistEntry(): ?CustomerBlacklist
    {
        return CustomerBlacklist::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $this->companyId))
            ->where('phone', app(CustomerRiskService::class)->normalizePhone($this->phone))
            ->first();
    }

    /**
     * Customer phones are stored as typed, so match the local, 880 and
     * +880 spellings of the same number.
     */
    public static function matchingCustomersQuery(string $phone)
    {
        $local = ltrim($phone, '0');

        return Customer::query()->whereIn('phone', ['0'.$local, '880'.$local, '+880'.$local]);
    }
}
