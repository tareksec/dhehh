<?php

namespace Tests\Feature;

use App\Filament\Pages\Documentation;
use App\Filament\Resources\DocumentationArticles\DocumentationArticleResource;
use App\Models\DocumentationArticle;
use App\Models\DocumentationCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_documentation_page_shows_only_published_articles(): void
    {
        $user = User::factory()->create(['role' => 'manager', 'is_active' => true]);

        $category = DocumentationCategory::query()->create(['name' => 'Orders', 'sort_order' => 1]);

        $published = DocumentationArticle::query()->create([
            'documentation_category_id' => $category->getKey(),
            'title' => 'How to create an order',
            'content' => '<p>Step one...</p>',
            'is_published' => true,
        ]);

        DocumentationArticle::query()->create([
            'documentation_category_id' => $category->getKey(),
            'title' => 'Draft guide',
            'content' => '<p>Not ready yet.</p>',
            'is_published' => false,
        ]);

        $this->actingAs($user);

        Livewire::test(Documentation::class)
            ->assertSet('activeArticleId', $published->getKey())
            ->assertSee('How to create an order')
            ->assertDontSee('Draft guide');
    }

    public function test_searching_filters_articles_by_title(): void
    {
        $user = User::factory()->create(['role' => 'manager', 'is_active' => true]);

        $category = DocumentationCategory::query()->create(['name' => 'Inventory', 'sort_order' => 1]);

        $stockArticle = DocumentationArticle::query()->create([
            'documentation_category_id' => $category->getKey(),
            'title' => 'Adjust stock levels',
            'content' => '<p>...</p>',
            'is_published' => true,
        ]);

        DocumentationArticle::query()->create([
            'documentation_category_id' => $category->getKey(),
            'title' => 'Create a purchase order',
            'content' => '<p>...</p>',
            'is_published' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(Documentation::class)
            ->set('search', 'stock')
            ->assertSee('Adjust stock levels')
            ->assertDontSee('Create a purchase order')
            ->assertSet('activeArticleId', $stockArticle->getKey());
    }

    public function test_a_search_with_no_match_clears_the_selected_article(): void
    {
        $user = User::factory()->create(['role' => 'manager', 'is_active' => true]);

        $category = DocumentationCategory::query()->create(['name' => 'Inventory', 'sort_order' => 1]);

        DocumentationArticle::query()->create([
            'documentation_category_id' => $category->getKey(),
            'title' => 'Adjust stock levels',
            'content' => '<p>...</p>',
            'is_published' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(Documentation::class)
            ->assertSet('activeArticleId', fn (?int $id): bool => $id !== null)
            ->set('search', 'no such guide exists')
            ->assertSet('activeArticleId', null)
            ->assertDontSee('Adjust stock levels');
    }

    public function test_a_manager_without_settings_permission_cannot_manage_documentation(): void
    {
        $user = User::factory()->create(['role' => 'manager', 'is_active' => true]);
        $this->actingAs($user);

        $this->assertFalse(DocumentationArticleResource::canViewAny());
    }

    public function test_a_super_admin_can_manage_documentation(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user);

        $this->assertTrue(DocumentationArticleResource::canViewAny());
    }
}
