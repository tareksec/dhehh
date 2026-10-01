<?php

namespace App\Filament\Resources\Vouchers\Pages;

use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\Voucher;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVoucher extends ViewRecord
{
    protected static string $resource = VoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            VoucherResource::moveToTrashAction()->after(function (Voucher $record): void {
                if ($record->trashed()) {
                    $this->redirect(VoucherResource::getUrl('index'));
                }
            }),
        ];
    }
}
