<?php

namespace App\Http\Controllers;

use App\Models\DocumentationArticle;
use App\Models\DocumentationCategory;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DocumentationGuideController extends Controller
{
    public function index(): View
    {
        return view('guides.index', [
            'categories' => $this->publishedCategories(),
        ]);
    }

    public function show(string $category, string $article): View
    {
        $categories = $this->publishedCategories();

        $documentationArticle = DocumentationArticle::query()
            ->where('is_published', true)
            ->where('is_public', true)
            ->where('slug', $article)
            ->whereHas('category', fn ($query) => $query->where('slug', $category))
            ->with('category')
            ->firstOrFail();

        return view('guides.show', [
            'categories' => $categories,
            'article' => $documentationArticle,
        ]);
    }

    /**
     * @return Collection<int, DocumentationCategory>
     */
    protected function publishedCategories(): Collection
    {
        return DocumentationCategory::query()
            ->with(['articles' => fn ($query) => $query->where('is_published', true)->where('is_public', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (DocumentationCategory $category) => $category->articles->isNotEmpty())
            ->values();
    }
}
