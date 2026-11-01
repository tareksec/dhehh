<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\TrashedCategories;
use App\Filament\Resources\CompanyFaqs\CompanyFaqResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\Offers\Pages\TrashedOffers;
use App\Filament\Resources\ProductCarousels\ProductCarouselResource;
use App\Filament\Resources\ProductReviews\Pages\TrashedProductReviews;
use App\Filament\Resources\ProductReviews\ProductReviewResource;
use App\Filament\Resources\QuickReplies\QuickReplyResource;
use App\Filament\Resources\StorefrontPages\StorefrontPageResource;
use App\Filament\Resources\StorefrontPaymentMethods\Pages\CreateStorefrontPaymentMethod;
use App\Filament\Resources\StorefrontPaymentMethods\StorefrontPaymentMethodResource;
use App\Filament\Resources\StorefrontSlides\StorefrontSlideResource;
use App\Models\Category;
use App\Models\CompanyFaq;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductCarousel;
use App\Models\ProductReview;
use App\Models\QuickReply;
use App\Models\StorefrontPage;
use App\Models\StorefrontPaymentMethod;
use App\Models\StorefrontSetting;
use App\Models\StorefrontSlide;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\ProductCsvService;
use App\Services\StorefrontCheckoutService;
use App\Support\TypeToConfirmDelete;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Owner request 2026-11-01, phase T3 of docs/delete-and-trash-plan.md: the
 * catalog and storefront content (categories, offers / collections, carousels,
 * reviews, hero slides, custom pages, payment methods, FAQs, quick replies) goes
 * to Trash when deleted and can be restored or deleted permanently.
 */
class CatalogTrashTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $company = $this->admin->defaultCompany();
        app(CompanyContext::class)->set($company);
        $this->session(['current_company_id' => $company->getKey()]);
    }

    /** @return array<string, array{0: class-string, 1: class-string, 2: string}> */
    public static function trashedResources(): array
    {
        return [
            'categories' => [CategoryResource::class, Category::class, 'categories'],
            'offers' => [OfferResource::class, Offer::class, 'offers'],
            'carousels' => [ProductCarouselResource::class, ProductCarousel::class, 'product_carousels'],
            'reviews' => [ProductReviewResource::class, ProductReview::class, 'product_reviews'],
            'slides' => [StorefrontSlideResource::class, StorefrontSlide::class, 'storefront_slides'],
            'pages' => [StorefrontPageResource::class, StorefrontPage::class, 'storefront_pages'],
            'payment methods' => [StorefrontPaymentMethodResource::class, StorefrontPaymentMethod::class, 'storefront_payment_methods'],
            'faqs' => [CompanyFaqResource::class, CompanyFaq::class, 'company_faqs'],
            'quick replies' => [QuickReplyResource::class, QuickReply::class, 'company_quick_replies'],
        ];
    }

    #[DataProvider('trashedResources')]
    public function test_every_catalog_and_storefront_model_has_a_trash(string $resource, string $model, string $table): void
    {
        $this->actingAs($this->admin);

        $this->assertTrue(Schema::hasColumn($table, 'deleted_at'));
        $this->assertTrue(TypeToConfirmDelete::usesSoftDeletes($model));
        $this->assertTrue($resource::hasPage('trash'));
        $this->assertTrue($resource::canRestoreAny());
        $this->assertTrue($resource::canForceDeleteAny());
    }

    /** @return array<string, array{0: class-string}> */
    public static function resourcesOnlyManagersMayDeleteFrom(): array
    {
        return [
            'offers' => [OfferResource::class],
            'carousels' => [ProductCarouselResource::class],
            'reviews' => [ProductReviewResource::class],
            'slides' => [StorefrontSlideResource::class],
            'pages' => [StorefrontPageResource::class],
            'payment methods' => [StorefrontPaymentMethodResource::class],
        ];
    }

    #[DataProvider('resourcesOnlyManagersMayDeleteFrom')]
    public function test_someone_who_may_not_delete_cannot_use_the_trash(string $resource): void
    {
        $staff = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        $this->actingAs($staff);

        $this->assertFalse($resource::canDeleteAny());
        $this->assertFalse($resource::canRestoreAny());
        $this->assertFalse($resource::canForceDeleteAny());
    }

    public function test_a_category_goes_to_trash_after_its_name_is_typed_and_its_products_keep_the_link(): void
    {
        $category = Category::query()->create(['name' => 'Phones', 'slug' => 'phones', 'is_active' => true]);
        $product = $this->product('Handset', 'PH-1', $category);

        Livewire::actingAs($this->admin)
            ->test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callAction('delete', data: ['confirmation' => 'Tablets'])
            ->assertHasActionErrors(['confirmation']);

        $this->assertNotSoftDeleted($category);

        Livewire::actingAs($this->admin)
            ->test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callAction('delete', data: ['confirmation' => 'Phones'])
            ->assertHasNoActionErrors();

        $this->assertSoftDeleted($category);
        $this->assertNull(Category::query()->find($category->getKey()));
        // The product is not touched: it shows as uncategorised while the category is in Trash...
        $this->assertSame($category->getKey(), $product->fresh()->category_id);
        $this->assertNull($product->fresh()->category);

        Livewire::actingAs($this->admin)
            ->test(TrashedCategories::class)
            ->assertCanSeeTableRecords([$category])
            ->callTableAction('restore', $category)
            ->assertHasNoTableActionErrors();

        // ...and has its category again after a restore.
        $this->assertNotSoftDeleted($category);
        $this->assertSame('Phones', $product->fresh()->category->name);
    }

    public function test_a_category_in_trash_keeps_its_slug_and_is_deleted_permanently_only_by_name(): void
    {
        $category = Category::query()->create(['name' => 'Phones', 'slug' => 'phones', 'is_active' => true]);
        $category->delete();

        // The form says why, instead of failing with a database error.
        Livewire::actingAs($this->admin)
            ->test(CreateCategory::class)
            ->fillForm(['name' => 'Phones again', 'slug' => 'phones', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::actingAs($this->admin)
            ->test(TrashedCategories::class)
            ->callTableAction('forceDelete', $category, data: ['confirmation' => 'wrong'])
            ->assertHasTableActionErrors(['confirmation']);

        $this->assertSoftDeleted($category);

        Livewire::actingAs($this->admin)
            ->test(TrashedCategories::class)
            ->callTableAction('forceDelete', $category, data: ['confirmation' => 'Phones'])
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($category);

        // Once it is gone for good the slug is free again.
        Category::query()->create(['name' => 'Phones', 'slug' => 'phones', 'is_active' => true]);
        $this->assertSame(1, Category::query()->where('slug', 'phones')->count());
    }

    public function test_a_csv_import_never_collides_with_the_slug_of_a_category_in_trash(): void
    {
        Category::query()->create(['name' => 'Phones', 'slug' => 'phones', 'is_active' => true])->delete();

        $method = new ReflectionMethod(ProductCsvService::class, 'categoryFor');
        $imported = $method->invoke(app(ProductCsvService::class), 'Phones');

        $this->assertSame('phones-2', $imported->slug);
        $this->assertSame(1, Category::onlyTrashed()->count(), 'the category in Trash is not brought back by an import');
        $this->assertNotNull($imported->getKey());
    }

    public function test_a_collection_in_trash_stops_applying_and_comes_back_on_restore(): void
    {
        $collection = Offer::query()->create([
            'type' => Offer::TYPE_COLLECTION,
            'collection_kind' => Offer::KIND_FLASH_SALE,
            'title' => 'Flash Sale',
            'slug' => 'flash-sale',
            'status' => Offer::STATUS_PUBLISHED,
        ]);

        $this->assertSame(1, Offer::query()->liveCollections()->count());

        $collection->delete();

        $this->assertSame(0, Offer::query()->liveCollections()->count());
        // Its slug is still held: a new offer with the same slug would collide on restore.
        $this->expectException(QueryException::class);
        Offer::query()->create(['type' => Offer::TYPE_SINGLE, 'title' => 'Another', 'slug' => 'flash-sale']);
    }

    public function test_a_restored_collection_is_live_again_and_listed_in_the_offers_trash(): void
    {
        $collection = Offer::query()->create([
            'type' => Offer::TYPE_COLLECTION,
            'collection_kind' => Offer::KIND_TRENDING,
            'title' => 'Trending',
            'slug' => 'trending',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        $collection->delete();

        Livewire::actingAs($this->admin)
            ->test(TrashedOffers::class)
            ->assertCanSeeTableRecords([$collection])
            ->callTableAction('restore', $collection)
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, Offer::query()->liveCollections()->count());
    }

    public function test_a_hero_slide_in_trash_leaves_the_storefront_at_once_and_returns_on_restore(): void
    {
        $companyId = $this->admin->defaultCompany()->getKey();
        $slide = StorefrontSlide::query()->create(['image' => 'slides/big-sale.jpg', 'heading' => 'Big sale', 'is_active' => true, 'sort_order' => 1]);

        // The list is cached; saving, deleting and restoring each clear it.
        $this->assertCount(1, StorefrontSlide::forCompany($companyId));

        $slide->delete();
        $this->assertCount(0, StorefrontSlide::forCompany($companyId));

        $slide->restore();
        $this->assertCount(1, StorefrontSlide::forCompany($companyId));
    }

    public function test_a_payment_method_in_trash_is_not_offered_but_old_orders_still_show_its_name(): void
    {
        $method = StorefrontPaymentMethod::query()->create([
            'type' => StorefrontPaymentMethod::TYPE_MANUAL,
            'name' => 'bKash',
            'account_number' => '01800000001',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $checkout = app(StorefrontCheckoutService::class);

        $this->assertCount(1, $checkout->activePaymentMethods(new StorefrontSetting));

        $method->delete();

        $this->assertCount(0, $checkout->activePaymentMethods(new StorefrontSetting));
        $this->assertStringContainsString('bKash', StorefrontCheckoutService::paymentMethodLabel('manual:'.$method->getKey()));
    }

    public function test_a_second_cash_on_delivery_cannot_be_added_while_the_first_is_in_trash(): void
    {
        $cod = StorefrontPaymentMethod::query()->create([
            'type' => StorefrontPaymentMethod::TYPE_COD,
            'name' => 'Cash on Delivery',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $cod->delete();

        Livewire::actingAs($this->admin)
            ->test(CreateStorefrontPaymentMethod::class)
            ->fillForm(['type' => StorefrontPaymentMethod::TYPE_COD, 'name' => 'Cash 2', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['type']);

        $this->assertSame(0, StorefrontPaymentMethod::query()->count());
    }

    public function test_a_review_in_trash_is_hidden_and_the_prompt_names_the_customer_and_product(): void
    {
        $product = $this->product('Handset', 'PH-2');
        $review = ProductReview::query()->create([
            'product_id' => $product->getKey(),
            'customer_name' => 'Rahim',
            'rating' => 5,
            'comment' => 'Good',
            'status' => ProductReview::STATUS_APPROVED,
        ]);

        $this->assertSame('Rahim — Handset', ProductReviewResource::getRecordTitle($review));

        $review->delete();

        $this->assertSame(0, ProductReview::query()->approved()->count());

        Livewire::actingAs($this->admin)
            ->test(TrashedProductReviews::class)
            ->assertCanSeeTableRecords([$review])
            ->callTableAction('restore', $review)
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, ProductReview::query()->approved()->count());
    }

    private function product(string $name, string $sku, ?Category $category = null): Product
    {
        return Product::query()->create([
            'name' => $name,
            'sku' => $sku,
            'slug' => strtolower($sku),
            'price' => 100,
            'sale_price' => 100,
            'category_id' => $category?->getKey(),
        ]);
    }
}
