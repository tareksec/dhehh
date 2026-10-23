<?php

namespace App\Filament\Trade\Resources\MoneyAccounts\Pages;

use App\Filament\Trade\Resources\MoneyAccounts\MoneyAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMoneyAccount extends CreateRecord
{
    protected static string $resource = MoneyAccountResource::class;

    protected static bool $canCreateAnother = false;

    /** Only the manual kinds; the balance starts at the opening money (Account::syncCurrentBalance()). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = array_key_exists($data['type'] ?? '', MoneyAccountResource::typeOptions()) ? $data['type'] : 'cash';
        $data['system_key'] = null;
        $data['current_balance'] = $data['opening_balance'] ?? 0;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return MoneyAccountResource::getUrl('index');
    }
}
