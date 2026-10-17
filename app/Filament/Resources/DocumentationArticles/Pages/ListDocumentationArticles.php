<?php

namespace App\Filament\Resources\DocumentationArticles\Pages;

use App\Filament\Resources\DocumentationArticles\DocumentationArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentationArticles extends ListRecords
{
    protected static string $resource = DocumentationArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
