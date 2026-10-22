<?php

namespace App\Filament\Trade\Resources\DeliveryChallans\Pages;

use App\Filament\Trade\Resources\DeliveryChallans\DeliveryChallanResource;
use App\Services\DeliveryChallanService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDeliveryChallan extends CreateRecord
{
    protected static string $resource = DeliveryChallanResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('New Delivery Challan');
    }

    /** Writing the challan is the sale: stock, due and payments in one go. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(DeliveryChallanService::class)->create($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return DeliveryChallanResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Challan saved. The goods are out of stock and the due is on the buyer.');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Save challan'));
    }
}
