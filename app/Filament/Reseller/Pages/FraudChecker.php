<?php

namespace App\Filament\Reseller\Pages;

use App\Filament\Concerns\ChecksCourierFraud;
use App\Filament\Reseller\Concerns\ForApprovedResellers;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Reseller portal: the same courier fraud check as the admin Fraud Checker,
 * run on the store's courier accounts. Resellers see only the courier
 * history and verdict — never the store's own customer records, order
 * history or blacklist reasons — and get no cache-skipping refresh, so a
 * reseller cannot drive repeated logins on the store's courier panels.
 */
class FraudChecker extends Page
{
    use ChecksCourierFraud;
    use ForApprovedResellers;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.fraud-check.page';

    public static function getNavigationLabel(): string
    {
        return __('Fraud Checker');
    }

    public function getTitle(): string
    {
        return __('Fraud Checker');
    }

    public function showsStoreData(): bool
    {
        return false;
    }

    public function canRefresh(): bool
    {
        return false;
    }

    protected function fraudCheckCompanyId(): int
    {
        return (int) $this->reseller()->company_id;
    }
}
