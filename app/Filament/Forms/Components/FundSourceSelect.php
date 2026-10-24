<?php

namespace App\Filament\Forms\Components;

use App\Models\FundSource;
use Filament\Forms\Components\Select;

/**
 * A fund-source picker that lists the company's money accounts
 * (Finance > Accounts: cash, bank, mobile banking). The field's state
 * outside the form is still a `fund_sources.id`: a stored id is shown as its
 * account, and the picked account is saved as its fund source (made on first
 * use, see `FundSource::forAccount()`).
 */
class FundSourceSelect
{
    public static function make(string $field): Select
    {
        return Select::make($field)
            ->options(fn (): array => FundSource::moneyAccountOptions())
            ->formatStateUsing(fn (mixed $state): ?int => FundSource::accountIdFor($state))
            ->dehydrateStateUsing(fn (mixed $state): ?int => FundSource::forAccount($state)?->getKey())
            ->searchable();
    }
}
