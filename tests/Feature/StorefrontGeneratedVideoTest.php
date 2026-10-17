<?php

namespace Tests\Feature;

use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Company;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md §11 — an attached video plays on
 * the storefront product page and in the offer hero, and an admin can take
 * it off again from the product / offer form.
 */
class StorefrontGeneratedVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_product_page_plays_an_attached_video(): void
    {
        $company = $this->createStore('video-product.example.test');
        $product = $this->createProduct('Video Kettle', [
            'video_path' => 'companies/'.$company->storage_key.'/public/ai-generated-videos/kettle.mp4',
        ]);

        $this->get('http://video-product.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertSee('data-product-video', false)
            ->assertSee('ai-generated-videos/kettle.mp4', false);
    }

    public function test_product_page_has_no_video_block_without_a_video(): void
    {
        $this->createStore('no-video-product.example.test');
        $product = $this->createProduct('Plain Kettle');

        $this->get('http://no-video-product.example.test/product/'.$product->slug)
            ->assertOk()
            ->assertDontSee('data-product-video', false);
    }

    public function test_offer_hero_plays_the_video_with_the_cover_image_as_poster(): void
    {
        $company = $this->createStore('video-offer.example.test');
        $offer = $this->createOffer($company, 'Hero Video Offer', [
            'cover_image' => 'companies/'.$company->storage_key.'/public/offers/cover.webp',
            'video_path' => 'companies/'.$company->storage_key.'/public/ai-generated-videos/hero.mp4',
        ]);

        $this->get('http://video-offer.example.test/offers/'.$offer->slug)
            ->assertOk()
            ->assertSee('data-offer-hero-video', false)
            ->assertSee('ai-generated-videos/hero.mp4', false)
            ->assertSee('offers/cover.webp', false);
    }

    public function test_offer_hero_falls_back_to_the_cover_image_without_a_video(): void
    {
        $company = $this->createStore('image-offer.example.test');
        $offer = $this->createOffer($company, 'Cover Only Offer', [
            'cover_image' => 'companies/'.$company->storage_key.'/public/offers/cover.webp',
        ]);

        $this->get('http://image-offer.example.test/offers/'.$offer->slug)
            ->assertOk()
            ->assertDontSee('data-offer-hero-video', false)
            ->assertSee('offers/cover.webp', false);
    }

    public function test_admin_can_remove_the_video_from_a_product(): void
    {
        $company = $this->createStore('remove-product-video.example.test');
        $product = $this->createProduct('Removable Kettle', [
            'video_path' => 'companies/'.$company->storage_key.'/public/ai-generated-videos/kettle.mp4',
        ]);
        $this->actingAs($this->superAdmin($company));

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertFormFieldVisible('video_preview')
            ->callAction(TestAction::make('removeVideo')->schemaComponent('video_preview'))
            ->assertNotified('Video removed');

        $this->assertNull($product->fresh()->video_path);
    }

    public function test_video_preview_is_hidden_on_a_product_without_a_video(): void
    {
        $company = $this->createStore('no-preview.example.test');
        $product = $this->createProduct('No Preview Kettle');
        $this->actingAs($this->superAdmin($company));

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->assertFormFieldHidden('video_preview');
    }

    public function test_admin_can_remove_the_hero_video_from_an_offer(): void
    {
        $company = $this->createStore('remove-offer-video.example.test');
        $offer = $this->createOffer($company, 'Removable Hero', [
            'video_path' => 'companies/'.$company->storage_key.'/public/ai-generated-videos/hero.mp4',
        ]);
        $this->actingAs($this->superAdmin($company));

        Livewire::test(EditOffer::class, ['record' => $offer->getKey()])
            ->assertFormFieldVisible('video_preview')
            ->callAction(TestAction::make('removeVideo')->schemaComponent('video_preview'))
            ->assertNotified('Video removed');

        $this->assertNull($offer->fresh()->video_path);
    }

    private function superAdmin(Company $company): User
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $user->companies()->syncWithoutDetaching([$company->getKey() => ['role' => 'super_admin', 'is_default' => true]]);

        return $user;
    }

    private function createOffer(Company $company, string $title, array $attributes = []): Offer
    {
        $product = $this->createProduct($title.' Item');

        $offer = Offer::query()->create([
            'company_id' => $company->getKey(),
            'type' => Offer::TYPE_SINGLE,
            'title' => $title,
            'status' => Offer::STATUS_PUBLISHED,
            'price_mode' => Offer::PRICE_MODE_AUTO_SUM,
            'blocks' => [['id' => 'hero-1', 'type' => 'hero', 'data' => ['headline' => $title, 'cta_label' => 'Order now']]],
        ]);
        $offer->forceFill($attributes)->save();

        OfferItem::query()->create([
            'company_id' => $company->getKey(),
            'offer_id' => $offer->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 1,
            'sort_order' => 0,
        ]);

        return $offer->fresh();
    }

    private function createProduct(string $name, array $attributes = []): Product
    {
        $product = Product::query()->create([
            'name' => $name,
            'sku' => str($name)->slug()->upper()->toString(),
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 250,
            'stock' => 20,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
        $product->forceFill($attributes)->save();

        return $product->fresh();
    }

    private function createStore(string $domain): Company
    {
        static $counter = 0;
        $counter++;

        $company = Company::query()->create([
            'name' => 'Store '.$domain,
            'slug' => str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => 'VID'.$counter,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#0F766E',
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);

        app(CompanyContext::class)->set($company);

        return $company->fresh();
    }
}
