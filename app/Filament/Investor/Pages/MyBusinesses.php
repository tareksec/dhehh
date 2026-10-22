<?php

namespace App\Filament\Investor\Pages;

use App\Filament\Investor\Concerns\InvestorPortalPage;
use App\Models\InvestorPortalAccount;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Services\InvestorBusinessSwitchService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "My businesses" (17_IMS_IN_PORTAL_PLAN.md §5): one line per business where
 * the person's phone has an active portal account, with the money figures of
 * that business, and the totals. The figures are worked out business by
 * business, each under its own company context, so no query mixes
 * companies. Shown only to someone with more than one business.
 */
class MyBusinesses extends Page
{
    use InvestorPortalPage;

    protected static ?string $portalRole = 'holder';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $slug = 'my-businesses';

    protected string $view = 'filament.investor.pages.my-businesses';

    public static function canAccess(array $parameters = []): bool
    {
        $account = Filament::auth()->user();

        return $account instanceof InvestorPortalAccount
            && ($account->isInvestor() || $account->isChannelPartner())
            && app(InvestorBusinessSwitchService::class)->otherBusinesses($account)->isNotEmpty();
    }

    public static function getNavigationLabel(): string
    {
        return __('My businesses');
    }

    public function getTitle(): string
    {
        return __('My businesses');
    }

    /**
     * @return list<array{company_id: int, name: string, currency: string, current: bool, investor: bool, partner: bool, capital_at_work: float, total_invested: float, profit_received: float, profit_pending: float, commission_due: float}>
     */
    public function rows(): array
    {
        $context = app(CompanyContext::class);
        $home = $context->company();
        $settings = app(CompanySettingsService::class);
        $rows = [];

        try {
            foreach (app(InvestorBusinessSwitchService::class)->businesses($this->account()) as $entry) {
                $company = $entry['company'];
                $account = $entry['account'];
                $context->set($company);

                $investor = $account->investor_id !== null && $account->investor()->exists();
                $partner = $account->isChannelPartner();
                $summary = $investor ? $this->portal()->investorSummary($account) : null;

                $rows[] = [
                    'company_id' => (int) $company->getKey(),
                    'name' => $settings->name($company),
                    'currency' => $company->currency ?: 'BDT',
                    'current' => (int) $company->getKey() === (int) $home?->getKey(),
                    'investor' => $investor,
                    'partner' => $partner,
                    'capital_at_work' => (float) ($summary['capital_at_work'] ?? 0),
                    'total_invested' => (float) ($summary['total_invested'] ?? 0),
                    'profit_received' => (float) ($summary['profit_received'] ?? 0),
                    'profit_pending' => (float) ($summary['profit_pending'] ?? 0),
                    'commission_due' => $partner ? (float) $this->portal()->partnerSummary($account)['due'] : 0.0,
                ];
            }
        } finally {
            $home ? $context->set($home) : $context->none();
        }

        return $rows;
    }

    /**
     * The totals per currency (businesses in different currencies are never added together).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{capital_at_work: float, total_invested: float, profit_received: float, profit_pending: float, commission_due: float}>
     */
    public function totals(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $sum = $totals[$row['currency']] ?? ['capital_at_work' => 0.0, 'total_invested' => 0.0, 'profit_received' => 0.0, 'profit_pending' => 0.0, 'commission_due' => 0.0];

            foreach (array_keys($sum) as $key) {
                $sum[$key] = round($sum[$key] + $row[$key], 2);
            }

            $totals[$row['currency']] = $sum;
        }

        return $totals;
    }
}
