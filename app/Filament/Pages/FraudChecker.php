<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Courier;
use App\Filament\Concerns\ChecksCourierFraud;
use App\Services\CompanyContext;
use BackedEnum;
use Filament\Pages\Page;
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
 *   scoring rules (see FraudCheckReport);
 * - this store's own orders for that number and any active blacklist hit.
 *
 * The reseller portal has its own copy of this page
 * (App\Filament\Reseller\Pages\FraudChecker) without the store data.
 */
class FraudChecker extends Page
{
    use ChecksCourierFraud;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $cluster = Courier::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = null;

    protected string $view = 'filament.fraud-check.page';

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

    public function showsStoreData(): bool
    {
        return true;
    }

    public function canRefresh(): bool
    {
        return true;
    }

    protected function fraudCheckCompanyId(): int
    {
        return (int) app(CompanyContext::class)->id();
    }
}
