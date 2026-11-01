<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StorefrontSetting;
use App\Support\WooCommerceDescription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One-time (re-runnable) product import from a WooCommerce site's REST API
 * into the company-scoped ERP Product/Category tables.
 *
 * Products are matched by SKU first (falling back to slug) so re-running the
 * import updates instead of duplicating. Stock is intentionally NOT imported:
 * ERP stock must come from stock movements, so imported products start at 0
 * stock unless they already exist.
 */
class WooCommerceImportService
{
    protected string $baseUrl = '';

    protected string $key = '';

    protected string $secret = '';

    public function __construct(protected CompanyContext $context) {}

    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function importProducts(Company $company, bool $downloadImages = true, ?callable $progress = null): array
    {
        $setting = StorefrontSetting::primaryFor((int) $company->getKey());

        if (! $setting instanceof StorefrontSetting || blank($setting->woocommerce_base_url)) {
            throw new RuntimeException('WooCommerce base URL is not configured in this company\'s storefront settings.');
        }

        $credentials = $setting->woocommerce_credentials ?? [];
        $key = (string) ($credentials['consumer_key'] ?? '');
        $secret = (string) ($credentials['consumer_secret'] ?? '');

        if ($key === '' || $secret === '') {
            throw new RuntimeException('WooCommerce consumer key/secret are not configured in this company\'s storefront settings.');
        }

        $this->context->set($company);

        $baseUrl = rtrim($setting->woocommerce_base_url, '/');
        $this->baseUrl = $baseUrl;
        $this->key = $key;
        $this->secret = $secret;
        $page = 1;
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        do {
            $response = Http::timeout(30)
                ->retry(2, 1000)
                ->withBasicAuth($key, $secret)
                ->get("{$baseUrl}/wp-json/wc/v3/products", [
                    'per_page' => 50,
                    'page' => $page,
                    'status' => 'publish',
                ]);

            if ($response->failed()) {
                throw new RuntimeException("WooCommerce API request failed (HTTP {$response->status()}) on page {$page}.");
            }

            $products = $response->json();

            if (! is_array($products)) {
                throw new RuntimeException('WooCommerce API returned an unexpected payload.');
            }

            foreach ($products as $payload) {
                $outcome = $this->importProduct($company, (array) $payload, $downloadImages);
                $result[$outcome]++;

                if ($progress) {
                    $progress($outcome, (string) ($payload['name'] ?? ''));
                }
            }

            $page++;
        } while (count($products) === 50);

        return $result;
    }

