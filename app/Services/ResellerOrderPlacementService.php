<?php

namespace App\Services;

use App\Jobs\CheckExternalCourierFraudJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An approved reseller placing an order for their own customer from the
 * portal. It follows the storefront checkout's rules instead of inventing new
 * ones: only products in the reseller's own store, their own selling price
 * (never below the base price; products without a wholesale rate sell at the
 * normal price), cash on delivery, the store's delivery charge and VAT, a
 * stock check, and the order arrives as a draft for the company to confirm
 * and deliver. The commission is created by the existing order observers.
 */
class ResellerOrderPlacementService
{
    public function __construct(
        protected StorefrontCheckoutPolicyService $checkoutPolicies,
        protected StorefrontDeliveryService $delivery,
        protected StorefrontDeliveryAreaResolver $deliveryAreas,
    ) {}

    /**
     * Products the reseller may put on an order: the active picks in their
     * store that are on sale. Variant products are left out (their lines have
     * no reseller price).
     *
     * @return Collection<int, Product>
     */
    public function catalog(Customer $reseller): Collection
    {
        return $reseller->resellerCatalog()
            ->wherePivot('is_active', true)
            ->where('products.is_active', true)
            ->where('products.status', Product::STATUS_AVAILABLE)
            ->where('products.has_variants', false)
            ->orderBy('products.name')
            ->get();
    }

    /** Lowest price the reseller may charge for $product, or null when it sells at the normal price. */
    public function basePrice(Customer $reseller, Product $product): ?float
    {
        return ResellerPricing::basePrice($product, $this->costPerUnit($reseller));
    }

    /** The price pre-filled for $product: the reseller's saved price, else the suggested one -- never below the base. */
    public function defaultPrice(Customer $reseller, Product $product): float
    {
        $base = $this->basePrice($reseller, $product);

        if ($base === null) {
            return round($product->baseSellingPrice(), 2);
        }

        $saved = ResellerProduct::query()
            ->where('customer_id', $reseller->getKey())
            ->where('product_id', $product->getKey())
            ->value('price');

        return round(max($saved !== null ? (float) $saved : $product->baseSellingPrice(), $base), 2);
    }

    /**
     * The order's money without creating anything -- what the form shows live.
     *
     * @param  array<int, array{product_id?: mixed, quantity?: mixed, price?: mixed}>  $lines
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, shipping_fee: float, vat: float, total: float, commission: float}
     */
    public function quote(Customer $reseller, array $lines, string $address): array
    {
        $items = $this->resolveLines($reseller, $lines);
        $setting = $reseller->company->storefrontSetting;
        $subtotal = round($items->sum('subtotal'), 2);
        $area = $this->deliveryAreas->resolve($address, $setting);
        $fee = (float) $this->delivery->quote($items, $area, $setting)['fee'];
        $vat = $setting->vatAmountForItems($items);

        return [
            'items' => $items->all(),
            'delivery_area' => $area,
            'subtotal' => $subtotal,
            'shipping_fee' => $fee,
            'vat' => $vat,
            'total' => round($subtotal + $fee + $vat, 2),
            'commission' => round($items->sum(fn (array $item): float => $item['reseller_base_price'] === null
                ? 0.0
                : ($item['unit_price'] - $item['reseller_base_price']) * $item['quantity']), 2),
        ];
    }

