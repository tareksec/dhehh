<?php

namespace App\Filament\Resources\PurchaseReceipts\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Concerns\ShowsModelValidationErrors;
use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreatePurchaseReceipt extends CreateRecord
{
    use HasStickyHeaderFormActions;
    use ShowsModelValidationErrors;

    protected static string $resource = PurchaseReceiptResource::class;

    // Receipt, lines and stock movements are saved together or not at all.
    protected ?bool $hasDatabaseTransactions = true;

    public function create(bool $another = false): void
    {
        try {
            parent::create($another);
        } catch (ValidationException $exception) {
            if (! $this->reportModelValidation($exception)) {
                throw $exception;
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getStickySaveFormAction(),
        ];
    }
}
