<?php

namespace App\Filament\Trade\Resources\StockItems\Pages;

use App\Filament\Trade\Resources\StockItems\StockItemResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateStockItem extends CreateRecord
{
    protected static string $resource = StockItemResource::class;

    protected static bool $canCreateAnother = false;

    /** The item, then its opening stock as an "opening" movement in the stock ledger. */
    protected function handleRecordCreation(array $data): Model
    {
        $opening = (int) ($this->data['opening_stock'] ?? 0);

        return DB::transaction(function () use ($data, $opening): Product {
            $product = Product::query()->create([
                ...$data,
                'sku' => StockItemResource::skuFor((string) $data['name']),
                'price' => $data['sale_price'] ?? 0,
                'is_active' => true,
                'status' => Product::STATUS_AVAILABLE,
            ]);

            if ($opening > 0) {
                $product->setStockFromProductForm($opening, reason: 'Opening stock', note: __('Opening stock entered in the Trade Portal'));
            }

            return $product->refresh();
        });
    }

    protected function getRedirectUrl(): string
    {
        return StockItemResource::getUrl('index');
    }
}
