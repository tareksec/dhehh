<?php

namespace App\Filament\Trade\Pages;

use App\Services\TradeCashBookService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The simple balance sheet (docs/tasneem-samples/PLAN.md §5): what the
 * business has today (cash, bank, stock at buying price, money buyers owe),
 * what it owes suppliers, and what is left. Needs "trade.cashbook".
 */
class BalanceSheet extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'balance-sheet';

    protected string $view = 'filament.trade.pages.balance-sheet';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('trade.cashbook') ?? false;
    }

    public function getTitle(): string
    {
        return __('Balance sheet');
    }

    public static function getNavigationLabel(): string
    {
        return __('Balance sheet');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['sheet' => app(TradeCashBookService::class)->balanceSheet()];
    }
}
