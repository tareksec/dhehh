<?php

namespace App\Filament\Trade\Resources\GoodsIn\Pages;

use App\Filament\Trade\Resources\GoodsIn\GoodsInResource;
use App\Models\Purchase;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateGoodsIn extends CreateRecord
{
    protected static string $resource = GoodsInResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * The purchase as a draft with its items, then received when the goods
     * are already here — the ERP's purchase rules move the stock either way.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Purchase {
            $purchase = Purchase::query()->create([
                'supplier_id' => $data['supplier_id'],
                'purchase_date' => $data['purchase_date'],
                'lc_number' => filled($data['lc_number'] ?? null) ? $data['lc_number'] : null,
                'note' => $data['note'] ?? null,
                'status' => 'draft',
                'paid_amount' => 0,
            ]);

            foreach ($data['items'] ?? [] as $item) {
                $purchase->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => (int) $item['quantity'],
                    'unit_cost' => (float) $item['unit_cost'],
                ]);
            }

            if (! empty($data['arrived'])) {
                $purchase->update(['status' => 'received']);
            }

            return $purchase->refresh();
        });
    }

    protected function getRedirectUrl(): string
    {
        return GoodsInResource::getUrl('index');
    }
}
