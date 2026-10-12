<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StorefrontCartRecord;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StorefrontCart
{
    /**
     * Effective "unlimited" stock ceiling for pre-order lines. Pre-order
     * products may be ordered beyond current stock (the checkout collects an
     * online advance), so their available quantity is capped at this large
     * value rather than the real stock count.
     */
    public const PREORDER_STOCK_CEILING = 100000;

    public function __construct(protected Session $session) {}

    public function add(Company $company, Product $product, int $quantity = 1, ?ProductVariant $variant = null, ?Customer $reseller = null): void
    {
        $quantity = max(1, $quantity);
        $items = $this->raw($company);
        $key = $this->lineKey($product, $variant);
        $maxStock = $this->availableStock($product, $variant);
        $currentQuantity = (int) ($items[$key]['quantity'] ?? 0);
        $nextQuantity = min($maxStock, max($product->effectiveMoq(), $currentQuantity + $quantity));

        if ($nextQuantity < 1) {
            return;
        }

        $items[$key] = [
            'product_id' => $product->getKey(),
            'variant_id' => $variant?->getKey(),
            'quantity' => $nextQuantity,
        ];

        $this->put($company, $items, $reseller);
    }

    public function update(Company $company, Product $product, int $quantity, ?ProductVariant $variant = null): void
    {
        $items = $this->raw($company);
        $key = $this->lineKey($product, $variant);

        if ($quantity < 1) {
            unset($items[$key]);
            $this->put($company, $items);

            return;
        }

        $items[$key] = [
            'product_id' => $product->getKey(),
            'variant_id' => $variant?->getKey(),
            'quantity' => min($this->availableStock($product, $variant), max($product->effectiveMoq(), $quantity)),
        ];

        $this->put($company, $items);
    }

    public function remove(Company $company, Product $product, ?ProductVariant $variant = null): void
    {
        $items = $this->raw($company);
        unset($items[$this->lineKey($product, $variant)]);
        $this->put($company, $items);
    }

    public function clear(Company $company, ?Order $order = null): void
    {
        $record = $this->cartRecord($company);
        $this->session->forget($this->key($company));
        $record?->markConverted($order);
        $this->session->forget('storefront_cart_token');
    }

    /**
     * Attach the checkout contact to the persisted cart record so
     * abandoned-cart reminders can reach the customer.
     */
    public function rememberContact(Company $company, string $phone, ?string $name = null): void
    {
        $this->rememberCheckout($company, ['phone' => $phone, 'name' => $name]);
    }

    public function startCheckout(Company $company, float $subtotal, array $contact = []): void
    {
        $record = $this->cartRecord($company);

        if (! $record || $record->status === StorefrontCartRecord::STATUS_CONVERTED) {
            return;
        }

        $updates = [
            'subtotal' => max(0, $subtotal),
            'checkout_started_at' => $record->checkout_started_at ?? now(),
            'last_activity_at' => now(),
        ];

        if ($record->status === StorefrontCartRecord::STATUS_ACTIVE) {
            $updates['status'] = StorefrontCartRecord::STATUS_CHECKOUT_STARTED;
        }

        $record->update($updates);
        $this->rememberCheckout($company, $contact, $subtotal);
    }

    public function rememberCheckout(Company $company, array $data, ?float $subtotal = null): void
    {
        $record = $this->cartRecord($company);

        if (! $record || $record->status === StorefrontCartRecord::STATUS_CONVERTED) {
            return;
        }

        $updates = ['last_activity_at' => now()];
        $name = trim((string) ($data['name'] ?? ''));
        $phone = $this->normalizePhone((string) ($data['phone'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));

        if ($name !== '') {
            $updates['customer_name'] = Str::limit($name, 255, '');
        }

        if ($phone !== null) {
            $updates['phone'] = $phone;
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $updates['email'] = Str::lower(Str::limit($email, 255, ''));
        }

        if ($address !== '') {
            $updates['address'] = Str::limit($address, 1000, '');
        }

        if ($subtotal !== null) {
            $updates['subtotal'] = max(0, $subtotal);
        }

        $record->update($updates);
    }

    public function recordId(Company $company): ?int
    {
        return $this->cartRecord($company)?->getKey();
    }

    /**
     * The reseller this cart was attributed to (see add()'s $reseller
     * param), if any -- read at checkout time so the resulting Order can
     * record which reseller's store it came through.
     */
    public function resellerCustomerId(Company $company): ?int
    {
        return $this->cartRecord($company)?->reseller_customer_id;
    }

    /**
     * The reseller an order placed from $items belongs to: whoever priced
     * its reseller lines (so the commission goes to the reseller whose
     * price the customer paid), else the cart's stamped attribution.
     */
    public function orderResellerId(Company $company, iterable $items): ?int
    {
        $pricedByReseller = collect($items)->contains(fn (array $item): bool => ($item['reseller_base_price'] ?? null) !== null);
        $reseller = app(ResellerPricing::class)->reseller();

        return $pricedByReseller && $reseller ? (int) $reseller->getKey() : $this->resellerCustomerId($company);
    }

    public function restore(Company $company, StorefrontCartRecord $record): void
    {
        abort_unless($record->company_id === $company->getKey() && $record->status !== StorefrontCartRecord::STATUS_CONVERTED, 404);

        $items = collect($record->items)
            ->filter(fn ($item): bool => is_array($item) && (int) ($item['product_id'] ?? 0) > 0 && (int) ($item['quantity'] ?? 0) > 0)
            ->mapWithKeys(fn (array $item): array => [
                ((int) $item['product_id']).':'.((int) ($item['variant_id'] ?? 0)) => [
                    'product_id' => (int) $item['product_id'],
                    'variant_id' => ! empty($item['variant_id']) ? (int) $item['variant_id'] : null,
                    'quantity' => (int) $item['quantity'],
                ],
            ])
            ->all();

        abort_if($items === [], 404);

        $this->session->put('storefront_cart_token', $record->session_id);
        $this->session->put($this->key($company), $items);
        $record->forceFill([
            'status' => StorefrontCartRecord::STATUS_CHECKOUT_STARTED,
            'checkout_started_at' => $record->checkout_started_at ?? now(),
            'recovery_opened_at' => $record->recovery_opened_at ?? now(),
            'last_activity_at' => now(),
        ])->save();
    }

    protected function cartRecord(Company $company): ?StorefrontCartRecord
    {
        if (! Schema::hasTable('storefront_cart_records')) {
            return null;
        }

        return StorefrontCartRecord::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('session_id', $this->cartToken())
            ->first();
    }

    /**
     * Stable per-visitor token for the persisted cart record. Session IDs
     * regenerate, so a dedicated token is kept in the session instead.
     */
    protected function cartToken(): string
    {
        $token = $this->session->get('storefront_cart_token');

        if (! is_string($token) || $token === '') {
            $token = (string) Str::uuid();
            $this->session->put('storefront_cart_token', $token);
        }

        return $token;
    }

    protected function persistCartRecord(Company $company, array $items, ?Customer $reseller = null): void
    {
        if (! Schema::hasTable('storefront_cart_records')) {
            return;
        }

        if ($items === []) {
            $this->cartRecord($company)?->delete();

            return;
        }

        $record = $this->cartRecord($company);

        if ($record?->status === StorefrontCartRecord::STATUS_CONVERTED) {
            $this->session->forget('storefront_cart_token');
        }

        $updates = [
            'items' => array_values($items),
            'status' => StorefrontCartRecord::STATUS_ACTIVE,
            'reminded_at' => null,
            'last_activity_at' => now(),
        ];

        // Only stamped when this add-to-cart happened under a reseller's
        // store -- never cleared by a later, non-reseller add, so the cart
        // keeps its "came through this reseller" attribution for checkout.
        if ($reseller) {
            $updates['reseller_customer_id'] = $reseller->getKey();
        }

        StorefrontCartRecord::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->getKey(), 'session_id' => $this->cartToken()],
            $updates,
        );
    }

    public function items(Company $company): Collection
    {
        $items = $this->raw($company);

        if ($items === []) {
            return collect();
        }

        $products = Product::query()
            ->with(['category', 'activeVariants'])
            ->whereIn('id', collect($items)->pluck('product_id')->unique()->all())
            ->where('is_active', true)
            ->where('status', Product::STATUS_AVAILABLE)
            ->get()
            ->keyBy('id');

        $changed = false;

        $cartItems = collect($items)
            ->map(function (array $item) use ($products, &$changed): ?array {
                $product = $products->get($item['product_id']);

                if (! $product) {
                    $changed = true;

                    return null;
                }

                $variant = null;

                if (! empty($item['variant_id'])) {
                    $variant = $product->activeVariants->firstWhere('id', (int) $item['variant_id']);

                    if (! $variant) {
                        $changed = true;

                        return null;
                    }
                }

                $maxStock = $this->availableStock($product, $variant);

                if ($maxStock < 1) {
                    $changed = true;

                    return null;
                }

                // MOQ floor first, then stock cap wins if stock is below MOQ.
                $quantity = min(max((int) $item['quantity'], $product->effectiveMoq()), $maxStock);

                if ($quantity !== (int) $item['quantity']) {
                    $changed = true;
                }

                $unitPrice = $variant
                    ? $variant->effectiveSalePrice()
                    : $product->priceForQuantity($quantity);

                return [
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $unitPrice * $quantity,
                    // Set only on reseller-priced lines; frozen onto the
                    // order item so the reseller's commission can't drift
                    // if tier prices change after checkout.
                    'reseller_base_price' => $variant
                        ? app(ResellerPricing::class)->variantBasePriceFor($variant)
                        : app(ResellerPricing::class)->basePriceFor($product),
                ];
            })
            ->filter()
            ->values();

        if ($changed) {
            $this->put(
                $company,
                $cartItems
                    ->mapWithKeys(fn (array $item): array => [
                        $this->lineKey($item['product'], $item['variant']) => [
                            'product_id' => $item['product']->getKey(),
                            'variant_id' => $item['variant']?->getKey(),
                            'quantity' => $item['quantity'],
                        ],
                    ])
                    ->all(),
            );
        }

        return $cartItems;
    }

    public function count(Company $company): int
    {
        return $this->items($company)->sum('quantity');
    }

    public function subtotal(Company $company): float
    {
        return (float) $this->items($company)->sum('subtotal');
    }

    protected function availableStock(Product $product, ?ProductVariant $variant): int
    {
        if ($variant) {
            return (int) $variant->stock;
        }

        // Pre-order products can be ordered beyond current stock; the
        // checkout collects an online advance payment for those lines.
        if ($product->is_preorder) {
            return max((int) $product->stock, self::PREORDER_STOCK_CEILING);
        }

        return (int) $product->stock;
    }

    protected function lineKey(Product $product, ?ProductVariant $variant): string
    {
        return $product->getKey().':'.($variant?->getKey() ?? 0);
    }

    protected function raw(Company $company): array
    {
        $items = $this->session->get($this->key($company), []);

        return is_array($items) ? $items : [];
    }

    protected function put(Company $company, array $items, ?Customer $reseller = null): void
    {
        $this->session->put($this->key($company), $items);

        $this->persistCartRecord($company, $items, $reseller);
    }

    protected function key(Company $company): string
    {
        return 'storefront_cart.'.$company->getKey();
    }

    protected function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '880')) {
            $digits = '0'.substr($digits, 3);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) === 1 ? $digits : null;
    }
}
