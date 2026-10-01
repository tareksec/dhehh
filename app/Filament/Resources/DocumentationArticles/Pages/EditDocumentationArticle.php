<?php

namespace App\Filament\Resources\DocumentationArticles\Pages;

use App\Filament\Resources\DocumentationArticles\DocumentationArticleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDocumentationArticle extends EditRecord
{
    protected static string $resource = DocumentationArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
