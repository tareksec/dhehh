<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Support\WooCommerceDescription;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One-off repair for products imported from WooCommerce before the importer
 * kept formatting: their description is the old site's text with every tag
 * stripped (list items run together, headings and bold lost).
 *
 * The original HTML comes from the old site's public Store API (no keys
 * needed; it is what visitors saw, shortcodes and paragraphs included) and is
 * matched to products by SKU, then slug. A description is only replaced while
 * it is still exactly that untouched plain text (see WooCommerceDescription::decide()),
 * so anything the team wrote or saved in the editor is never overwritten.
 * Company scoped; running it twice changes nothing the second time.
 */
class WooCommerceDescriptionRepairService
{
    public function __construct(protected CompanyContext $context) {}

    /** The old site's address: the override, else the company's WooCommerce URL. */
    public function baseUrlFor(Company $company, ?string $override = null): string
    {
        $url = trim((string) $override) !== ''
            ? trim((string) $override)
            : (string) StorefrontSetting::primaryFor((int) $company->getKey())?->woocommerce_base_url;

        if ($url === '') {
            throw new RuntimeException('No WooCommerce site address: set it in Storefront Settings or pass --base-url.');
        }

        return rtrim($url, '/');
    }

    /**
     * Every published product of the old site, with its HTML description.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchStoreProducts(string $baseUrl): array
    {
        $products = [];
        $page = 1;

        do {
            $response = Http::timeout(30)
                ->retry(2, 1000, throw: false)
                ->acceptJson()
                ->get("{$baseUrl}/wp-json/wc/store/v1/products", ['per_page' => 100, 'page' => $page]);

            if ($response->failed()) {
                throw new RuntimeException("The old site's product list could not be read (HTTP {$response->status()}) on page {$page}.");
            }

            $batch = $response->json();

            if (! is_array($batch)) {
                throw new RuntimeException('The old site returned an unexpected product list.');
            }

            foreach ($batch as $payload) {
                if (is_array($payload)) {
                    $products[] = $payload;
                }
            }

            $page++;
        } while (count($batch) === 100);

        return $products;
    }

    /**
     * What would change, product by product. Nothing is written.
     *
     * @param  array<int, array<string, mixed>>  $wooProducts
     * @param  array<int, string>  $onlySkus  limit to these product SKUs (empty = every product of the company)
     * @return list<array{product_id: int, sku: string, name: string, action: string, reason: string, before: string, after: ?string}>
     */
    public function plan(Company $company, array $wooProducts, array $onlySkus = [], bool $fillBlank = false): array
    {
        $this->context->set($company);

        [$bySku, $bySlug] = $this->index($wooProducts);

        $onlySkus = array_map('mb_strtolower', array_filter(array_map('trim', $onlySkus)));
        $rows = [];

        $query = Product::query()->where('company_id', $company->getKey())->orderBy('id');

        foreach ($query->get() as $product) {
            if ($onlySkus !== [] && ! in_array(mb_strtolower((string) $product->sku), $onlySkus, true)) {
                continue;
            }

            $payload = $this->matchPayload($product, $bySku, $bySlug);

            $row = [
                'product_id' => (int) $product->getKey(),
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'before' => (string) $product->description,
                'after' => null,
            ];

            if ($payload === null) {
                $rows[] = [...$row, 'action' => 'keep', 'reason' => 'no-match'];

                continue;
            }

            $source = $this->sourceHtml($payload);
            $decision = WooCommerceDescription::decide($product->description, $source, $fillBlank);

            $rows[] = [
                ...$row,
                'action' => $decision['action'],
                'reason' => $decision['reason'],
                'after' => $decision['html'],
            ];
        }

        return $rows;
    }

    /**
     * Writes the rows a plan marked "update". Each product is read again and
     * checked again first, so a description edited since the plan was made is
     * left alone.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  array<int, array<string, mixed>>  $wooProducts  the same list the plan was made from
     * @return int number of products changed
     */
    public function apply(Company $company, array $plan, array $wooProducts, bool $fillBlank = false): int
    {
        $this->context->set($company);

        [$bySku, $bySlug] = $this->index($wooProducts);
        $changed = 0;

        foreach ($plan as $row) {
            if (($row['action'] ?? null) !== 'update') {
                continue;
            }

            $product = Product::query()->where('company_id', $company->getKey())->find($row['product_id']);

            if (! $product instanceof Product) {
                continue;
            }

            $payload = $this->matchPayload($product, $bySku, $bySlug);

            if ($payload === null) {
                continue;
            }

            $decision = WooCommerceDescription::decide($product->description, $this->sourceHtml($payload), $fillBlank);

            if ($decision['action'] !== 'update') {
                continue;
            }

            $product->description = $decision['html'];
            $product->save();
            $changed++;
        }

        return $changed;
    }

    /** Same rule the importer uses: the full description, else the short one. */
    protected function sourceHtml(array $payload): string
    {
        return (string) (($payload['description'] ?? '') ?: ($payload['short_description'] ?? ''));
    }

    /**
     * @param  array<int, array<string, mixed>>  $wooProducts
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    protected function index(array $wooProducts): array
    {
        $bySku = [];
        $bySlug = [];

        foreach ($wooProducts as $payload) {
            $sku = trim((string) ($payload['sku'] ?? ''));

            if ($sku !== '') {
                $bySku[mb_strtolower($sku)] = $payload;
            }

            $slug = trim((string) ($payload['slug'] ?? ''));

            if ($slug !== '') {
                $bySlug[$slug] = $payload;
            }
        }

        return [$bySku, $bySlug];
    }

    /** SKU first, then slug — the same order the importer matches in. */
    protected function matchPayload(Product $product, array $bySku, array $bySlug): ?array
    {
        return $bySku[mb_strtolower((string) $product->sku)] ?? $bySlug[(string) $product->slug] ?? null;
    }
}
