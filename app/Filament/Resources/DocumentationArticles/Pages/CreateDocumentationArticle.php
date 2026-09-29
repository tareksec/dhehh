<?php

namespace App\Filament\Resources\DocumentationArticles\Pages;

use App\Filament\Resources\DocumentationArticles\DocumentationArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentationArticle extends CreateRecord
{
    protected static string $resource = DocumentationArticleResource::class;
}
