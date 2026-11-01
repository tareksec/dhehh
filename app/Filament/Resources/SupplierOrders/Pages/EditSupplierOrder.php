<?php

namespace App\Filament\Resources\SupplierOrders\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Concerns\ShowsModelValidationErrors;
use App\Filament\Resources\SupplierOrders\SupplierOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSupplierOrder extends EditRecord
{
    use HasStickyHeaderFormActions;
    use ShowsModelValidationErrors;

    protected static string $resource = SupplierOrderResource::class;

    // Order, lines and stock movements are saved together or not at all.
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
                ->using(fn (Model $record): bool => SupplierOrderResource::deleteSafely($record)),
            $this->getStickySaveFormAction(),
        ];
    }
}
