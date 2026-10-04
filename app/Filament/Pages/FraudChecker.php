<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Courier;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\CustomerBlacklist;
use App\Models\Order;
use App\Services\CompanyContext;
use App\Services\CustomerRiskService;
use App\Services\CustomerRiskSettingsService;
use App\Services\ExternalCourierFraudService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Standalone "type a phone number, see its courier history" screen, laid
 * out like the public BD fraud-checker sites (risk banner, summary tiles,
 * one card per courier) so staff can vet a buyer before even opening an
 * order. It only presents data the app already has:
 *
 * - courier history from ExternalCourierFraudService (Pathao, Steadfast,
 *   RedX — whichever the company has credentials for), same 24h cache and
 *   audit log as the order form's "Courier Fraud Check" button;
 * - the Safe/Risky verdict from the existing
 *   `external_fraud_low_ratio_threshold` Customer Risk setting — no new
 *   scoring rules;
 * - this store's own orders for that number and any active blacklist hit.
 */
class FraudChecker extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $cluster = Courier::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = null;

    protected string $view = 'filament.pages.fraud-checker';

    public ?string $phone = null;

    /** Normalized (01XXXXXXXXX) number the result below belongs to. */
    public ?string $checkedPhone = null;

    /** @var array<string, mixed>|null ExternalCourierFraudService::checkByPhone() output */
    public ?array $result = null;

    public ?string $checkedAt = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('sales.view') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Fraud Checker');
    }

    public function getTitle(): string
    {
        return __('Fraud Checker');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('phone')
                    ->hiddenLabel()
                    ->tel()
                    ->prefix('+88')
                    ->placeholder('01XXXXXXXXX')
                    ->autofocus()
                    ->required()
                    ->regex('/^(?:\+?88)?0?1[3-9]\d{8}$/')
                    ->validationMessages([
                        'regex' => __('Enter a valid Bangladeshi mobile number, e.g. 01712345678.'),
                    ]),
            ]);
    }

    /**
     * $refresh skips the 24h cache, same as the order form's manual button,
     * so newly added courier credentials take effect immediately.
     */
    public function check(bool $refresh = false): void
    {
        $this->form->validate();

        $service = app(ExternalCourierFraudService::class);
        $phone = $service->normalizePhone((string) $this->phone);

        $this->result = $service->checkByPhone(
            $phone,
            $this->companyId(),
            $this->matchingCustomer($phone)?->getKey(),
            bypassCache: $refresh,
        );
        $this->checkedPhone = $phone;
        $this->checkedAt = now()->toDateTimeString();
    }

    public function refreshCheck(): void
    {
        if ($this->checkedPhone === null) {
            return;
        }

        $this->phone = $this->checkedPhone;
        $this->check(refresh: true);
    }

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
     * Safe / Risky / no history, using the owner's existing Customer Risk
     * threshold — the same split the order form's fraud check shows.
     *
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
     * it answered — so a missing card always says why.
     *
     * @return array<string, array{label: string, configured: bool, stats: array<string, int>|null, ratio: float|null}>
     */
    public function courierCards(): array
    {
        $results = $this->courierResults();

        return collect(app(ExternalCourierFraudService::class)->configuredDrivers($this->companyId()))
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
     * This store's own orders for the number (every matching customer
     * record), or null when it isn't a customer yet.
     *
     * @return array{customer: Customer, total: int, completed: int, cancelled: int, returned: int}|null
     */
    public function storeHistory(): ?array
    {
        if ($this->checkedPhone === null) {
            return null;
        }

        $customerIds = $this->matchingCustomersQuery($this->checkedPhone)->pluck('id');

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
     * filter is explicit here.
     */
    public function blacklistEntry(): ?CustomerBlacklist
    {
        if ($this->checkedPhone === null) {
            return null;
        }

        $companyId = $this->companyId();

        return CustomerBlacklist::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
            ->where('phone', app(CustomerRiskService::class)->normalizePhone($this->checkedPhone))
            ->first();
    }

    /** @return array<string, array<string, int>> */
    protected function courierResults(): array
    {
        return collect($this->result ?? [])
            ->except('overall_success_ratio')
            ->filter(fn ($stats): bool => is_array($stats))
            ->all();
    }

    protected function matchingCustomer(string $phone): ?Customer
    {
        return $this->matchingCustomersQuery($phone)->first();
    }

    /**
     * Customer phones are stored as typed, so match the local, 880 and
     * +880 spellings of the same number.
     */
    protected function matchingCustomersQuery(string $phone)
    {
        $local = ltrim($phone, '0');

        return Customer::query()->whereIn('phone', ['0'.$local, '880'.$local, '+880'.$local]);
    }

    protected function companyId(): int
    {
        return (int) app(CompanyContext::class)->id();
    }
}
