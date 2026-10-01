<?php

namespace App\Filament\Pages;

use App\Models\DocumentationArticle;
use App\Models\DocumentationCategory;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;

/**
 * The app's step-by-step guide hub, open to every signed-in user. Content is
 * authored by admins in Settings → Documentation; this page only browses it.
 */
class Documentation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = -2;

    protected static ?string $title = null;

    public static function getNavigationLabel(): string
    {
        return __('Documentation');
    }

    public function getTitle(): string
    {
        return __('Documentation');
    }

    protected string $view = 'filament.pages.documentation';

    public ?int $activeArticleId = null;

    public string $search = '';

    public static function canAccess(): bool
    {
        return SchemaFacade::hasTable('documentation_articles') && Auth::check();
    }

    public function mount(): void
    {
        $this->activeArticleId = $this->categories()
            ->flatMap(fn (DocumentationCategory $category): iterable => $category->articles)
            ->first()
            ?->getKey();
    }

    /**
     * @return Collection<int, DocumentationCategory>
     */
    public function categories(): Collection
    {
        return DocumentationCategory::query()
            ->with(['articles' => function ($query): void {
                $query->where('is_published', true)
                    ->when($this->search !== '', fn ($query) => $query->where('title', 'like', '%'.$this->search.'%'))
                    ->orderBy('sort_order');
            }])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (DocumentationCategory $category): bool => $category->articles->isNotEmpty())
            ->values();
    }

    public function activeArticle(): ?DocumentationArticle
    {
        if ($this->activeArticleId === null) {
            return null;
        }

        return DocumentationArticle::query()
            ->where('is_published', true)
            ->find($this->activeArticleId);
    }

    public function selectArticle(int $articleId): void
    {
        $this->activeArticleId = $articleId;
    }

    public function updatedSearch(): void
    {
        $firstMatch = $this->categories()
            ->flatMap(fn (DocumentationCategory $category): iterable => $category->articles)
            ->first();

        $this->activeArticleId = $firstMatch?->getKey();
    }
}