    /**
     * @param  array{name: string, phone: string, address: string, note?: ?string}  $buyer
     * @param  array<int, array{product_id?: mixed, quantity?: mixed, price?: mixed}>  $lines
     */
    public function place(Customer $reseller, array $buyer, array $lines): Order
    {
        abort_unless($reseller->isApprovedReseller(), 403);

        $quote = $this->quote($reseller, $lines, $buyer['address']);

        return DB::transaction(function () use ($reseller, $buyer, $quote): Order {
            $customer = $this->buyer($buyer);

            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'reseller_customer_id' => $reseller->getKey(),
                'customer_name' => $customer->name,
                'order_date' => now()->toDateString(),
                'discount' => 0,
                'vat' => $quote['vat'],
                'shipping_zone' => $quote['delivery_area'],
                'shipping_fee' => $quote['shipping_fee'],
                'paid_amount' => 0,
                'status' => 'draft',
                'source' => Order::SOURCE_RESELLER_PORTAL,
                'note' => trim("Reseller portal order\nDelivery address: {$buyer['address']}\nPayment method: Cash on delivery"
                    .(filled($buyer['note'] ?? null) ? "\nCustomer note: {$buyer['note']}" : '')),
            ]);

            foreach ($quote['items'] as $item) {
                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    'product_id' => $item['product']->getKey(),
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $item['product']->cost_price ?? 0,
                    'reseller_base_price' => $item['reseller_base_price'],
                ]);
            }

            CheckExternalCourierFraudJob::dispatch($order->getKey())->afterCommit();

            return $order->refresh();
        });
    }

    /**
     * Validates every line and prices it. Errors are keyed to the repeater so
     * the form shows them next to the right line.
     *
     * @param  array<int, array{product_id?: mixed, quantity?: mixed, price?: mixed}>  $lines
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function resolveLines(Customer $reseller, array $lines): \Illuminate\Support\Collection
    {
        $catalog = $this->catalog($reseller)->keyBy('id');
        $errors = [];
        $items = collect();
        $seen = [];

        foreach (array_values($lines) as $index => $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            $product = $catalog->get($productId);
            $key = "data.items.{$index}";

            if (! $product) {
                $errors["{$key}.product_id"] = __('Choose a product from your store.');

                continue;
            }

            if (isset($seen[$productId])) {
                $errors["{$key}.product_id"] = __('This product is already on the order.');

                continue;
            }
            $seen[$productId] = true;

            if ($quantity < 1) {
                $errors["{$key}.quantity"] = __('Enter a quantity of at least 1.');

                continue;
            }

            if (! $product->is_preorder && $quantity > (int) $product->stock) {
                $errors["{$key}.quantity"] = __(':product does not have enough stock.', ['product' => $product->name]);

                continue;
            }

            $base = $this->basePrice($reseller, $product);
            $price = $this->defaultPrice($reseller, $product);

            if ($base !== null && filled($line['price'] ?? null)) {
                $price = round((float) $line['price'], 2);

                if ($price < $base) {
                    $errors["{$key}.price"] = __('Your price must be at least :amount.', ['amount' => MoneyFormatter::number($base)]);

                    continue;
                }
            }

            $items->push([
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => $price,
                'reseller_base_price' => $base,
                'subtotal' => round($price * $quantity, 2),
            ]);
        }

        if ($items->isEmpty() && $errors === []) {
            $errors['data.items'] = __('Add at least one product.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $items;
    }

    protected function costPerUnit(Customer $reseller): float
    {
        return (float) ($reseller->company?->storefrontSetting?->reseller_cost_per_unit ?? 0);
    }

    /**
     * The buyer's customer record, found by phone like the storefront
     * checkout does. A phone that already belongs to a reseller account only
     * receives the order -- the reseller's own name and address are left alone.
     *
     * @param  array{name: string, phone: string, address: string}  $buyer
     */
    protected function buyer(array $buyer): Customer
    {
        $phone = trim($buyer['phone']);
        $phone = $this->checkoutPolicies->normalizeLocalPhone($phone) ?: $phone;

        $customer = Customer::query()
            ->whereIn('phone', $this->checkoutPolicies->phoneVariants($phone))
            ->first() ?? new Customer(['phone' => $phone]);

        if ($customer->exists && filled($customer->reseller_status)) {
            return $customer;
        }

        $customer->fill([
            'name' => $buyer['name'],
            'address' => $buyer['address'],
            'customer_type' => $customer->customer_type ?: 'regular',
            'customer_source' => $customer->customer_source ?: 'other',
            'opening_balance' => $customer->opening_balance ?? 0,
            'is_active' => true,
        ]);
        $customer->save();

        return $customer;
    }
}
