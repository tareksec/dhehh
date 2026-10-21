<?php

namespace App\Filament\Resources\ResellerLevels\Pages;

use App\Filament\Resources\ResellerLevels\ResellerLevelResource;
use App\Services\CompanyContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Validation\ValidationException;

class ManageResellerLevels extends ManageRecords
{
    protected static string $resource = ResellerLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New level'))
                ->visible(fn (): bool => ResellerLevelResource::canCreate())
                ->mutateDataUsing(function (array $data): array {
                    $companyId = app(CompanyContext::class)->id();

                    // A level belongs to one company: with "all companies" chosen there is none to put it in.
                    if (! $companyId) {
                        throw ValidationException::withMessages(['name' => __('Choose a company first: reseller levels belong to one company.')]);
                    }

                    return $data + ['company_id' => $companyId];
                }),
        ];
    }
}
