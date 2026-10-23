<?php

namespace App\Filament\Trade\Resources\LcFiles\Pages;

use App\Filament\Trade\Resources\LcFiles\LcFileResource;
use App\Filament\Trade\TradeErrors;
use App\Models\LcFile;
use App\Services\LcFileService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditLcFile extends EditRecord
{
    protected static string $resource = LcFileResource::class;

    /** The form shows the LC file with its purchase's and supplier's fields. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var LcFile $file */
        $file = $this->getRecord();

        return app(LcFileService::class)->formData($file->loadMissing('purchase.supplier'));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! TradeErrors::attempt(fn () => app(LcFileService::class)->save($record, $data))) {
            $this->halt();
        }

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return LcFileResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
