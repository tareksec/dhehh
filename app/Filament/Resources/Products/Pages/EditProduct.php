<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Concerns\PersistsProductFormData;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Services\ProductTrashService;
use App\Support\TypeToConfirmDelete;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use HasStickyHeaderFormActions;
    use PersistsProductFormData;

    protected static string $resource = ProductResource::class;

    protected int $requestedStock = 0;

    protected function getHeaderActions(): array
    {
        return [
            $this->getStickySaveFormAction(),
            DeleteAction::make()
                ->label('Move to trash')
                ->schema(fn (Action $action): array => [
                    ...TypeToConfirmDelete::nameFields($action),
                    ...ProductsTable::trashReasonFields(),
                ])
                ->using(function (Product $record, array $data, DeleteAction $action): bool {
                    $why = app(ProductTrashService::class)->tryMoveToTrash($record, $data['trash_reason'] ?? null, $data['trash_note'] ?? null);

                    if ($why !== null) {
                        Notification::make()->title(__('Not moved to trash'))->body($why)->danger()->persistent()->send();
                        $action->halt();
                    }

                    return true;
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return self::mutateProductDataBeforeFill($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = self::mutateProductDataBeforeSave($data);
        $this->requestedStock = (int) ($data['stock'] ?? $this->record->stock);
        unset($data['stock']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->setStockFromProductForm($this->requestedStock);
    }
}
