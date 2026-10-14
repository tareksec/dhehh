<?php

namespace App\Services;

use App\Jobs\CheckExternalCourierFraudJob;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
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
     * store that are on sale. A product with variations is ordered one
     * variation at a time (owner decision 2026-10-12), so it needs at least
     * one active variation; its variations come loaded as `activeVariants`.
     *
     * @return Collection<int, Product>
     */
    public function catalog(Customer $reseller): Collection
    {
        return $reseller->resellerCatalog()
            ->wherePivot('is_active', true)
            ->where('products.is_active', true)
            ->where('products.status', Product::STATUS_AVAILABLE)
            ->where(fn ($query) => $query
                ->where('products.has_variants', false)
                ->orWhereHas('activeVariants'))
            ->with('activeVariants')
            ->orderBy('products.name')
            ->get();
    }

    /**
     * Lowest price the reseller may charge for $product (or one of its
     * variations), or null when it sells at the normal price. A variation's
     * is what the store charges the reseller for it: its own (wholesale)
     * price plus the reseller cost per unit.
     */
    public function basePrice(Customer $reseller, Product $product, ?ProductVariant $variant = null): ?float
    {
        if ($product->has_variants) {
            return $variant?->resellerCost($this->costPerUnit($reseller));
        }

        return ResellerPricing::basePrice($product, $this->costPerUnit($reseller));
    }

    /**
     * The price suggested for $product (its normal selling price), or for its
     * $variant (the admin's suggested selling price; null when the admin set
     * none, so the reseller has to set one).
     */
    public function suggestedPrice(Product $product, ?ProductVariant $variant = null): ?float
    {
        return $variant ? $variant->resellerSuggestedPrice() : round($product->baseSellingPrice(), 2);
    }

    /**
     * The price pre-filled for $product (or its $variant): the reseller's
     * saved price, else the suggested one -- never below the base. Null for a
     * variation with neither: the reseller has to enter a price.
     */
    public function defaultPrice(Customer $reseller, Product $product, ?ProductVariant $variant = null): ?float
    {
        $base = $this->basePrice($reseller, $product, $variant);

        if ($base === null) {
            return $this->suggestedPrice($product, $variant);
        }

        $pick = ResellerProduct::query()
            ->where('customer_id', $reseller->getKey())
            ->where('product_id', $product->getKey())
            ->first(['price', 'variant_prices']);
        $saved = $variant ? ($pick?->variant_prices[$variant->getKey()] ?? null) : $pick?->price;
        $price = $saved !== null ? (float) $saved : $this->suggestedPrice($product, $variant);

        return $price === null ? null : round(max($price, $base), 2);
    }

    /**
     * The variation a line names: required (and must be one of the product's
     * active variations) for a product with variations, ignored otherwise.
     * Null means "not a variation line"; false means the line is wrong.
     */
    protected function lineVariant(Product $product, mixed $variantId): ProductVariant|false|null
    {
        if (! $product->has_variants) {
            return null;
        }

        return $product->activeVariants->firstWhere('id', (int) $variantId) ?? false;
    }

    /** Units in stock for a line: the variation's own stock, else the product's. */
    protected function lineStock(Product $product, ?ProductVariant $variant): int
    {
        return (int) ($variant ? $variant->stock : $product->stock);
    }

    /**
     * The order's money without creating anything -- what the form shows live.
     *
     * With $ownCourier the reseller's own courier delivers: every line is
     * charged at the base price (the normal price when there is no wholesale
     * rate), there is no company delivery charge and no commission, and the
     * store's packaging charge per piece is added (owner rule) -- the
     * reseller pays this total in advance and keeps whatever their customer
     * pays them.
     *
     * @param  array<int, array{product_id?: mixed, quantity?: mixed, price?: mixed}>  $lines
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, shipping_fee: float, packaging_fee: float, vat: float, total: float, commission: float}
     */
    public function quote(Customer $reseller, array $lines, string $address, bool $ownCourier = false): array
    {
        $items = $this->resolveLines($reseller, $lines, $ownCourier);
        $setting = $reseller->company->storefrontSetting;
        $subtotal = round($items->sum('subtotal'), 2);
        $area = $ownCourier ? null : $this->deliveryAreas->resolve($address, $setting);
        $fee = $ownCourier ? 0.0 : (float) $this->delivery->quote($items, $area, $setting)['fee'];
        $vat = $setting->vatAmountForItems($items);
        $packaging = $ownCourier
            ? round((int) $items->sum('quantity') * (float) ($setting->reseller_packaging_cost_per_unit ?? 0), 2)
            : 0.0;
        $total = round($subtotal + $fee + $packaging + $vat, 2);
        // Own courier: the reseller's deposit pays first, they send the rest.
        $depositUsed = $ownCourier ? min(app(ResellerOwnCourierService::class)->spendableDeposit($reseller), $total) : 0.0;

        return [
            'items' => $items->all(),
            'delivery_area' => $area,
            'subtotal' => $subtotal,
            'shipping_fee' => $fee,
            'packaging_fee' => $packaging,
            'vat' => $vat,
            'total' => $total,
            'deposit_used' => $depositUsed,
            'to_send' => round($total - $depositUsed, 2),
            // What the reseller's customer is charged on an own-courier order (their retail prices).
            'customer_total' => round($items->sum(fn (array $item): float => ($item['retail_price'] ?? $item['unit_price']) * $item['quantity']), 2),
            'commission' => round($items->sum(fn (array $item): float => $item['reseller_base_price'] === null
                ? 0.0
                : ($item['unit_price'] - $item['reseller_base_price']) * $item['quantity']), 2),
        ];
    }

    /**
     * $ownCourier (courier_name, parcel_id, payment_method_id, sender,
     * transaction_id) sends the order with the reseller's own courier and
     * records their advance payment -- see ResellerOwnCourierService.
     *
     * @param  array{name: string, phone: string, address: string, note?: ?string}  $buyer
     * @param  array<int, array{product_id?: mixed, quantity?: mixed, price?: mixed}>  $lines
     * @param  array{courier_name?: ?string, parcel_id: string, payment_method_id: mixed, sender: string, transaction_id: string}|null  $ownCourier
     */
    public function place(Customer $reseller, array $buyer, array $lines, ?array $ownCourier = null): Order
    {
        abort_unless($reseller->isApprovedReseller(), 403);

        $quote = $this->quote($reseller, $lines, $buyer['address'], $ownCourier !== null);

        if ($ownCourier !== null) {
            $this->validateOwnCourier($reseller, $ownCourier, $quote['to_send'] > 0);
        }

        return DB::transaction(function () use ($reseller, $buyer, $quote, $ownCourier): Order {
            $customer = $this->buyer($buyer);
            $payment = $ownCourier === null
                ? 'Payment method: Cash on delivery'
                : 'Payment method: Full advance by the reseller (reseller\'s own courier)';

            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'reseller_customer_id' => $reseller->getKey(),
                'reseller_own_courier' => $ownCourier !== null,
                'reseller_courier_name' => filled($ownCourier['courier_name'] ?? null) ? trim($ownCourier['courier_name']) : null,
                'reseller_parcel_id' => $ownCourier !== null ? trim($ownCourier['parcel_id']) : null,
                'customer_name' => $customer->name,
                'order_date' => now()->toDateString(),
                'discount' => 0,
                'vat' => $quote['vat'],
                // A non-null zone stops Order::creating() from adding the
                // company's delivery charge to an own-courier order.
                'shipping_zone' => $ownCourier !== null ? '' : $quote['delivery_area'],
                'shipping_fee' => $quote['shipping_fee'],
                'packaging_fee' => $quote['packaging_fee'],
                'paid_amount' => 0,
                'status' => 'draft',
                'source' => Order::SOURCE_RESELLER_PORTAL,
                'note' => trim("Reseller portal order\nDelivery address: {$buyer['address']}\n{$payment}"
                    .($ownCourier !== null ? "\nReseller's courier: ".(trim((string) ($ownCourier['courier_name'] ?? '')) ?: '-').', parcel ID: '.trim($ownCourier['parcel_id']) : '')
                    .(filled($buyer['note'] ?? null) ? "\nCustomer note: {$buyer['note']}" : '')),
            ]);

            foreach ($quote['items'] as $item) {
                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    'product_id' => $item['product']->getKey(),
                    'product_variant_id' => $item['variant']?->getKey(),
                    'variant_label' => $item['variant']?->label(),
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => ($item['variant']?->cost_price ?? $item['product']->cost_price) ?? 0,
                    'reseller_base_price' => $item['reseller_base_price'],
                    'reseller_retail_price' => $item['retail_price'] ?? null,
                ]);
            }

            $order->refresh();

            if ($ownCourier !== null) {
                // Paid in advance and delivered by the reseller's courier, so
                // there is no cash-on-delivery risk to check. Their deposit
                // pays first; they send the rest.
                $courier = app(ResellerOwnCourierService::class);
                $courier->applyDeposit($order);

                if ($courier->outstandingAmount($order) > 0) {
                    $courier->submitPayment($order, $ownCourier);
                }

                return $order->refresh();
            }

            CheckExternalCourierFraudJob::dispatch($order->getKey())->afterCommit();

            return $order;
        });
    }

    /**
     * @param  array{courier_name?: ?string, parcel_id?: mixed, payment_method_id?: mixed, sender?: mixed, transaction_id?: mixed}  $ownCourier
     */
    protected function validateOwnCourier(Customer $reseller, array $ownCourier, bool $needsPayment = true): void
    {
        $errors = [
            ...$this->parcelErrors($reseller, $ownCourier),
            ...($needsPayment ? $this->paymentErrors($reseller, $ownCourier) : []),
        ];

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array{parcel_id?: mixed}  $ownCourier
     * @return array<string, string>
     */
    protected function parcelErrors(Customer $reseller, array $ownCourier, ?int $exceptOrderId = null): array
    {
        $parcelId = trim((string) ($ownCourier['parcel_id'] ?? ''));

        if ($parcelId === '') {
            return ['data.parcel_id' => __('Enter the parcel ID from your courier.')];
        }

        if (app(ResellerOwnCourierService::class)->parcelIdIsTaken((int) $reseller->getKey(), $parcelId, $exceptOrderId)) {
            return ['data.parcel_id' => __('This parcel ID is already on another of your orders.')];
        }

        return [];
    }

    /**
     * @param  array{payment_method_id?: mixed, sender?: mixed, transaction_id?: mixed}  $ownCourier
     * @return array<string, string>
     */
    protected function paymentErrors(Customer $reseller, array $ownCourier): array
    {
        $service = app(ResellerOwnCourierService::class);
        $errors = [];

        // Paying online needs no number or transaction ID: the gateway confirms it.
        if (($ownCourier['payment_method_id'] ?? null) === ResellerOwnCourierService::ONLINE) {
            return $service->onlineGateway($reseller->company) ? [] : ['data.payment_method_id' => __('Online payment is not available. Choose a send-money number.')];
        }

        $methodId = (int) ($ownCourier['payment_method_id'] ?? 0);

        if (! $service->paymentMethods($reseller->company)->contains(fn ($method): bool => (int) $method->getKey() === $methodId)) {
            $errors['data.payment_method_id'] = __('Choose how you paid.');
        }

        if (trim((string) ($ownCourier['sender'] ?? '')) === '') {
            $errors['data.sender'] = __('Enter the number or account you paid from.');
        }

        if (trim((string) ($ownCourier['transaction_id'] ?? '')) === '') {
            $errors['data.transaction_id'] = __('Enter the transaction ID.');
        }

        return $errors;
    }

    /**
     * What an own-courier order would cost after the reseller's edit, without
     * changing anything -- what the edit form shows live. `extra` is what the
     * reseller still has to send (only the part above what they already
     * sent, owner rule 2026-10-06).
     *
     * @param  array<int, array{item_id?: mixed, product_id?: mixed, quantity?: mixed, price?: mixed}>  $lines
     *                                                                                                          `deposit_used` is the part of the extra the reseller's deposit pays,
     *                                                                                                          `to_send` what is left for them to send.
     * @return array{subtotal: float, packaging_fee: float, vat: float, total: float, customer_total: float, extra: float, deposit_used: float, to_send: float}
     */
    public function quoteEdit(Customer $reseller, Order $order, array $lines): array
    {
        return array_diff_key($this->planEdit($reseller, $order, $lines), ['kept' => 0, 'new' => 0]);
    }

    /**
     * The reseller changes their own-courier order from the portal (owner
     * rules, 2026-10-06): products can be added, quantities raised and
     * their customer's prices changed, but nothing on the order can be
     * removed or lowered; the courier, parcel ID and their customer's
     * details can be corrected. Only until the store verifies any of the
     * advance. When the total goes up, $payment (payment_method_id, sender,
     * transaction_id) is required and records a payment for the extra only.
     *
     * @param  array{name: string, phone: string, address: string}  $buyer
     * @param  array<int, array{item_id?: mixed, product_id?: mixed, quantity?: mixed, price?: mixed}>  $lines
     * @param  array{courier_name?: ?string, parcel_id: string}  $courier
     * @param  array{payment_method_id?: mixed, sender?: mixed, transaction_id?: mixed}  $payment
     */
    public function updateOwnCourierOrder(Customer $reseller, Order $order, array $buyer, array $lines, array $courier, array $payment = []): Order
    {
        abort_unless($reseller->isApprovedReseller() && (int) $order->reseller_customer_id === (int) $reseller->getKey(), 403);

        $courierService = app(ResellerOwnCourierService::class);

        return DB::transaction(function () use ($reseller, $order, $buyer, $lines, $courier, $payment, $courierService): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if (! $courierService->canEdit($order)) {
                throw ValidationException::withMessages(['data.items' => __('This order can no longer be changed: the store has verified your payment or packed the parcel.')]);
            }

            $plan = $this->planEdit($reseller, $order, $lines);
            $errors = [
                ...$this->parcelErrors($reseller, $courier, (int) $order->getKey()),
                ...($plan['to_send'] > 0 ? $this->paymentErrors($reseller, $payment) : []),
            ];

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $customer = $this->buyer($buyer);
            $address = trim($buyer['address']);
            $order->forceFill([
                'customer_id' => $customer->getKey(),
                'customer_name' => $customer->name,
                'reseller_courier_name' => filled($courier['courier_name'] ?? null) ? trim($courier['courier_name']) : null,
                'reseller_parcel_id' => trim($courier['parcel_id']),
                'packaging_fee' => $plan['packaging_fee'],
                'vat' => $plan['vat'],
                'note' => preg_replace('/^Delivery address: .*$/m', 'Delivery address: '.str_replace(["\r", "\n"], ' ', $address), (string) $order->note, 1),
            ])->save();

            foreach ($plan['kept'] as $line) {
                $line['item']->forceFill([
                    'quantity' => $line['quantity'],
                    'reseller_retail_price' => $line['retail_price'],
                ])->save();
            }

            foreach ($plan['new'] as $line) {
                OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    'product_id' => $line['product']->getKey(),
                    'product_variant_id' => $line['variant']?->getKey(),
                    'variant_label' => $line['variant']?->label(),
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'unit_cost' => ($line['variant']?->cost_price ?? $line['product']->cost_price) ?? 0,
                    'reseller_base_price' => null,
                    'reseller_retail_price' => $line['retail_price'],
                ]);
            }

            $order->refresh();
            app(OrderWorkflowService::class)->sync($order);
            $order->refresh();

            $courierService->applyDeposit($order);

            if ($courierService->outstandingAmount($order) > 0) {
                $courierService->submitPayment($order, [
                    'payment_method_id' => $payment['payment_method_id'] ?? null,
                    'sender' => (string) ($payment['sender'] ?? ''),
                    'transaction_id' => (string) ($payment['transaction_id'] ?? ''),
                    'amount' => $payment['amount'] ?? null,
                ]);
            }

            return $order->refresh();
        });
    }

    /**
     * Checks and prices an edit. Lines with an item_id are already on the
     * order: their product and store price stay, the quantity can only go
     * up and their customer's price never below the store price. Lines
     * without one are new products from the reseller's store at today's
     * store price. Errors are keyed to the repeater line.
     *
     * @param  array<int, array{item_id?: mixed, product_id?: mixed, quantity?: mixed, price?: mixed}>  $lines
     * @return array{kept: list<array{item: OrderItem, quantity: int, retail_price: float}>, new: list<array<string, mixed>>, subtotal: float, packaging_fee: float, vat: float, total: float, customer_total: float, extra: float, deposit_used: float, to_send: float}
     */
    protected function planEdit(Customer $reseller, Order $order, array $lines): array
    {
        $existing = $order->items()->with('product')->get()->keyBy('id');
        $catalog = $this->catalog($reseller)->keyBy('id');
        $seen = $existing->mapWithKeys(fn (OrderItem $item): array => [$item->product_id.':'.(int) $item->product_variant_id => true])->all();
        $errors = [];
        $kept = [];
        $new = [];
        $stockRows = [];

        foreach (array_values($lines) as $index => $line) {
            $key = "data.items.{$index}";
            $quantity = (int) ($line['quantity'] ?? 0);
            $item = filled($line['item_id'] ?? null) ? $existing->get((int) $line['item_id']) : null;

            if (filled($line['item_id'] ?? null) && ! $item) {
                $errors["{$key}.product_id"] = __('Choose a product from your store.');

                continue;
            }

            if ($item) {
                $retail = filled($line['price'] ?? null) ? round((float) $line['price'], 2) : $item->buyerUnitPrice();

                if ($quantity < (int) $item->quantity) {
                    $errors["{$key}.quantity"] = __('You cannot lower the quantity below :quantity.', ['quantity' => (int) $item->quantity]);
                } elseif ($retail < (float) $item->unit_price) {
                    $errors["{$key}.price"] = __('Your price must be at least :amount.', ['amount' => MoneyFormatter::number((float) $item->unit_price)]);
                } else {
                    $kept[(int) $item->getKey()] = ['item' => $item, 'quantity' => $quantity, 'retail_price' => $retail, 'index' => $index];
                }

                continue;
            }

            $productId = (int) ($line['product_id'] ?? 0);
            $product = $catalog->get($productId);

            if (! $product) {
                $errors["{$key}.product_id"] = __('Choose a product from your store.');

                continue;
            }

            $variant = $this->lineVariant($product, $line['variant_id'] ?? null);

            if ($variant === false) {
                $errors["{$key}.variant_id"] = __('Choose an option.');

                continue;
            }

            $seenKey = $productId.':'.($variant?->getKey() ?? 0);

            if (isset($seen[$seenKey])) {
                $errors["{$key}.product_id"] = __('This product is already on the order.');

                continue;
            }
            $seen[$seenKey] = true;

            if ($quantity < 1) {
                $errors["{$key}.quantity"] = __('Enter a quantity of at least 1.');

                continue;
            }

            if ($variant && $this->basePrice($reseller, $product, $variant) === null) {
                $errors["{$key}.variant_id"] = __('This variation has no price yet, so it cannot be sold.');

                continue;
            }

            $storePrice = round($this->basePrice($reseller, $product, $variant) ?? $this->suggestedPrice($product, $variant) ?? 0.0, 2);
            $retail = filled($line['price'] ?? null) ? round((float) $line['price'], 2) : $this->defaultPrice($reseller, $product, $variant);

            if ($retail === null) {
                $errors["{$key}.price"] = __('Enter your price.');

                continue;
            }

            if ($retail < $storePrice) {
                $errors["{$key}.price"] = __('Your price must be at least :amount.', ['amount' => MoneyFormatter::number($storePrice)]);

                continue;
            }

            $new[] = ['product' => $product, 'variant' => $variant, 'quantity' => $quantity, 'unit_price' => $storePrice, 'retail_price' => $retail, 'index' => $index];
        }

        $mentioned = collect($lines)->pluck('item_id')->filter()->map(fn ($id): int => (int) $id);

        if ($existing->keys()->diff($mentioned)->isNotEmpty()) {
            $errors['data.items'] = __('Products already on the order cannot be removed.');
        }

        // Pre-order products are not stock-checked, as at checkout.
        foreach ([...array_values($kept), ...$new] as $line) {
            $product = isset($line['item']) ? $line['item']->product : $line['product'];

            if ($product && ! $product->is_preorder) {
                $stockRows[$line['index']] = [
                    'product_id' => $product->getKey(),
                    'product_variant_id' => isset($line['item']) ? $line['item']->product_variant_id : $line['variant']?->getKey(),
                    'quantity' => $line['quantity'],
                ];
            }
        }

        foreach (app(OrderStockCheckService::class)->shortages($stockRows, $order) as $shortage) {
            $index = collect($stockRows)->search(fn (array $row): bool => (int) $row['product_id'] === $shortage['product_id']
                && (int) ($row['product_variant_id'] ?? 0) === (int) ($shortage['product_variant_id'] ?? 0));
            $errors["data.items.{$index}.quantity"] = __(':product does not have enough stock.', ['product' => $shortage['name']]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $setting = $reseller->company->storefrontSetting;
        $priced = collect([...array_values($kept), ...$new])->map(fn (array $line): array => [
            'product' => $line['item']->product ?? $line['product'] ?? null,
            'quantity' => $line['quantity'],
            'subtotal' => round((isset($line['item']) ? (float) $line['item']->unit_price : $line['unit_price']) * $line['quantity'], 2),
            'customer_subtotal' => round($line['retail_price'] * $line['quantity'], 2),
        ]);
        $addedPieces = collect($kept)->sum(fn (array $line): int => $line['quantity'] - (int) $line['item']->quantity)
            + collect($new)->sum('quantity');
        $subtotal = round($priced->sum('subtotal'), 2);
        $packaging = round((float) $order->packaging_fee + $addedPieces * (float) ($setting->reseller_packaging_cost_per_unit ?? 0), 2);
        $vat = $setting->vatAmountForItems($priced);
        $total = round(max($subtotal - (float) $order->discount + $vat + (float) $order->shipping_fee + $packaging, 0), 2);
        $courier = app(ResellerOwnCourierService::class);
        $extra = max(0.0, round($total - $courier->sentAmount($order), 2));
        $depositUsed = min($courier->spendableDeposit($reseller), $extra);

        return [
            'kept' => array_values($kept),
            'new' => $new,
            'subtotal' => $subtotal,
            'packaging_fee' => $packaging,
            'vat' => $vat,
            'total' => $total,
            'customer_total' => round($priced->sum('customer_subtotal'), 2),
            'extra' => $extra,
            'deposit_used' => $depositUsed,
            'to_send' => round($extra - $depositUsed, 2),
        ];
    }

    /**
     * Validates every line and prices it. Errors are keyed to the repeater so
     * the form shows them next to the right line.
     *
     * @param  array<int, array{product_id?: mixed, quantity?: mixed, price?: mixed}>  $lines
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function resolveLines(Customer $reseller, array $lines, bool $ownCourier = false): \Illuminate\Support\Collection
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

            $variant = $this->lineVariant($product, $line['variant_id'] ?? null);

            if ($variant === false) {
                $errors["{$key}.variant_id"] = __('Choose an option.');

                continue;
            }

            $seenKey = $productId.':'.($variant?->getKey() ?? 0);

            if (isset($seen[$seenKey])) {
                $errors["{$key}.product_id"] = __('This product is already on the order.');

                continue;
            }
            $seen[$seenKey] = true;

            if ($quantity < 1) {
                $errors["{$key}.quantity"] = __('Enter a quantity of at least 1.');

                continue;
            }

            if (! $product->is_preorder && $quantity > $this->lineStock($product, $variant)) {
                $errors["{$key}.quantity"] = __(':product does not have enough stock.', ['product' => $variant ? $product->name.' ('.$variant->label().')' : $product->name]);

                continue;
            }

            $base = $this->basePrice($reseller, $product, $variant);
            $suggested = $this->suggestedPrice($product, $variant);

            if ($variant && $base === null) {
                $errors["{$key}.variant_id"] = __('This variation has no price yet, so it cannot be sold.');

                continue;
            }

            if ($ownCourier) {
                // The reseller pays the store's price (no commission line);
                // their own retail price is kept for their customer, who
                // must never see the store's price (owner rule).
                $storePrice = round($base ?? $suggested ?? 0.0, 2);
                $retail = filled($line['price'] ?? null)
                    ? round((float) $line['price'], 2)
                    : $this->defaultPrice($reseller, $product, $variant);

                if ($retail === null) {
                    $errors["{$key}.price"] = __('Enter your price.');

                    continue;
                }

                if ($retail < $storePrice) {
                    $errors["{$key}.price"] = __('Your price must be at least :amount.', ['amount' => MoneyFormatter::number($storePrice)]);

                    continue;
                }

                $items->push([
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => $storePrice,
                    'reseller_base_price' => null,
                    'retail_price' => $retail,
                    'subtotal' => round($storePrice * $quantity, 2),
                ]);

                continue;
            }

            $price = $this->defaultPrice($reseller, $product, $variant);

            if ($base !== null && filled($line['price'] ?? null)) {
                $price = round((float) $line['price'], 2);

                if ($price < $base) {
                    $errors["{$key}.price"] = __('Your price must be at least :amount.', ['amount' => MoneyFormatter::number($base)]);

                    continue;
                }
            }

            if ($price === null) {
                $errors["{$key}.price"] = __('Enter your price.');

                continue;
            }

            $items->push([
                'product' => $product,
                'variant' => $variant,
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
