<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Pages\Reports;
use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Filament\Resources\SupplierOrders\SupplierOrderResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\PurchaseReceipt;
use App\Models\SupplierOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        $user = fn () => auth()->user();

        return [
            Action::make('supplierAccount')
                ->label(__('Supplier account'))
                ->icon('heroicon-o-scale')
                ->color('gray')
                ->visible(fn (): bool => (bool) $user()?->canAccessReports())
                ->url(fn (): string => Reports::getUrl([
                    'report_type' => 'supplier-account',
                    'supplier_id' => $this->getRecord()->getKey(),
                    'date_to' => now()->toDateString(),
                ])),
            Action::make('newReceipt')
                ->label(__('New goods receipt'))
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => (bool) $user()?->canPerformModelAbility('create', PurchaseReceipt::class))
                ->url(fn (): string => PurchaseReceiptResource::getUrl('create', ['supplier' => $this->getRecord()->getKey()])),
            Action::make('newSupplierOrder')
                ->label(__('New supplier order'))
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->visible(fn (): bool => (bool) $user()?->canPerformModelAbility('create', SupplierOrder::class))
                ->url(fn (): string => SupplierOrderResource::getUrl('create', ['supplier' => $this->getRecord()->getKey()])),
            EditAction::make(),
        ];
    }
}
