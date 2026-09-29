<?php

namespace Tests\Feature;

use App\Models\DocumentationArticle;
use App\Models\DocumentationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicGuidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guides_index_lists_published_public_articles_without_requiring_login(): void
    {
        $category = DocumentationCategory::query()->create(['name' => 'Orders', 'slug' => 'orders', 'sort_order' => 1]);

        DocumentationArticle::query()->create([
            'documentation_category_id' => $category->getKey(),
            'title' => 'How to create an order',
            'slug' => 'how-to-create-an-order',
            'content' => '<p>Step one...</p>',
            'is_published' => true,
            'is_public' => true,
        ]);

        $this->get(route('guides.index'))
            ->assertOk()
            ->assertSee('How to create an order');
    }

    public function test_an_unpublished_article_is_not_listed_and_returns_404_directly(): void
    {
        $category = DocumentationCategory::query()->create(['name' => 'Orders', 'slug' => 'orders', 'sort_order' => 1]);

        DocumentationArticle::query()->create([
            'documentation_category_id' => $category->getKey(),
            'title' => 'Draft guide',
            'slug' => 'draft-guide',
            'content' => '<p>Not ready yet.</p>',
            'is_published' => false,
            'is_public' => true,
        ]);

        $this->get(route('guides.index'))->assertDontSee('Draft guide');
        $this->get(route('guides.show', ['orders', 'draft-guide']))->assertNotFound();
    }

    public function test_a_published_article_not_marked_public_is_not_listed_and_returns_404_directly(): void
    {
        $category = DocumentationCategory::query()->create(['name' => 'Finance', 'slug' => 'finance', 'sort_order' => 1]);

        DocumentationArticle::query()->create([
            'documentation_category_id' => $category->getKey(),
            'title' => 'Approve a voucher',
            'slug' => 'approve-a-voucher',
            'content' => '<p>Staff-only content.</p>',
            'is_published' => true,
            'is_public' => false,
        ]);

        $this->get(route('guides.index'))->assertDontSee('Approve a voucher');
        $this->get(route('guides.show', ['finance', 'approve-a-voucher']))->assertNotFound();
    }

    public function test_a_published_and_public_articles_show_page_renders_its_content_without_requiring_login(): void
    {
        $category = DocumentationCategory::query()->create(['name' => 'Inventory', 'slug' => 'inventory', 'sort_order' => 1]);

        DocumentationArticle::query()->create([
            'documentation_category_id' => $category->getKey(),
            'title' => 'Adjust stock levels',
            'slug' => 'adjust-stock-levels',
            'content' => '<p>Go to the product page and edit the stock field.</p>',
            'is_published' => true,
            'is_public' => true,
        ]);

        $this->get(route('guides.show', ['inventory', 'adjust-stock-levels']))
            ->assertOk()
            ->assertSee('Adjust stock levels')
            ->assertSee('Go to the product page and edit the stock field.', escape: false);
    }
}
