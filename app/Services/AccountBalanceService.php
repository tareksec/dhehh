<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

class AccountBalanceService
{
    public function for(Account $account): float
    {
        $companyId = app(CompanyContext::class)->isAllCompanies()
            ? null
            : (int) $account->company_id;

        return match ($account->system_key) {
            'inventory_value' => $this->inventoryValue($companyId),
            'customer_due' => $this->customerDue($companyId),
            'new_shipment' => $this->newShipmentValue($companyId),
            default => (float) $account->current_balance,
        };
    }

    public function inventoryValue(?int $companyId): float
    {
        $baseProducts = (float) DB::table('products')
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->where('has_variants', false)
            ->whereNull('deleted_at') // a raw query does not see the Trash filter
            ->sum(DB::raw('stock * cost_price'));

        $variantProducts = (float) DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->when($companyId !== null, fn ($query) => $query->where('product_variants.company_id', $companyId))
            ->where('products.has_variants', true)
            ->whereNull('products.deleted_at')
            ->where('product_variants.is_active', true)
            ->sum(DB::raw('product_variants.stock * COALESCE(product_variants.cost_price, products.cost_price)'));

        return $baseProducts + $variantProducts;
    }

    public function customerDue(?int $companyId): float
    {
        // A raw table query does not see the Trash filter: customers in Trash are left out, like in every other dues figure.
        return (float) DB::table('customers')
            ->whereNull('deleted_at')
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->where('current_balance', '>', 0)
            ->sum('current_balance');
    }

    public function newShipmentValue(?int $companyId): float
    {
        $activeStatuses = array_diff(array_keys(Shipment::STATUSES), ['received', 'cancelled']);

        return (float) DB::table('purchases')
            ->whereNull('deleted_at')
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->whereIn('id', DB::table('shipments')
                ->select('purchase_id')
                ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
                ->whereNotNull('purchase_id')
                ->whereIn('status', $activeStatuses))
            ->sum('total_amount');
    }
}