    protected function importProduct(Company $company, array $payload, bool $downloadImages): string
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            return 'skipped';
        }

        $sku = trim((string) ($payload['sku'] ?? ''));
        $slug = Str::slug((string) ($payload['slug'] ?? $name));

        $product = null;

        if ($sku !== '') {
            $product = Product::query()->where('sku', $sku)->first();
        }

        $product ??= Product::query()->where('slug', $slug)->first();

        $regularPrice = $this->toPrice($payload['regular_price'] ?? null) ?? $this->toPrice($payload['price'] ?? null) ?? 0.0;
        $salePrice = $this->toPrice($payload['sale_price'] ?? null) ?? $regularPrice;

        $attributes = [
            'name' => $name,
            'price' => $regularPrice,
            'sale_price' => $salePrice,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
            'category_id' => $this->resolveCategory($payload['categories'] ?? [])?->getKey(),
        ];

        if (($weight = $this->toWeight($payload['weight'] ?? null)) !== null) {
            $attributes['weight_kg'] = $weight;
        }

        if ($brand = $this->resolveBrand($payload)) {
            $attributes['brand'] = $brand;
        }

        // Full description first — the short description alone loses most of
        // the product information written on the old site. Kept as HTML (what
        // the product form's rich editor stores) so headings, lists, bold,
        // links, tables and line breaks come across as written. A description
        // the team already has is only replaced while it is still the
        // untouched plain text of an older import, so a re-sync never
        // overwrites hand-written text.
        $description = WooCommerceDescription::decide(
            $product?->description,
            (string) (($payload['description'] ?? '') ?: ($payload['short_description'] ?? '')),
            fillBlank: true,
        );

        if ($description['action'] === 'update') {
            $attributes['description'] = $description['html'];
        }

        $isNew = ! $product;

        if ($isNew) {
            $product = new Product([
                ...$attributes,
                'sku' => $sku !== '' ? $sku : 'WOO-'.Str::upper(Str::random(8)),
                'slug' => $slug,
                'unit' => 'pcs',
                'cost_price' => 0,
                'stock' => 0,
                'reorder_level' => 0,
                'vat_rate' => 0,
            ]);
            $product->company_id = $company->getKey();
        } else {
            $product->fill($attributes);
        }

        if ($downloadImages && blank($product->image)) {
            $imageUrl = (string) (data_get($payload, 'images.0.src') ?? '');

            if ($imageUrl !== '') {
                $product->image = $this->downloadImage($imageUrl, $company, 'products') ?? $product->image;
            }
        }

        // Remaining WooCommerce images become the gallery (only filled once,
        // so admin-curated galleries are never overwritten on re-sync).
        if ($downloadImages && blank($product->gallery_images)) {
            $gallery = collect($payload['images'] ?? [])
                ->skip(1)
                ->map(fn ($image) => $this->downloadImage((string) (data_get($image, 'src') ?? ''), $company, 'products/gallery'))
                ->filter()
                ->values()
                ->all();

            if ($gallery !== []) {
                $product->gallery_images = $gallery;
            }
        }

        $product->save();

        if (($payload['type'] ?? '') === 'variable') {
            $this->importVariations($product, $payload, $downloadImages);
        }

        return $isNew ? 'created' : 'updated';
    }

    /**
     * Pulls a variable product's variations and upserts them as ProductVariant
     * rows (matched by variation SKU, falling back to the options signature).
     * Variant stock is NOT imported — same rule as product stock.
     */
    protected function importVariations(Product $product, array $payload, bool $downloadImages): void
    {
        $wooProductId = (int) ($payload['id'] ?? 0);

        if ($wooProductId <= 0) {
            return;
        }

        // Attribute names used for variations (e.g. Size, Color) — stored on
        // the product for reference in the admin form.
        $variationAttributes = collect($payload['attributes'] ?? [])
            ->filter(fn ($attribute) => (bool) data_get($attribute, 'variation'))
            ->mapWithKeys(fn ($attribute) => [(string) data_get($attribute, 'name') => array_map('strval', (array) data_get($attribute, 'options', []))])
            ->all();

        $page = 1;
        $sortOrder = 0;

        do {
            $response = Http::timeout(30)
                ->retry(2, 1000)
                ->withBasicAuth($this->key, $this->secret)
                ->get("{$this->baseUrl}/wp-json/wc/v3/products/{$wooProductId}/variations", [
                    'per_page' => 50,
                    'page' => $page,
                ]);

            if ($response->failed()) {
                return; // Keep the base product; variations can be re-synced later.
            }

            $variations = $response->json();

            if (! is_array($variations)) {
                return;
            }

            foreach ($variations as $variation) {
                $this->importVariation($product, (array) $variation, $downloadImages, $sortOrder);
                $sortOrder++;
            }

            $page++;
        } while (count($variations) === 50);

        if ($sortOrder > 0) {
            $product->forceFill([
                'has_variants' => true,
                'variant_attributes' => $variationAttributes ?: $product->variant_attributes,
            ])->save();
        }
    }

    protected function importVariation(Product $product, array $variation, bool $downloadImages, int $sortOrder): void
    {
        $options = collect($variation['attributes'] ?? [])
            ->mapWithKeys(fn ($attribute) => [(string) data_get($attribute, 'name') => (string) data_get($attribute, 'option')])
            ->filter(fn ($value, $key) => $key !== '' && $value !== '')
            ->all();

        if ($options === []) {
            return;
        }

        $sku = trim((string) ($variation['sku'] ?? ''));

        $variant = null;

        if ($sku !== '' && $sku !== $product->sku) {
            $variant = ProductVariant::query()->where('product_id', $product->getKey())->where('sku', $sku)->first();
        }

        $variant ??= ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->get()
            ->first(fn (ProductVariant $existing): bool => $this->optionsSignature($existing->options ?? []) === $this->optionsSignature($options));

        $regularPrice = $this->toPrice($variation['regular_price'] ?? null) ?? $this->toPrice($variation['price'] ?? null);
        $salePrice = $this->toPrice($variation['sale_price'] ?? null) ?? $regularPrice;

        $attributes = [
            'options' => $options,
            'sale_price' => $salePrice,
            'is_active' => ($variation['status'] ?? 'publish') === 'publish',
            'sort_order' => (int) ($variation['menu_order'] ?? $sortOrder) ?: $sortOrder,
        ];

        // WooCommerce sends an empty weight when the variation inherits the
        // product's — same as an empty weight here, so only a real one is set.
        if (($weight = $this->toWeight($variation['weight'] ?? null)) !== null) {
            $attributes['weight_kg'] = $weight;
        }

        if ($variant) {
            $variant->fill($attributes);
        } else {
            $variant = new ProductVariant([
                ...$attributes,
                'product_id' => $product->getKey(),
                'sku' => ($sku !== '' && $sku !== $product->sku) ? $sku : null,
                'stock' => 0,
            ]);
            $variant->company_id = $product->company_id;
        }

        if ($downloadImages && blank($variant->images)) {
            $imageUrl = (string) (data_get($variation, 'image.src') ?? '');

            if ($imageUrl !== '' && ($path = $this->downloadImage($imageUrl, $product->company, 'products/variants'))) {
                $variant->images = [$path];
            }
        }

        $variant->save();
    }

    protected function optionsSignature(array $options): string
    {
        $normalized = collect($options)
            ->mapWithKeys(fn ($value, $key) => [mb_strtolower(trim((string) $key)) => mb_strtolower(trim((string) $value))])
            ->sortKeys()
            ->all();

        return json_encode($normalized) ?: '';
    }

    /**
     * WooCommerce core has no brand field; most stores expose it as a
     * product attribute named "Brand".
     */
    protected function resolveBrand(array $payload): ?string
    {
        $brand = collect($payload['attributes'] ?? [])
            ->first(fn ($attribute) => mb_strtolower(trim((string) data_get($attribute, 'name'))) === 'brand');

        $value = trim((string) (data_get($brand, 'options.0') ?? ''));

        return $value !== '' ? $value : null;
    }

    protected function resolveCategory(array $categories): ?Category
    {
        $name = trim((string) (data_get($categories, '0.name') ?? ''));

        if ($name === '') {
            return null;
        }

        $existing = Category::query()->where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        $slug = Str::slug($name) ?: 'category';
        $unique = $slug;
        $suffix = 2;

        while (Category::query()->where('slug', $unique)->exists()) {
            $unique = "{$slug}-{$suffix}";
            $suffix++;
        }

        return Category::query()->create([
            'name' => $name,
            'slug' => $unique,
            'is_active' => true,
        ]);
    }

    protected function downloadImage(string $url, Company $company, string $area): ?string
    {
        try {
            $response = Http::timeout(20)->get($url);

            if ($response->failed()) {
                return null;
            }

            $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'jpg';

            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $extension = 'jpg';
            }

            return app(CompanyStorageService::class)->putPublic(
                $company,
                $area,
                'woo-'.Str::random(20).'.'.$extension,
                $response->body(),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** The weight as sent by WooCommerce (kg, like the product weight); null when empty or zero. */
    protected function toWeight(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    protected function toPrice(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }
}
