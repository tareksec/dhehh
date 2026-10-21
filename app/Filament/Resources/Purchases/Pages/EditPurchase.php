<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Purchases\Support\PurchaseDocumentActions;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPurchase extends EditRecord
{
    use HasStickyHeaderFormActions;

    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PurchaseDocumentActions::make('pi'),
            PurchaseDocumentActions::make('ci'),
            PurchaseDocumentActions::make('pl'),
            ViewAction::make(),
            $this->getStickySaveFormAction(),
        ];
    }
}
