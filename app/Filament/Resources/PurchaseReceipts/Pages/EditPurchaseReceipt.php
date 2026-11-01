<?php

namespace App\Filament\Resources\PurchaseReceipts\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Concerns\ShowsModelValidationErrors;
use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPurchaseReceipt extends EditRecord
{
    use HasStickyHeaderFormActions;
    use ShowsModelValidationErrors;

    protected static string $resource = PurchaseReceiptResource::class;

    // Receipt, lines and stock movements are saved together or not at all.
    protected ?bool $hasDatabaseTransactions = true;

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (ValidationException $exception) {
            if (! $this->reportModelValidation($exception)) {
                throw $exception;
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->using(fn (Model $record): bool => PurchaseReceiptResource::deleteSafely($record)),
            $this->getStickySaveFormAction(),
        ];
    }
}
