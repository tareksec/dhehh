<?php

namespace App\Filament\Resources\AgreementTemplates\Pages;

use App\Filament\Resources\AgreementTemplates\AgreementTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAgreementTemplate extends EditRecord
{
    protected static string $resource = AgreementTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
