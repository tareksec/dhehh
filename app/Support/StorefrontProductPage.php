<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Product;
use App\Services\ResellerPricing;
use App\Services\StorefrontCart;
use Illuminate\Support\Collection;

/**
 * The purchase facts a storefront product page shows (gallery, variants,
 * stock, MOQ, quantity bounds, wholesale tiers, discount). Every theme's
 * products.show reads them from here so a theme only changes the design,
 * never the ordering rules.
 */
final class StorefrontProductPage
{
    /**
     * @param  Collection<int, string>  $galleryImages  Public image URLs, main image first.
     * @param  Collection<int, array{id: int, label: string, price: float, stock: int, sku: ?string, images: list<string>}>  $variantData
     * @param  list<array{min_qty: int, price: float}>  $tiers
     */
    private function __construct(
        public readonly Collection $galleryImages,
        public readonly ?string $videoUrl,
        public readonly bool $isVariableProduct,
        public readonly Collection $variantData,
        public readonly bool $inStock,
        public readonly bool $isPreorder,
        public readonly bool $orderable,
        public readonly int $moq,
        public readonly int $minOrderQuantity,
        public readonly int $maxOrderQuantity,
        public readonly array $tiers,
        public readonly float $sellingPrice,
        public readonly float $comparePrice,
        public readonly int $discountPercent,
    ) {}

    public static function make(Product $product, Company $company): self
    {
        $galleryImages = collect([$product->image])
            ->merge($product->gallery_images ?? [])
            ->filter()
            ->map(fn (string $image): ?string => CompanyMedia::publicUrl($image, $company))
            ->filter()
            ->values();

        $isVariableProduct = (bool) $product->has_variants;

        $resellerPricing = app(ResellerPricing::class);

        $variantData = $isVariableProduct
            ? $product->activeVariants()->get()->map(fn ($variant): array => [
                'id' => $variant->getKey(),
                'label' => $variant->label(),
                'price' => $variant->effectiveSalePrice(),
                // A variation a reseller has no selling price for cannot be
                // bought in their store (never sold at the wholesale rate).
                'stock' => $resellerPricing->variantBlocked($variant) ? 0 : (int) $variant->stock,
                'sku' => $variant->sku ?: $product->sku,
                'images' => collect($variant->images ?? [])->map(fn ($img) => CompanyMedia::publicUrl($img, $company))->filter()->values()->all(),
            ])->values()
            : collect();

        $inStock = $product->stock > 0;
        $isPreorder = (bool) $product->is_preorder;
        $moq = $product->effectiveMoq();
        $maxOrderQuantity = $isPreorder
            ? max((int) $product->stock, StorefrontCart::PREORDER_STOCK_CEILING)
            : max(1, (int) $product->stock);

        $sellingPrice = (float) $product->selling_price;
        $comparePrice = (float) ($product->price ?? 0);

        return new self(
            galleryImages: $galleryImages,
            // Only ever written by GeneratedVideoAttacher (AI Video Generation).
            videoUrl: CompanyMedia::publicUrl($product->video_path, $company),
            isVariableProduct: $isVariableProduct,
            variantData: $variantData,
            inStock: $inStock,
            isPreorder: $isPreorder,
            orderable: $isVariableProduct
                ? $variantData->contains(fn (array $variant): bool => $variant['stock'] > 0)
                : ($inStock || $isPreorder),
            moq: $moq,
            minOrderQuantity: min($moq, $maxOrderQuantity),
            maxOrderQuantity: $maxOrderQuantity,
            tiers: $product->normalizedTiers(),
            sellingPrice: $sellingPrice,
            comparePrice: $comparePrice,
            discountPercent: $comparePrice > 0 && $sellingPrice > 0 && $sellingPrice < $comparePrice
                ? (int) round((1 - ($sellingPrice / $comparePrice)) * 100)
                : 0,
        );
    }

    /** True when any variant brings its own photos (the thumbnail rail must stay). */
    public function variantsHaveImages(): bool
    {
        return $this->variantData->pluck('images')->flatten()->isNotEmpty();
    }
}
