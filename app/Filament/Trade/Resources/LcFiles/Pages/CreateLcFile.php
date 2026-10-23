<?php

namespace App\Filament\Trade\Resources\LcFiles\Pages;

use App\Filament\Trade\Resources\LcFiles\LcFileResource;
use App\Filament\Trade\TradeErrors;
use App\Services\LcFileService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLcFile extends CreateRecord
{
    protected static string $resource = LcFileResource::class;

    protected static bool $canCreateAnother = false;

    /** The form is written to the purchase, its supplier and the new LC file (LcFileService). */
    protected function handleRecordCreation(array $data): Model
    {
        $file = null;

        if (! TradeErrors::attempt(function () use ($data, &$file): void {
            $file = app(LcFileService::class)->save(null, $data);
        })) {
            $this->halt();
        }

        return $file;
    }

    protected function getRedirectUrl(): string
    {
        return LcFileResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
