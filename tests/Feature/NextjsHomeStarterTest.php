<?php

namespace Tests\Feature;

use App\Filament\Resources\StorefrontSettings\Pages\EditStorefrontSetting;
use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Support\StorefrontCategoryIcons;
use App\Support\StorefrontHomeStarter;
use App\Support\StorefrontSiteDesign;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner request 2026-10-14: "arrange the home page like zamzamint.netlify.app, every section —
 * doing it by hand in the admin takes too long". The starter layout is loaded from the Home Page
 * Builder (nothing is saved until Save), matches the company's categories by name, and a product
 * row can mix several categories.
 */
class NextjsHomeStarterTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected StorefrontSetting $nextjs;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->company = Company::query()->create([
            'name' => 'ZamZam International', 'slug' => 'zamzam-international', 'domain' => 'offer.example.test', 'domain_verified' => true,
            'invoice_prefix' => 'ZAM', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
            'address' => 'House 38, Road 5/A, Uttara, Dhaka',
        ])->fresh();
        $this->nextjs = StorefrontSetting::query()->create([
            'company_id' => $this->company->getKey(), 'site_type' => StorefrontSetting::SITE_NEXTJS, 'is_published' => true, 'nextjs_site_enabled' => true,
        ]);
        [, $this->token] = CompanyApiKey::generate($this->company);
        app(CompanyContext::class)->set($this->company);
    }

    // ---------------------------------------------------------- the layout

    public function test_the_starter_is_the_reference_home_page_in_order(): void
    {
        $categories = $this->liveCategories();

        $sections = StorefrontHomeStarter::sections($categories);

        $this->assertSame(
            ['hero', 'trust_strip', 'category_grid', 'deals_banner', 'product_row', 'flash_sale', 'product_row', 'product_row', 'product_row', 'product_row', 'mobile_cta', 'seo_text'],
            array_column($sections, 'type'),
        );
        $this->assertSame(
            ['Latest Gadgets', 'Best Audio Picks', 'Fans & Cooling', 'Kitchen Essentials', 'Beauty & Lifestyle'],
            collect($sections)->where('type', 'product_row')->pluck('data.title')->values()->all(),
        );
        $this->assertSame(
            ['Fast Delivery', 'Easy Payment', 'Smart Gadgets', 'Quality Check'],
            array_column($sections[1]['data']['items'], 'title'),
        );
        $this->assertSame('Best Deals Today', $sections[3]['data']['title']);
        $this->assertSame('Order Easily on WhatsApp', $sections[10]['data']['heading']);
        $this->assertSame('Wholesale Gadgets in Bangladesh', $sections[11]['data']['heading']);
        $this->assertStringContainsString('{products_count}', $sections[11]['data']['body']);
    }

    public function test_the_starter_is_already_clean_data_and_every_section_is_on(): void
    {
        $sections = StorefrontHomeStarter::sections($this->liveCategories());

        foreach ($sections as $section) {
            $this->assertArrayHasKey($section['type'], StorefrontSiteDesign::HOME_SECTION_TYPES);
            $this->assertTrue($section['data']['enabled'], $section['type'].' is on');
        }

        $this->assertSame($sections, StorefrontSiteDesign::homeSections($sections, forForm: true), 'cleaning it again changes nothing');
        $this->assertCount(12, StorefrontSiteDesign::homeSections($sections), 'nothing is dropped by the cleaner');
    }

    public function test_categories_match_by_name_even_when_spelled_differently(): void
    {
        // The live names: "Cocker" for the cooker, "EearBuds", "Gift and Crafts" without the ampersand.
        $cocker = $this->category('Cocker');
        $earbuds = $this->category('EearBuds');
        $gift = $this->category('Gift and Crafts');
        $audio = $this->category('Audio');
        $this->category('Unrelated');

        $sections = StorefrontHomeStarter::sections(Category::query()->orderBy('id')->get());

        $grid = collect($sections)->firstWhere('type', 'category_grid');
        $this->assertSame([$audio->getKey(), $gift->getKey(), $earbuds->getKey(), $cocker->getKey()], $grid['data']['category_ids'], 'the reference order is kept, and "Unrelated" is not picked');

        $rows = collect($sections)->where('type', 'product_row')->keyBy('data.title');
        $this->assertSame([$audio->getKey(), $earbuds->getKey()], $rows['Best Audio Picks']['data']['category_ids']);
        $this->assertSame([$cocker->getKey()], $rows['Kitchen Essentials']['data']['category_ids']);
        $this->assertSame([$gift->getKey()], $rows['Beauty & Lifestyle']['data']['category_ids']);
        $this->assertFalse($rows->has('Fans & Cooling'), 'a row with none of its categories is left out');
    }

    public function test_a_company_with_no_matching_categories_still_gets_a_working_home_page(): void
    {
        $sections = StorefrontHomeStarter::sections(collect());

        $this->assertSame(
            ['hero', 'trust_strip', 'category_grid', 'deals_banner', 'product_row', 'flash_sale', 'mobile_cta', 'seo_text'],
            array_column($sections, 'type'),
        );
        $this->assertSame([], $sections[2]['data']['category_ids'], 'no picked categories means every category with products');
    }

    public function test_every_starter_category_icon_exists_in_the_icon_picker(): void
    {
        $options = StorefrontCategoryIcons::options();
        $names = ['Gadgets', 'Audio', 'Fan', 'Kitchen', 'Cosmetics', 'Gift & Crafts', 'Home Decor', 'Accessories', 'EearBuds', 'Solar Fan', 'Air Fryer', 'Cooker', 'Lipstick', 'DIY Necklace Kit'];
        $categories = collect($names)->map(fn (string $name): Category => $this->category($name));

        $fills = StorefrontHomeStarter::categoryFills($categories);

        $this->assertCount(14, $fills);
        foreach ($fills as $fill) {
            $this->assertArrayHasKey($fill['icon'], $options);
            $this->assertLessThanOrEqual(80, mb_strlen($fill['tagline']));
        }
    }

    public function test_only_blank_taglines_and_icons_are_filled(): void
    {
        $kept = $this->category('Audio');
        $kept->forceFill(['tagline' => 'My own line', 'icon' => 'heroicon-o-star'])->save();
        $half = $this->category('Fan');
        $half->forceFill(['tagline' => 'Mine too'])->save();
        $blank = $this->category('Cosmetics');

        $fills = StorefrontHomeStarter::categoryFills(Category::query()->get());

        $this->assertArrayNotHasKey($kept->getKey(), $fills, 'a category the owner finished is left alone');
        $this->assertSame(['icon'], array_keys($fills[$half->getKey()]), 'only the missing half is filled');
        $this->assertSame(['tagline', 'icon'], array_keys($fills[$blank->getKey()]));
    }

    // ----------------------------------------------- a row over categories

    public function test_a_product_row_can_mix_several_categories_and_older_rows_still_work(): void
    {
        $audio = $this->category('Audio');
        $earbuds = $this->category('EearBuds');
        $gone = $this->category('Gone', active: false);

        $this->nextjs->update(['home_sections' => [
            ['type' => 'product_row', 'data' => ['title' => 'Audio and earbuds', 'source' => 'category', 'category_ids' => [$audio->getKey(), $earbuds->getKey(), $gone->getKey(), 'junk']]],
            ['type' => 'product_row', 'data' => ['source' => 'category', 'category_id' => $audio->getKey()]],
            ['type' => 'product_row', 'data' => ['source' => 'category', 'category_ids' => [$gone->getKey()]]],
            ['type' => 'product_row', 'data' => ['source' => 'latest', 'category_ids' => [$audio->getKey()]]],
        ]]);

        $rows = collect($this->getJson('/api/v1/storefront/site', ['Authorization' => 'Bearer '.$this->token])->assertOk()->json('data.home_sections'))
            ->where('type', 'product_row')
            ->values();

        $this->assertCount(3, $rows, 'a row whose categories are all gone is left out');
        $this->assertSame(['audio', 'eearbuds'], $rows[0]['data']['category_slugs']);
        $this->assertSame('audio', $rows[0]['data']['category_slug'], 'the first one stays for older websites');
        $this->assertSame(['audio'], $rows[1]['data']['category_slugs'], 'a row saved with one category keeps working');
        $this->assertSame([], $rows[2]['data']['category_slugs'], 'only a "category" row has categories');
        $this->assertArrayNotHasKey('category_ids', $rows[0]['data']);
    }

    // -------------------------------------------------------------- admin

    public function test_the_starter_loads_into_the_builder_without_saving_until_the_owner_saves(): void
    {
        $this->liveCategories();
        $this->actingAsSuperAdmin();

        $component = Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'nextjs_design')
            ->assertSee(__('Load the starter layout'))
            ->assertSee(__('Fill empty category details'))
            ->callAction(TestAction::make('loadStarterLayout')->schemaComponent('home_builder', schema: 'form'))
            ->assertNotified(__('Starter layout loaded'));

        $this->assertNull($this->nextjs->fresh()->home_sections, 'loading is only a draft');

        $sections = array_values($component->get('data.home_sections'));
        $this->assertSame('Latest Gadgets', collect($sections)->firstWhere('type', 'product_row')['data']['title']);
        $this->assertSame(['Mobile App', 'Track Order'], array_column(array_values($component->get('data.site_design.topbar.links')), 'label'));

        $component->call('save')->assertHasNoFormErrors();

        $saved = $this->nextjs->fresh();
        $this->assertSame(12, count($saved->home_sections));
        $this->assertSame('Best Audio Picks', collect($saved->homeSections())->where('type', 'product_row')->pluck('data.title')->get(1));
        $this->assertSame('/#mobile-app', $saved->siteDesign()['topbar']['links'][0]['url']);
    }

    public function test_loading_the_starter_keeps_top_bar_links_the_owner_already_made(): void
    {
        $this->nextjs->update(['site_design' => ['topbar' => ['links' => [['label' => 'Wholesale', 'type' => 'shop']]]]]);
        $this->actingAsSuperAdmin();

        $component = Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'nextjs_design')
            ->callAction(TestAction::make('loadStarterLayout')->schemaComponent('home_builder', schema: 'form'));

        $this->assertSame(['Wholesale'], array_column(array_values($component->get('data.site_design.topbar.links')), 'label'));
    }

    public function test_filling_category_details_writes_only_blanks_in_this_company(): void
    {
        $audio = $this->category('Audio');
        $own = $this->category('Fan');
        $own->forceFill(['tagline' => 'Mine', 'icon' => 'heroicon-o-star'])->save();

        $other = Company::query()->create(['name' => 'Other', 'slug' => 'other', 'invoice_prefix' => 'OTH', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        $foreign = $this->category('Audio');
        app(CompanyContext::class)->set($this->company);

        $this->actingAsSuperAdmin();

        Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'nextjs_design')
            ->callAction(TestAction::make('fillCategoryDetails')->schemaComponent('home_builder', schema: 'form'))
            ->assertNotified(__(':count categories updated', ['count' => 1]));

        $audio = Category::withoutGlobalScopes()->find($audio->getKey());
        $this->assertSame('Earbuds · TWS · Headsets', $audio->tagline);
        $this->assertSame('heroicon-o-speaker-wave', $audio->icon);
        $this->assertSame('Mine', Category::withoutGlobalScopes()->find($own->getKey())->tagline, 'the owner\'s own line is never changed');
        $this->assertNull(Category::withoutGlobalScopes()->find($foreign->getKey())->tagline, 'another company is never touched');

        // Run again: nothing left to fill.
        Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'nextjs_design')
            ->callAction(TestAction::make('fillCategoryDetails')->schemaComponent('home_builder', schema: 'form'))
            ->assertNotified(__('Nothing to fill'));
    }

    public function test_a_row_over_categories_saves_from_the_form(): void
    {
        $audio = $this->category('Audio');
        $earbuds = $this->category('EearBuds');
        $this->product('Pods', 'P-1', $audio);
        $this->actingAsSuperAdmin();

        Livewire::test(EditStorefrontSetting::class, ['record' => $this->nextjs->getKey()])
            ->call('selectSection', 'nextjs_design')
            ->set('data.home_sections', [
                'a' => ['type' => 'product_row', 'data' => ['enabled' => true, 'title' => 'Audio', 'source' => 'category', 'category_ids' => [$audio->getKey(), $earbuds->getKey()], 'limit' => 6, 'columns' => 6]],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$audio->getKey(), $earbuds->getKey()], $this->nextjs->fresh()->homeSections()[0]['data']['category_ids']);
    }

    // ------------------------------------------------------------ helpers

    /** @return Collection<int, Category> */
    protected function liveCategories(): Collection
    {
        foreach (['Accessories', 'Air Fryer', 'Audio', 'Cocker', 'Cosmetics', 'DIY Necklace Kit', 'EearBuds', 'Fan', 'Gift and Crafts', 'Home Decor', 'Solar Fan'] as $name) {
            $this->category($name);
        }

        // Kitchen and Lipstick are not categories this company has; the rows must still build.
        $this->category('Kitchen');
        $this->category('Lipstick');
        $this->category('Gadgets');

        return Category::query()->orderBy('id')->get();
    }

    protected function category(string $name, bool $active = true): Category
    {
        return Category::query()->create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'is_active' => $active]);
    }

    protected function product(string $name, string $sku, ?Category $category): Product
    {
        return Product::query()->create([
            'name' => $name, 'sku' => $sku, 'category_id' => $category?->getKey(), 'price' => 500, 'sale_price' => 500, 'cost_price' => 300,
            'stock' => 20, 'unit' => 'pcs', 'reorder_level' => 1, 'vat_rate' => 0, 'is_active' => true, 'status' => Product::STATUS_AVAILABLE,
        ]);
    }

    protected function actingAsSuperAdmin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        app(CompanyContext::class)->set($this->company);
    }
}
