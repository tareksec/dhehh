<?php

namespace App\Filament\Resources\StockPools\Pages;

use App\Filament\Resources\StockPools\StockPoolResource;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStockPool extends EditRecord
{
    protected static string $resource = StockPoolResource::class;

    /** @var list<int> */
    protected array $memberProductIds = [];

    protected function getHeaderActions(): array
    {
        return [
            // Moving the pool to Trash takes its products out of the pool (StockPool::booted()).
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['member_product_ids'] = Product::query()
            ->withoutGlobalScopes()
            ->where('stock_pool_id', $this->record->getKey())
            ->where('id', '!=', $this->record->source_product_id)
            ->pluck('id')
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->memberProductIds = array_map('intval', $data['member_product_ids'] ?? []);
        unset($data['member_product_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        StockPoolResource::syncMembers($this->record, $this->memberProductIds);
    }
}
