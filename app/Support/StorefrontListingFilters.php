<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Optional storefront catalog filters carried in the query string
 * (`in_stock=1`, `min_price`, `max_price`) so a filtered listing works
 * without JavaScript and can be shared as a link. Price bounds match the
 * listing's price sort: the product's own sale price, else its price.
 */
final class StorefrontListingFilters
{
    public function __construct(
        public readonly bool $inStock = false,
        public readonly ?float $minPrice = null,
        public readonly ?float $maxPrice = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $min = self::price($request->query('min_price'));
        $max = self::price($request->query('max_price'));

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return new self($request->boolean('in_stock'), $min, $max);
    }

    public function active(): bool
    {
        return $this->inStock || $this->minPrice !== null || $this->maxPrice !== null;
    }

    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->inStock, fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('stock', '>', 0)->orWhere('is_preorder', true)))
            ->when($this->minPrice !== null, fn (Builder $query) => $query->whereRaw('COALESCE(sale_price, price) >= CAST(? AS DECIMAL(15, 2))', [$this->minPrice]))
            ->when($this->maxPrice !== null, fn (Builder $query) => $query->whereRaw('COALESCE(sale_price, price) <= CAST(? AS DECIMAL(15, 2))', [$this->maxPrice]));
    }

    private static function price(mixed $value): ?float
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $price = (float) $value;

        return $price >= 0 ? $price : null;
    }
}
