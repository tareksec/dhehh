<?php

namespace App\Filament\Resources\SupplierOrders\Pages;

use App\Filament\Concerns\HasStickyHeaderFormActions;
use App\Filament\Concerns\ShowsModelValidationErrors;
use App\Filament\Resources\SupplierOrders\SupplierOrderResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateSupplierOrder extends CreateRecord
{
    use HasStickyHeaderFormActions;
    use ShowsModelValidationErrors;

    protected static string $resource = SupplierOrderResource::class;

    // Order, lines and stock movements are saved together or not at all.
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
