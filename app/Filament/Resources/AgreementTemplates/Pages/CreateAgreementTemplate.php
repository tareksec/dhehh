<?php

namespace App\Filament\Resources\AgreementTemplates\Pages;

use App\Filament\Resources\AgreementTemplates\AgreementTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAgreementTemplate extends CreateRecord
{
    protected static string $resource = AgreementTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'created_by' => auth()->id()];
    }
}
