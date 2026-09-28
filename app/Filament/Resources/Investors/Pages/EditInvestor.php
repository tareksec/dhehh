<?php

namespace App\Filament\Resources\Investors\Pages;

use App\Filament\Resources\Investors\InvestorResource;
use App\Models\Investor;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditInvestor extends EditRecord
{
    protected static string $resource = InvestorResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /** @param  Investor  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        [$data, $files] = InvestorResource::splitPaperCopies($data);

        return DB::transaction(function () use ($record, $data, $files): Model {
            $investor = parent::handleRecordUpdate($record, $data);
            InvestorResource::attachPaperCopies($investor, $files);

            return $investor;
        });
    }

    protected function afterSave(): void
    {
        // The uploads are now documents; clear the fields so saving again does not add them twice.
        foreach (array_keys(InvestorResource::PAPER_COPY_UPLOADS) as $field) {
            $this->data[$field] = [];
        }
    }
}
