<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductCsvService
{
    public const HEADINGS = [
        'sku',
        'name',
        'category',
        'barcode',
        'brand',
        'unit',
        'cost_price',
        'sale_price',
        'stock',
        'reorder_level',
        'vat_rate',
        'status',
        'is_active',
        'description',
    ];

    /**
     * A lightweight, focused sibling of the full product import above —
     * only touches the `stock` field, keyed by SKU, matching the
     * competitor ERP's dedicated Inventory-page "Bulk Update Inventory"
     * tool (their version is warehouse-scoped; we have no warehouse
     * concept, so this is scoped to the whole catalog instead). SKUs are
     * looked up across both `Product` and `ProductVariant`, since a
     * variant carries its own SKU distinct from its parent product's.
     */
    public const STOCK_HEADINGS = ['sku', 'stock'];

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::HEADINGS);

            Product::query()
                ->with('category')
                ->orderBy('name')
                ->chunk(200, function (Collection $products) use ($handle): void {
                    foreach ($products as $product) {
                        fputcsv($handle, $this->productRow($product));
                    }
                });

            fclose($handle);
        }, 'products-export.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function sample(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::HEADINGS);
            fputcsv($handle, [
                'DEMO-SKU-001',
                'Demo Router',
                'Electronics',
                '880100000001',
                'Mercury',
                'pcs',
                '1450',
                '2200',
                '25',
                '5',
                '0',
                Product::STATUS_AVAILABLE,
                'yes',
                'Sample product row. Replace this with your own product data.',
            ]);
            fclose($handle);
        }, 'products-import-sample.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function import(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            throw ValidationException::withMessages([
                'csv' => 'Unable to read the uploaded CSV file.',
            ]);
        }

        $headings = $this->normalizeHeadings(fgetcsv($handle) ?: []);
        $missingHeadings = array_diff(['sku', 'name', 'sale_price'], $headings);

        if ($missingHeadings !== []) {
            fclose($handle);

            throw ValidationException::withMessages([
                'csv' => 'Missing required CSV columns: '.implode(', ', $missingHeadings),
            ]);
        }

        $created = 0;
        $updated = 0;
        $rowNumber = 1;
        $errors = [];

        try {
            DB::transaction(function () use ($handle, $headings, &$created, &$updated, &$rowNumber, &$errors): void {
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    $data = $this->combineRow($headings, $row);

                    if ($this->isEmptyRow($data)) {
                        continue;
                    }

                    try {
                        $product = $this->upsertProduct($data);
                        $product->wasRecentlyCreated ? $created++ : $updated++;
                    } catch (ValidationException $exception) {
                        foreach ($exception->errors() as $messages) {
                            foreach ($messages as $message) {
                                $errors[] = "Row {$rowNumber}: {$message}";
                            }
                        }
                    }
                }

                if ($errors !== []) {
                    throw ValidationException::withMessages([
                        'csv' => $errors,
                    ]);
                }
            });
        } finally {
            fclose($handle);
        }

        return [
            'created' => $created,
            'updated' => $updated,
        ];
    }

    public function sampleStock(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::STOCK_HEADINGS);
            fputcsv($handle, ['DEMO-SKU-001', '25']);
            fclose($handle);
        }, 'products-stock-sample.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * @return array{updated: int}
     */
    public function importStock(string $path): array
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            throw ValidationException::withMessages([
                'csv' => 'Unable to read the uploaded CSV file.',
            ]);
        }

        $headings = $this->normalizeHeadings(fgetcsv($handle) ?: []);
        $missingHeadings = array_diff(self::STOCK_HEADINGS, $headings);

        if ($missingHeadings !== []) {
            fclose($handle);

            throw ValidationException::withMessages([
                'csv' => 'Missing required CSV columns: '.implode(', ', $missingHeadings),
            ]);
        }

        $updated = 0;
        $rowNumber = 1;
        $errors = [];

        try {
            DB::transaction(function () use ($handle, $headings, &$updated, &$rowNumber, &$errors): void {
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    $data = $this->combineRow($headings, $row);

                    if ($this->isEmptyRow($data)) {
                        continue;
                    }

                    try {
                        $this->applyStockRow($data);
                        $updated++;
                    } catch (ValidationException $exception) {
                        foreach ($exception->errors() as $messages) {
                            foreach ($messages as $message) {
                                $errors[] = "Row {$rowNumber}: {$message}";
                            }
                        }
                    }
                }

                if ($errors !== []) {
                    throw ValidationException::withMessages([
                        'csv' => $errors,
                    ]);
                }
            });
        } finally {
            fclose($handle);
        }

        return [
            'updated' => $updated,
        ];
    }

    protected function applyStockRow(array $data): void
    {
        $sku = trim((string) ($data['sku'] ?? ''));

        if ($sku === '') {
            throw ValidationException::withMessages(['sku' => 'SKU is required.']);
        }

        $stock = $this->integer($data['stock'] ?? null, 'Stock');

        // Variant SKUs are checked first since a variant's SKU is distinct
        // from — and never collides with — its parent product's SKU.
        $variant = ProductVariant::query()->where('sku', $sku)->first();

        if ($variant) {
            // ProductVariant::stock is a live counter (unlike Product::stock,
            // which is ledger-derived), so a direct update is correct here —
            // its own saved() hook re-syncs the parent product's mirrored
            // stock automatically.
            $variant->update(['stock' => $stock]);

            return;
        }

        $product = Product::query()->where('sku', $sku)->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'sku' => "SKU \"{$sku}\" was not found.",
            ]);
        }

        if ($product->has_variants) {
            throw ValidationException::withMessages([
                'sku' => "SKU \"{$sku}\" belongs to a variant product — its stock is derived from its variants, so update the variant SKUs instead.",
            ]);
        }

        $product->setStockFromProductForm($stock);
    }

    protected function upsertProduct(array $data): Product
    {
        $sku = trim((string) ($data['sku'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $salePrice = $this->decimal($data['sale_price'] ?? null);

        if ($sku === '') {
            throw ValidationException::withMessages(['sku' => 'SKU is required.']);
        }

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Product name is required.']);
        }

        if ($salePrice === null) {
            throw ValidationException::withMessages(['sale_price' => 'Sale price is required and must be numeric.']);
        }

        $category = $this->categoryFor($data['category'] ?? null);
        $stock = $this->integer($data['stock'] ?? 0, 'Stock');

        if (Product::onlyTrashed()->where('sku', $sku)->exists()) {
            throw ValidationException::withMessages(['sku' => "SKU \"{$sku}\" belongs to a product in Trash. Restore it, or delete it permanently, first."]);
        }

        $product = Product::query()->firstOrNew(['sku' => $sku]);

        $product->fill([
            'name' => $name,
            'description' => $this->nullableString($data['description'] ?? null),
            'barcode' => $this->nullableString($data['barcode'] ?? null),
            'unit' => $this->nullableString($data['unit'] ?? null) ?: 'pcs',
            'brand' => $this->nullableString($data['brand'] ?? null),
            'cost_price' => $this->decimal($data['cost_price'] ?? 0) ?? 0,
            'sale_price' => $salePrice,
            'price' => $salePrice,
            'reorder_level' => $this->integer($data['reorder_level'] ?? 0, 'Reorder level'),
            'vat_rate' => $this->decimal($data['vat_rate'] ?? 0) ?? 0,
            'status' => $this->status($data['status'] ?? null),
            'is_active' => $this->boolean($data['is_active'] ?? true),
            'category_id' => $category?->getKey(),
        ]);

        $product->save();
        $product->setStockFromProductForm($stock);

        return $product;
    }

    protected function categoryFor(?string $name): ?Category
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name) ?: 'category-'.substr(md5($name), 0, 10);

        $existing = Category::query()->where('slug', $slug)->first();

        if ($existing) {
            return $existing;
        }

        // A category in Trash still holds its slug and is not brought back by an import:
        // the import gets a new category with the next free slug instead.
        $unique = $slug;
        $suffix = 2;

        while (Category::withTrashed()->where('slug', $unique)->exists()) {
            $unique = "{$slug}-{$suffix}";
            $suffix++;
        }

        return Category::query()->create([
            'slug' => $unique,
            'name' => $name,
            'description' => null,
            'is_active' => true,
        ]);
    }

    protected function productRow(Product $product): array
    {
        return [
            $product->sku,
            $product->name,
            $product->category?->name,
            $product->barcode,
            $product->brand,
            $product->unit,
            $product->cost_price,
            $product->selling_price,
            $product->stock,
            $product->reorder_level,
            $product->vat_rate,
            $product->status ?? Product::STATUS_AVAILABLE,
            $product->is_active ? 'yes' : 'no',
            $product->description,
        ];
    }

    protected function normalizeHeadings(array $headings): array
    {
        return collect($headings)
            ->map(fn ($heading): string => Str::of((string) $heading)
                ->replace("\xEF\xBB\xBF", '')
                ->trim()
                ->lower()
                ->replace([' ', '-'], '_')
                ->toString())
            ->all();
    }

    protected function combineRow(array $headings, array $row): array
    {
        $data = [];

        foreach ($headings as $index => $heading) {
            if ($heading === '') {
                continue;
            }

            $data[$heading] = $row[$index] ?? null;
        }

        return $data;
    }

    protected function isEmptyRow(array $data): bool
    {
        return collect($data)
            ->filter(fn ($value): bool => trim((string) $value) !== '')
            ->isEmpty();
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 255, '');
    }

    protected function decimal(mixed $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(',', '', $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    protected function integer(mixed $value, string $label): int
    {
        $number = $this->decimal($value);

        if ($number === null || $number < 0) {
            throw ValidationException::withMessages([
                Str::slug($label, '_') => "{$label} must be a non-negative number.",
            ]);
        }

        return (int) $number;
    }

    protected function boolean(mixed $value): bool
    {
        $value = Str::lower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'y', 'active', ''], true);
    }

    protected function status(mixed $value): string
    {
        $status = trim((string) $value) ?: Product::STATUS_AVAILABLE;

        if (! array_key_exists($status, Product::STATUSES)) {
            throw ValidationException::withMessages([
                'status' => 'Status must be one of: '.implode(', ', array_keys(Product::STATUSES)),
            ]);
        }

        return $status;
    }
}
