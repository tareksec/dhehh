<?php

namespace App\Filament\Resources\CompanyFaqs\Pages;

use App\Filament\Resources\CompanyFaqs\CompanyFaqResource;
use App\Filament\Resources\Pages\TrashedRecords;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanyFaqs extends ListRecords
{
    protected static string $resource = CompanyFaqResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TrashedRecords::linkAction(CompanyFaqResource::class),
            CreateAction::make(),
        ];
    }
}
