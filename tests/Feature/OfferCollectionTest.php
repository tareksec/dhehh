<?php

namespace Tests\Feature;

use App\Filament\Resources\Offers\Pages\CreateOffer;
use App\Models\Company;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision 2026-10-03: Flash Sale / Trending / New Arrivals are managed
 * as a new "Collection" type in the Offers section. A collection is only a
 * curated product list for the Next.js storefront — it must never show up as
 * a landing page, be bought through the offer checkout, or be picked as an
 * offer for AI image/video generation.
 */
class OfferCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_collections_are_live_only_while_published_and_inside_their_schedule(): void
    {
        $company = $this->store('live.example.test');
        app(CompanyContext::class)->set($company);

        $live = $this->collection($company, 'Live now', Offer::KIND_FLASH_SALE, ['ends_at' => now()->addHour()]);
        $open = $this->collection($company, 'No schedule', Offer::KIND_TRENDING);
        $this->collection($company, 'Draft', Offer::KIND_TRENDING, ['status' => Offer::STATUS_DRAFT]);
        $this->collection($company, 'Archived', Offer::KIND_TRENDING, ['status' => Offer::STATUS_ARCHIVED]);
        $this->collection($company, 'Not started', Offer::KIND_FLASH_SALE, ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        $this->collection($company, 'Ended', Offer::KIND_FLASH_SALE, ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        Offer::query()->create(['company_id' => $company->getKey(), 'type' => Offer::TYPE_COMBO, 'title' => 'A combo', 'status' => Offer::STATUS_PUBLISHED]);

        $this->assertEqualsCanonicalizing(
            [$live->getKey(), $open->getKey()],
            Offer::query()->liveCollections()->pluck('id')->all(),
        );
    }

    public function test_changing_a_collection_into_a_landing_offer_clears_its_kind_and_schedule(): void
    {
        $company = $this->store('switch.example.test');
        app(CompanyContext::class)->set($company);
        $offer = $this->collection($company, 'Was a collection', Offer::KIND_FLASH_SALE, ['ends_at' => now()->addHour()]);

        $offer->update(['type' => Offer::TYPE_SINGLE]);

        $offer->refresh();
        $this->assertNull($offer->collection_kind);
        $this->assertNull($offer->starts_at);
        $this->assertNull($offer->ends_at);
    }

    public function test_a_collection_never_appears_as_a_landing_page_or_a_checkout_target(): void
    {
        $company = $this->store('hidden.example.test');
        app(CompanyContext::class)->set($company);
        $product = $this->product('Collection Item', 'COL-1');
        $collection = $this->collection($company, 'Secret Flash Sale', Offer::KIND_FLASH_SALE, ['ends_at' => now()->addHour()], [$product]);
        $landing = Offer::query()->create([
            'company_id' => $company->getKey(),
            'type' => Offer::TYPE_SINGLE,
            'title' => 'Visible Landing Offer',
            'status' => Offer::STATUS_PUBLISHED,
        ]);
        OfferItem::query()->create(['company_id' => $company->getKey(), 'offer_id' => $landing->getKey(), 'product_id' => $product->getKey(), 'quantity' => 1, 'sort_order' => 0]);

        $this->get('http://hidden.example.test/offers')
            ->assertOk()
            ->assertSee('Visible Landing Offer')
            ->assertDontSee('Secret Flash Sale');

        $this->get('http://hidden.example.test/offers/'.$collection->slug)->assertNotFound();
        $this->get('http://hidden.example.test/offers/'.$landing->slug)->assertOk();

        $this->post('http://hidden.example.test/offers/'.$collection->slug.'/checkout', [
            'name' => 'Buyer',
            'phone' => '01711000009',
            'address' => 'Dhaka',
            'quantity' => 1,
            'payment_method' => 'cod',
        ])->assertNotFound();
    }

    public function test_the_admin_can_create_a_flash_sale_with_a_deal_price(): void
    {
        $company = $this->store('admin.example.test');
        app(CompanyContext::class)->set($company);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $product = $this->product('Deal Item', 'COL-2');

        Livewire::test(CreateOffer::class)
            ->fillForm([
                'type' => Offer::TYPE_COLLECTION,
                'collection_kind' => Offer::KIND_FLASH_SALE,
                'status' => Offer::STATUS_PUBLISHED,
                'title' => 'Weekend Flash Sale',
                'slug' => 'weekend-flash-sale',
                'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'items' => [['product_id' => $product->getKey(), 'deal_price' => 350]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $offer = Offer::query()->where('slug', 'weekend-flash-sale')->firstOrFail();
        $this->assertSame(Offer::TYPE_COLLECTION, $offer->type);
        $this->assertSame(Offer::KIND_FLASH_SALE, $offer->collection_kind);
        $this->assertNotNull($offer->ends_at);
        $this->assertSame('350.00', $offer->items()->firstOrFail()->deal_price);
    }

    public function test_a_flash_sale_needs_an_end_time_and_a_valid_schedule(): void
    {
        $company = $this->store('rules.example.test');
        app(CompanyContext::class)->set($company);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $product = $this->product('Rule Item', 'COL-3');
        $base = [
            'type' => Offer::TYPE_COLLECTION,
            'collection_kind' => Offer::KIND_FLASH_SALE,
            'status' => Offer::STATUS_DRAFT,
            'title' => 'Bad Flash Sale',
            'slug' => 'bad-flash-sale',
            'items' => [['product_id' => $product->getKey()]],
        ];

        Livewire::test(CreateOffer::class)
            ->fillForm($base)
            ->call('create')
            ->assertHasFormErrors(['ends_at' => 'required']);

        Livewire::test(CreateOffer::class)
            ->fillForm($base + [
                'starts_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasFormErrors(['ends_at']);

        // Trending / New Arrivals do not need a schedule at all.
        Livewire::test(CreateOffer::class)
            ->fillForm([...$base, 'collection_kind' => Offer::KIND_TRENDING, 'slug' => 'trending-now'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    protected function collection(Company $company, string $title, string $kind, array $attributes = [], array $products = []): Offer
    {
        $offer = Offer::query()->create([
            'company_id' => $company->getKey(),
            'type' => Offer::TYPE_COLLECTION,
            'collection_kind' => $kind,
            'title' => $title,
            'status' => Offer::STATUS_PUBLISHED,
            ...$attributes,
        ]);

        foreach ($products as $index => $product) {
            OfferItem::query()->create([
                'company_id' => $company->getKey(),
                'offer_id' => $offer->getKey(),
                'product_id' => $product->getKey(),
                'quantity' => 1,
                'sort_order' => $index,
            ]);
        }

        return $offer;
    }

    protected function product(string $name, string $sku): Product
    {
        return Product::query()->create([
            'name' => $name,
            'sku' => $sku,
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 10,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
        ]);
    }

    protected function store(string $domain): Company
    {
        static $counter = 0;
        $counter++;

        $company = Company::query()->create([
            'name' => 'Store '.$domain,
            'slug' => str($domain)->slug()->toString(),
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => 'COL'.$counter,
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'is_published' => true,
            'new_customer_delivery_advance_enabled' => false,
        ]);

        return $company;
    }
}
