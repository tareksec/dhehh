<?php

namespace App\Filament\Resources\AgreementTemplates\Pages;

use App\Filament\Resources\AgreementTemplates\AgreementTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAgreementTemplates extends ListRecords
{
    protected static string $resource = AgreementTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
