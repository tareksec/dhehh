<?php

namespace App\Filament\Reseller\Concerns;

use App\Models\Company;
use App\Models\Customer;
use App\Services\CompanyContext;
use Filament\Facades\Filament;

/**
 * Shared by every portal page that only an approved reseller may open
 * (everything except the Dashboard, which also shows the pending/rejected
 * status page). canAccess() is re-checked by Filament on every request, so
 * a reseller who is rejected or disabled loses these pages immediately.
 */
trait ForApprovedResellers
{
    public static function canAccess(array $parameters = []): bool
    {
        $reseller = Filament::auth()->user();

        return $reseller instanceof Customer && $reseller->isApprovedReseller();
    }

    public function reseller(): Customer
    {
        /** @var Customer $customer */
        $customer = Filament::auth()->user();

        return $customer;
    }

    protected function company(): ?Company
    {
        return app(CompanyContext::class)->company();
    }

    protected function currency(): string
    {
        return $this->company()?->currency ?: 'BDT';
    }
}
