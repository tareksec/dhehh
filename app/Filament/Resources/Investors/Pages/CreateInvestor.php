<?php

namespace App\Filament\Resources\Investors\Pages;

use App\Filament\Resources\Investors\InvestorResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateInvestor extends CreateRecord
{
    protected static string $resource = InvestorResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        [$data, $files] = InvestorResource::splitPaperCopies($data);

        return DB::transaction(function () use ($data, $files): Model {
            $investor = parent::handleRecordCreation($data);
            InvestorResource::attachPaperCopies($investor, $files);

            return $investor;
        });
    }
}
