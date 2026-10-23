<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Resources\SmsLogs\SmsLogResource;
use App\Models\StorefrontSetting;
use App\Services\CompanyContext;
use App\Services\SmsBalanceService;
use App\Services\SmsTemplateService;
use App\Services\SmsUsageService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * How many SMS the system sent, how many the gateway refused, what that cost,
 * and what is left on the BulkSMSBD account (owner request 2026-10-23), so the
 * owner does not have to log in to BulkSMSBD to see them. Counts and cost come
 * from the SMS log; the balance comes from BulkSMSBD's balance API. The gateway
 * has no API to recharge or to report deliveries, so a recharge is a link to
 * its site and "sent" means the gateway accepted the message.
 */
class SmsDashboard extends Page
{
    public const PERIODS = ['today', '7d', '30d', 'month'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $cluster = Settings::class;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.sms-dashboard';

    #[Url]
    public string $period = 'month';

    /** The last balance reading (see SmsBalanceService::balance()); null until the page asks the gateway. */
    public ?array $balance = null;

    public static function getNavigationLabel(): string
    {
        return __('SMS Dashboard');
    }

    public function getTitle(): string
    {
        return __('SMS Dashboard');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canManageSettings() ?? false;
    }

    /** @return array<string, string> */
    public static function periodLabels(): array
    {
        return [
            'today' => __('Today'),
            '7d' => __('Last 7 days'),
            '30d' => __('Last 30 days'),
            'month' => __('This month'),
        ];
    }

    /** Asks the gateway for the balance (the page does it after it has drawn, so a slow gateway never blocks it). */
    public function loadBalance(bool $fresh = false): void
    {
        $setting = $this->gateway();

        $this->balance = $setting === null ? null : app(SmsBalanceService::class)->balance($setting, $fresh);
    }

    public function refreshBalance(): void
    {
        $this->loadBalance(fresh: true);
    }

    /** The gateway SMS goes out through for the company being viewed; null when none is saved or all companies are shown. */
    public function gateway(): ?StorefrontSetting
    {
        $context = app(CompanyContext::class);

        if ($context->isAllCompanies() || $context->id() === null) {
            return null;
        }

        return app(SmsTemplateService::class)->gatewayFor((int) $context->id());
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $period = in_array($this->period, self::PERIODS, true) ? $this->period : 'month';
        $now = CarbonImmutable::now();
        $from = match ($period) {
            'today' => $now->startOfDay(),
            '7d' => $now->subDays(6)->startOfDay(),
            '30d' => $now->subDays(29)->startOfDay(),
            default => $now->startOfMonth(),
        };

        $setting = $this->gateway();
        $price = $this->number(data_get($setting?->notification_credentials, 'sms_price_per_sms'));
        $lowBalance = $this->number(data_get($setting?->notification_credentials, 'sms_low_balance_alert'));
        $balances = app(SmsBalanceService::class);

        return [
            'period' => $period,
            'periodLabels' => self::periodLabels(),
            'summary' => app(SmsUsageService::class)->summary($from, $now, $price),
            'days' => collect(range(0, $from->diffInDays($now->startOfDay())))
                ->map(fn (int $offset): string => $from->addDays($offset)->toDateString())
                ->all(),
            'kindLabels' => SmsLogResource::kindLabels(),
            'setting' => $setting,
            'allCompanies' => app(CompanyContext::class)->isAllCompanies(),
            'price' => $price,
            'lowBalance' => $lowBalance,
            'balanceSupported' => $setting !== null && $balances->supported($setting),
            'rechargeUrl' => $setting !== null ? $balances->rechargeUrl($setting) : null,
            'notice' => SmsLogResource::logNotice(),
            'currency' => app(CompanyContext::class)->company()?->currency ?: 'BDT',
            'integrationsUrl' => Integrations::getUrl(),
            'logsUrl' => Logs::canAccess() ? Logs::getUrl(['tab' => 'sms']) : null,
        ];
    }

    protected function number(mixed $value): ?float
    {
        $value = str_replace(',', '', trim((string) $value));

        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }
}
