<?php

namespace App\Filament\Trade\Resources\Dues\Pages;

use App\Filament\Trade\Resources\Dues\DueResource;
use App\Models\Customer;
use App\Support\ImsNumbers;
use Filament\Resources\Pages\ListRecords;

class ListDues extends ListRecords
{
    protected static string $resource = DueResource::class;

    public function getSubheading(): ?string
    {
        $total = (float) Customer::query()->where('current_balance', '>', 0)->sum('current_balance');

        return __('Total to collect: :total', ['total' => ImsNumbers::money($total)]);
    }
}
