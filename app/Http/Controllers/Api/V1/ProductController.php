<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CompanyApiKey;
use App\Models\Product;
use App\Services\CompanyContext;
use App\Services\WebsiteProductWriter;
use App\Support\WebsiteProductPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Product/stock two-way sync for the external website integration.
 * CompanyContext is already set by ResolveCompanyFromApiKey, so every query
 * below is naturally scoped to the calling company via CompanyScope.
 *
 * Responses are the full product (WebsiteProductPayload), the same shape the
 * product.created / product.updated webhooks send.
 */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', 50), 200));

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $product): array => WebsiteProductPayload::for($product))->all(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(string $sku): JsonResponse
    {
        $product = Product::query()->where('sku', $sku)->firstOrFail();

        return response()->json(['data' => WebsiteProductPayload::for($product)]);
    }

    /**
     * Creates a product from the website. 409 when the SKU already exists
     * (PATCH it instead). No product.created webhook goes back to the website
     * that created it.
     */
    public function store(Request $request, WebsiteProductWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            ...$this->productRules(),
        ]);

        if (Product::query()->where('sku', $data['sku'])->exists()) {
            return response()->json([
                'message' => 'A product with this SKU already exists. Use PATCH /products/{sku} to change it.',
                'error' => 'sku_exists',
            ], 409);
        }

        $product = new Product([
            'sku' => $data['sku'],
            'unit' => 'pcs',
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);

        $failedImages = $writer->apply($product, $data, app(CompanyContext::class)->company());

        if (array_key_exists('stock', $data)) {
            $this->syncStock($request, $product, (int) $data['stock']);
        }

        return response()->json([
            'data' => WebsiteProductPayload::for($product->refresh()),
            'failed_images' => $failedImages,
        ], 201);
    }

    /**
     * Price/sale_price and the other product fields are written directly.
     * Stock is never overwritten directly — it goes through
     * Product::setStockFromProductForm(), which records the change as a
     * StockMovement (ledger-safe, same as an admin editing stock from the
     * Product form), stamped with this API key as its reference so
     * StockMovement::booted() knows not to echo a webhook back to the same
     * website that just made this change.
     */
    public function update(Request $request, string $sku, WebsiteProductWriter $writer): JsonResponse
    {
        $product = Product::query()->where('sku', $sku)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            ...$this->productRules(),
        ]);

        abort_if($data === [], 422, 'At least one product field (price, sale_price, stock, name, ...) is required.');

        $failedImages = [];
        $fields = array_diff(array_keys($data), ['stock']);

        if ($fields !== []) {
            $failedImages = $writer->apply($product, $data, app(CompanyContext::class)->company());
        }

        if (array_key_exists('stock', $data)) {
            $this->syncStock($request, $product, (int) $data['stock']);
        }

        return response()->json([
            'data' => WebsiteProductPayload::for($product->refresh()),
            ...($failedImages !== [] ? ['failed_images' => $failedImages] : []),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function productRules(): array
    {
        return [
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65000'],
            'specifications' => ['sometimes', 'nullable', 'array', 'max:100'],
            'images' => ['sometimes', 'nullable', 'array', 'max:11'],
            'images.*' => ['string', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(array_keys(Product::STATUSES))],
        ];
    }

    protected function syncStock(Request $request, Product $product, int $stock): void
    {
        /** @var CompanyApiKey $apiKey */
        $apiKey = $request->attributes->get('company_api_key');

        $product->setStockFromProductForm(
            $stock,
            referenceType: CompanyApiKey::class,
            referenceId: $apiKey->getKey(),
            reason: 'Website API sync',
            note: 'Stock synced from website API',
        );
    }
}
