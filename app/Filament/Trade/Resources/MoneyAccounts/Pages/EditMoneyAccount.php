<?php

namespace App\Filament\Trade\Resources\MoneyAccounts\Pages;

use App\Filament\Trade\Resources\MoneyAccounts\MoneyAccountResource;
use Filament\Resources\Pages\EditRecord;

class EditMoneyAccount extends EditRecord
{
    protected static string $resource = MoneyAccountResource::class;

    protected function getRedirectUrl(): string
    {
        return MoneyAccountResource::getUrl('index');
    }
}
