<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Support\StorefrontApi\Media;
use App\Support\WebsiteProductPayload;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Applies a validated website API product payload (POST /products, PATCH
 * /products/{sku}) to a Product: plain columns, the category by name, the
 * specifications list and the image URLs, which are downloaded into the
 * company's own storage. Stock is not handled here — the controller sends it
 * through the stock ledger. Everything is saved with suppressWebsiteWebhook
 * set, so the website's own change is never echoed back to it.
 */
class WebsiteProductWriter
{
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    protected const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> image URLs that could not be downloaded
     */
    public function apply(Product $product, array $data, Company $company): array
    {
        $product->fill(Arr::only($data, ['name', 'brand', 'description', 'price', 'sale_price', 'is_active', 'status']));

        if (array_key_exists('category', $data)) {
            $product->category_id = $this->categoryId($data['category']);
        }

        if (array_key_exists('specifications', $data)) {
            $product->specifications = WebsiteProductPayload::specificationMap($data['specifications']) ?: null;
        }

        $failed = [];

        if (array_key_exists('images', $data)) {
            [$paths, $failed] = $this->storeImages($product, (array) ($data['images'] ?? []), $company);

            // A list where nothing could be downloaded leaves the photos as they were.
            if ($paths !== [] || $data['images'] === [] || $data['images'] === null) {
                $product->image = $paths[0] ?? null;
                $product->gallery_images = array_slice($paths, 1) ?: null;
            }
        }

        $product->suppressWebsiteWebhook = true;
        $product->save();

        return $failed;
    }

    protected function categoryId(mixed $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name) ?: 'category';

        $existing = Category::query()
            ->where(fn ($query) => $query->where('name', $name)->orWhere('slug', $slug))
            ->first();

        if ($existing) {
            return $existing->getKey();
        }

        $unique = $slug;
        $suffix = 2;

        while (Category::query()->where('slug', $unique)->exists()) {
            $unique = "{$slug}-{$suffix}";
            $suffix++;
        }

        return Category::query()->create(['name' => $name, 'slug' => $unique, 'is_active' => true])->getKey();
    }

    /**
     * An URL that is already one of this product's own images keeps its stored
     * file instead of being downloaded again.
     *
     * @param  list<mixed>  $urls
     * @return array{0: list<string>, 1: list<string>}
     */
    protected function storeImages(Product $product, array $urls, Company $company): array
    {
        $known = [];

        foreach (array_filter([$product->image, ...($product->gallery_images ?? [])]) as $path) {
            $url = Media::url($path, $company);

            if ($url) {
                $known[$url] = $path;
            }
        }

        $paths = [];
        $failed = [];

        foreach (array_slice(array_values($urls), 0, 11) as $url) {
            $url = trim((string) $url);

            if ($url === '') {
                continue;
            }

            $path = $known[$url] ?? $this->download($url, $company);

            if ($path === null) {
                $failed[] = $url;

                continue;
            }

            $paths[] = $path;
        }

        return [array_values(array_unique($paths)), $failed];
    }

    protected function download(string $url, Company $company): ?string
    {
        if (! $this->isPublicHttpUrl($url)) {
            return null;
        }

        try {
            $response = Http::timeout(20)->withOptions(['allow_redirects' => false])->get($url);
        } catch (\Throwable) {
            return null;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();

        if ($response->failed() || ! isset(self::IMAGE_TYPES[$type]) || $body === '' || strlen($body) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return rescue(fn (): string => app(CompanyStorageService::class)->putPublic(
            $company,
            'products',
            'website-'.Str::random(20).'.'.self::IMAGE_TYPES[$type],
            $body,
        ), report: false);
    }

    /** http(s) only, and never a private, loopback or reserved address. */
    protected function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return false;
        }

        if (strtolower($host) === 'localhost') {
            return false;
        }

        // A name that does not resolve here fails at the download itself.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
