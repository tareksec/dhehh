<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ResellerProduct;
use Illuminate\Contracts\Session\Session;

/**
 * Request-scoped "which reseller is this storefront visitor shopping
 * through" context, and the reseller prices that follow from it.
 *
 * A visitor who lands on /store/{resellerSlug} is remembered for the rest
 * of their session on that company's storefront (the storefront's own
 * links point at the main-store routes, so the URL alone can't carry the
 * reseller). While active, a product in that reseller's catalog sells at
 * the reseller's price everywhere on the storefront — Product's
 * selling_price accessor asks this class — and its wholesale tiers are
 * hidden, since the lowest tier is the reseller's own buying rate.
 *
 * Pricing rules (owner, 2026-09-28):
 *  - base price  = the product's lowest wholesale tier price + the
 *                  company's one reseller cost per unit;
 *  - suggested   = the product's normal selling price;
 *  - the reseller picks any price at or above the base price.
 * Products without wholesale tiers, and variant lines, have no base price
 * and are sold at the normal price with no commission.
 */
class ResellerPricing
{
    protected ?Customer $reseller = null;

    protected ?int $companyId = null;

    protected float $costPerUnit = 0.0;

    /** @var array<int, float|null>|null product_id => reseller price (null = suggested) */
    protected ?array $prices = null;

    public static function sessionKey(Company $company): string
    {
        return 'storefront_reseller.'.$company->getKey();
    }

    /** Remember $reseller for this visitor's session and activate them now. */
    public function remember(Session $session, Company $company, Customer $reseller): void
    {
        $session->put(self::sessionKey($company), $reseller->getKey());
        $this->activate($company, $reseller);
    }

    /** Re-activate the reseller remembered in this visitor's session, if still valid. */
    public function restoreFromSession(Session $session, Company $company): void
    {
        $resellerId = (int) $session->get(self::sessionKey($company), 0);

        if ($resellerId < 1) {
            return;
        }

        $reseller = $company->reseller_module_enabled
            ? Customer::withoutGlobalScopes()
                ->where('company_id', $company->getKey())
                ->where('reseller_status', 'approved')
                ->find($resellerId)
            : null;

        if (! $reseller) {
            $session->forget(self::sessionKey($company));

            return;
        }

        $this->activate($company, $reseller);
    }

    public function activate(Company $company, Customer $reseller): void
    {
        $this->reseller = $reseller;
        $this->companyId = (int) $company->getKey();
        $this->costPerUnit = (float) ($company->storefrontSetting?->reseller_cost_per_unit ?? 0);
        $this->prices = null;
    }

    public function clear(): void
    {
        $this->reseller = null;
        $this->companyId = null;
        $this->prices = null;
    }

    public function reseller(): ?Customer
    {
        return $this->reseller;
    }

    /** Reseller price the storefront should charge for $product, or null when none applies. */
    public function priceFor(Product $product): ?float
    {
        if (! $this->reseller || ! $product->getKey() || (int) $product->company_id !== $this->companyId) {
            return null;
        }

        $prices = $this->catalogPrices();

        if (! array_key_exists((int) $product->getKey(), $prices)) {
            return null;
        }

        $base = self::basePrice($product, $this->costPerUnit);

        if ($base === null) {
            return null;
        }

        // Never below the base price, even if the tier rate or cost was
        // raised after the reseller set their price: the company's rate is
        // always covered; the reseller's margin just shrinks to zero.
        return max($prices[(int) $product->getKey()] ?? $product->baseSellingPrice(), $base);
    }

    /** Per-unit base price for the active reseller, or null when not reseller-priced. */
    public function basePriceFor(Product $product): ?float
    {
        return $this->priceFor($product) === null ? null : self::basePrice($product, $this->costPerUnit);
    }

    /** Lowest wholesale tier price + reseller cost per unit; null when the product has no tiers or has variants. */
    public static function basePrice(Product $product, float $costPerUnit): ?float
    {
        $rate = $product->resellerWholesaleRate();

        return $rate === null ? null : round($rate + max(0, $costPerUnit), 2);
    }

    /** @return array<int, float|null> */
    protected function catalogPrices(): array
    {
        return $this->prices ??= ResellerProduct::query()
            ->where('customer_id', $this->reseller->getKey())
            ->where('is_active', true)
            ->get(['product_id', 'price'])
            ->mapWithKeys(fn (ResellerProduct $row): array => [
                (int) $row->product_id => $row->price === null ? null : (float) $row->price,
            ])
            ->all();
    }
}
