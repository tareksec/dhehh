<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Support\StorefrontApi\Media;

/**
 * The full product, as the external website API returns it and as the
 * product.created / product.updated webhooks send it — one shape for both, so
 * a website can create or update its own copy of the product from either.
 * Image fields are absolute URLs the website downloads; `images` is the
 * featured image followed by the gallery, without repeats.
 */
final class WebsiteProductPayload
{
    /**
     * Product columns the website mirrors. A change to any of them is sent to
     * the website (Product::booted()); stock is sent from StockMovement instead.
     */
    public const SYNCED_FIELDS = [
        'name', 'slug', 'brand', 'category_id', 'description', 'specifications',
        'image', 'gallery_images', 'price', 'sale_price', 'is_active', 'status',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function for(Product $product): array
    {
        $company = Company::query()->find($product->company_id);
        $category = $product->category_id
            ? Category::withoutGlobalScopes()->where('company_id', $product->company_id)->find($product->category_id)
            : null;

        $imagePaths = collect([$product->image, ...($product->gallery_images ?? [])])
            ->filter(fn ($path): bool => is_string($path) && $path !== '')
            ->unique()
            ->values();

        return [
            'sku' => $product->sku,
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand,
            'category' => $category ? ['name' => $category->name, 'slug' => $category->slug] : null,
            'description' => $product->description,
            'specifications' => self::specificationList($product->specifications),
            'price' => (float) $product->price,
            'sale_price' => $product->sale_price !== null ? (float) $product->sale_price : null,
            'stock' => (int) $product->stock,
            'is_active' => (bool) $product->is_active,
            'status' => $product->status,
            'image' => $company ? Media::url($product->image, $company) : null,
            'images' => $company ? Media::urls($imagePaths, $company) : [],
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Stored as an ordered label => value map (the product form's key-value
     * field); sent as a list so the order survives every JSON parser.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function specificationList(mixed $specifications): array
    {
        return collect(is_array($specifications) ? $specifications : [])
            ->map(fn ($value, $label): array => ['label' => trim((string) $label), 'value' => trim((string) $value)])
            ->filter(fn (array $row): bool => $row['label'] !== '' && $row['value'] !== '')
            ->values()
            ->all();
    }

    /**
     * The API's `specifications` input — a list of {label, value} or a plain
     * label => value object — as the map stored on the product.
     *
     * @return array<string, string>
     */
    public static function specificationMap(mixed $input): array
    {
        $map = [];

        foreach (is_array($input) ? $input : [] as $key => $row) {
            if (is_int($key) && ! is_array($row)) {
                continue;
            }

            [$label, $value] = is_array($row)
                ? [$row['label'] ?? '', $row['value'] ?? '']
                : [$key, $row];

            $label = trim((string) $label);
            $value = trim((string) $value);

            if ($label !== '' && $value !== '') {
                $map[$label] = $value;
            }
        }

        return $map;
    }
}
