<?php

namespace Tests\Feature;

use App\Filament\Resources\StorefrontSettings\Pages\CreateStorefrontSetting;
use App\Filament\Resources\StorefrontSettings\Pages\EditStorefrontSetting;
use App\Filament\Resources\StorefrontSettings\Pages\ListStorefrontSettings;
use App\Models\Company;
use App\Models\Order;
use App\Models\StorefrontPayment;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision 2026-10-07 (replaces the 2026-10-03 "one shared row"): a
 * company's Blade storefront (the reseller store) and its headless Next.js
 * site (the main storefront) each have their OWN row in Site → Storefront
 * Settings, so each can be customised on its own. The Next.js row carries the
 * Next.js switch, domain and verified flag; the Blade domain stays on the company.
 */
class NextjsSiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_company_has_a_separate_row_per_site_and_each_is_found_on_its_own(): void
    {
        $company = $this->company('wholesale', 'offer.example.test');
        $blade = $this->bladeSite($company, ['whatsapp_number' => '01711111111']);
        $nextjs = $this->nextjsSite($company, ['whatsapp_number' => '01722222222']);

        $this->assertTrue($company->storefrontSetting->is($blade), 'storefrontSetting stays the Blade row');
        $this->assertTrue($company->nextjsSetting->is($nextjs));
        $this->assertCount(2, $company->storefrontSettings);
        $this->assertTrue(StorefrontSetting::bladeFor($company->getKey())->is($blade));
        $this->assertTrue(StorefrontSetting::nextjsFor($company->getKey())->is($nextjs));
        $this->assertSame('offer.example.test', $blade->siteDomain());
        $this->assertSame('main.example.test', $nextjs->siteDomain());
        $this->assertTrue($blade->isBlade());
        $this->assertTrue($nextjs->isNextjs());
    }

    public function test_a_company_can_have_only_one_row_of_each_site_type(): void
    {
        $company = $this->company('wholesale', 'offer.example.test');
        $this->bladeSite($company);
        $this->nextjsSite($company);

        foreach ([StorefrontSetting::SITE_BLADE, StorefrontSetting::SITE_NEXTJS] as $type) {
            try {
                StorefrontSetting::query()->create(['company_id' => $company->getKey(), 'site_type' => $type]);
                $this->fail("A second {$type} row was accepted.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_next_js_site_is_off_by_default_and_a_blade_row_is_never_a_next_js_site(): void
    {
        $company = $this->company('wholesale', 'offer.example.test');

        $fresh = StorefrontSetting::query()->create(['company_id' => $company->getKey(), 'site_type' => StorefrontSetting::SITE_NEXTJS]);
        $this->assertFalse($fresh->nextjsSiteEnabled());

        // A legacy flag left on a Blade row must not open the API.
        $legacy = $this->company('legacy', 'legacy.example.test');
        $row = StorefrontSetting::query()->create(['company_id' => $legacy->getKey(), 'nextjs_site_enabled' => true]);
        $this->assertFalse($row->nextjsSiteEnabled());
    }

    public function test_saving_the_next_js_row_normalises_its_domain_and_never_touches_the_company_domain(): void
    {
        $company = $this->company('wholesale', 'offer.example.test');
        $this->bladeSite($company);
        $nextjs = $this->nextjsSite($company, ['nextjs_site_enabled' => false, 'nextjs_site_domain' => null]);
        $this->actingAsSuperAdmin($company);

        Livewire::test(EditStorefrontSetting::class, ['record' => $nextjs->getKey()])
            ->call('selectSection', 'nextjs_site')
            ->assertSee(__('Enable Next.js site API'))
            ->set('data.nextjs_site_enabled', true)
            ->set('data.nextjs_site_domain', 'https://www.Wholesale.example.test/')
            ->call('save')
            ->assertHasNoFormErrors();

        $nextjs->refresh();
        $this->assertTrue($nextjs->nextjs_site_enabled);
        $this->assertSame('wholesale.example.test', $nextjs->nextjs_site_domain);
        $this->assertFalse($nextjs->nextjs_site_domain_verified, 'A new domain starts unverified.');
        $this->assertSame('offer.example.test', $company->fresh()->domain, 'Saving the Next.js row must not blank or change the Blade domain.');
        $this->assertTrue($company->fresh()->domain_verified);
    }

    public function test_saving_a_blade_row_never_gives_it_a_next_js_switch_or_domain(): void
    {
        $company = $this->company('wholesale', 'offer.example.test');
        $blade = $this->bladeSite($company);
        $this->actingAsSuperAdmin($company);

        Livewire::test(EditStorefrontSetting::class, ['record' => $blade->getKey()])
            ->set('data.whatsapp_number', '01733333333')
            ->call('save')
            ->assertHasNoFormErrors();

        $blade->refresh();
        $this->assertSame('01733333333', $blade->whatsapp_number);
        $this->assertFalse($blade->nextjs_site_enabled);
        $this->assertNull($blade->nextjs_site_domain);
    }

    public function test_domain_verified_needs_a_second_save_and_resets_when_the_domain_changes(): void
    {
        $company = $this->company('wholesale', 'offer.example.test');
        $nextjs = $this->nextjsSite($company, ['nextjs_site_domain' => 'wholesale.example.test']);
        $this->actingAsSuperAdmin($company);

        Livewire::test(EditStorefrontSetting::class, ['record' => $nextjs->getKey()])
            ->set('data.nextjs_site_domain_verified', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($nextjs->refresh()->nextjs_site_domain_verified);

        Livewire::test(EditStorefrontSetting::class, ['record' => $nextjs->getKey()])
            ->set('data.nextjs_site_domain', 'shop.example.test')
            ->set('data.nextjs_site_domain_verified', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $nextjs->refresh();
        $this->assertSame('shop.example.test', $nextjs->nextjs_site_domain);
        $this->assertFalse($nextjs->nextjs_site_domain_verified);
    }

    public function test_a_next_js_domain_cannot_reuse_a_storefront_or_another_next_js_domain(): void
    {
        $this->company('retail', 'retail.example.test');
        $other = $this->company('other', 'other.example.test');
        $this->nextjsSite($other, ['nextjs_site_domain' => 'taken.example.test']);
        $company = $this->company('wholesale', 'offer.example.test');
        $nextjs = $this->nextjsSite($company, ['nextjs_site_domain' => null]);
        $this->actingAsSuperAdmin($company);

        foreach (['retail.example.test', 'www.retail.example.test', 'taken.example.test', 'offer.example.test'] as $domain) {
            Livewire::test(EditStorefrontSetting::class, ['record' => $nextjs->getKey()])
                ->set('data.nextjs_site_domain', $domain)
                ->call('save')
                ->assertHasFormErrors(['nextjs_site_domain']);
        }

        $this->assertNull($nextjs->refresh()->nextjs_site_domain);
    }

    public function test_a_blade_domain_cannot_be_a_next_js_site_domain(): void
    {
        $other = $this->company('other', 'other.example.test');
        $this->nextjsSite($other, ['nextjs_site_domain' => 'wholesale.example.test']);
        $company = $this->company('wholesale', 'offer.example.test');
        $blade = $this->bladeSite($company);
        $this->actingAsSuperAdmin($company);

        Livewire::test(EditStorefrontSetting::class, ['record' => $blade->getKey()])
            ->set('data.company_domain', 'wholesale.example.test')
            ->call('save')
            ->assertHasFormErrors(['company_domain']);

        $this->assertSame('offer.example.test', $company->fresh()->domain);
    }

    public function test_resellers_cannot_claim_a_next_js_site_domain_as_their_own(): void
    {
        $this->nextjsSite($this->company('wholesale', 'offer.example.test'), ['nextjs_site_domain' => 'wholesale.example.test']);

        $this->assertTrue(Company::hostIsTakenByThePlatform('wholesale.example.test'));
        $this->assertTrue(Company::hostIsTakenByThePlatform('www.wholesale.example.test'));
        $this->assertFalse(Company::hostIsTakenByThePlatform('free.example.test'));
    }

    public function test_a_blade_row_never_owns_a_next_js_domain(): void
    {
        // A Blade row has no Next.js domain even if a stale value sits in the column.
        $company = $this->company('wholesale', 'offer.example.test');
        $this->bladeSite($company, ['nextjs_site_domain' => 'stale.example.test']);

        $this->assertFalse(StorefrontSetting::nextjsDomainTaken('stale.example.test'));
    }

    public function test_each_site_only_shows_the_sections_that_apply_to_it(): void
    {
        $company = $this->company('wholesale', 'offer.example.test');
        $blade = $this->bladeSite($company);
        $nextjs = $this->nextjsSite($company);
        $this->actingAsSuperAdmin($company);

        $bladeKeys = array_column(Livewire::test(EditStorefrontSetting::class, ['record' => $blade->getKey()])->instance()->sectionNavigation(), 'key');
        $nextjsKeys = array_column(Livewire::test(EditStorefrontSetting::class, ['record' => $nextjs->getKey()])->instance()->sectionNavigation(), 'key');

        // Reseller settings live on Resellers → Reseller Settings, not on either site (owner rule, 2026-10-06).
        $this->assertNotContains('reseller', $bladeKeys);
        $this->assertNotContains('nextjs_site', $bladeKeys);
        $this->assertContains('nextjs_site', $nextjsKeys);
        $this->assertNotContains('reseller', $nextjsKeys);
        $this->assertContains('design', $nextjsKeys, 'The Next.js site is customised through the same design/content sections.');

        Livewire::test(EditStorefrontSetting::class, ['record' => $nextjs->getKey()])
            ->assertFormFieldIsHidden('company_domain')
            ->call('selectSection', 'nextjs_site')
            ->assertFormFieldIsVisible('nextjs_site_domain');
    }

    public function test_the_list_shows_one_row_per_site_with_its_own_domain(): void
    {
        $company = $this->company('wholesale', 'offer.example.test');
        $blade = $this->bladeSite($company);
        $nextjs = $this->nextjsSite($company, ['nextjs_site_domain' => 'wholesale.example.test']);
        $this->actingAsSuperAdmin($company);

        $this->assertSame(__('Blade storefront (reseller store)'), $blade->siteTypeLabel());
        $this->assertSame(__('Next.js site (main storefront)'), $nextjs->siteTypeLabel());

        Livewire::test(ListStorefrontSettings::class)
            ->assertCanSeeTableRecords([$blade, $nextjs])
            ->assertSee('offer.example.test')
            ->assertSee('wholesale.example.test')
            ->assertSee(__('Next.js site (main storefront)'));
    }

    public function test_a_new_next_js_site_can_start_as_a_copy_of_the_blade_settings(): void
    {
        $user = User::factory()->create();
        $company = $user->defaultCompany();
        $company->forceFill(['domain' => 'create-store.example.test', 'domain_verified' => true])->save();
        $blade = StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'theme_color' => '#123456',
            'whatsapp_number' => '01744444444',
            'payment_credentials' => ['paystation_merchant_id' => 'mid-1', 'paystation_password' => 'pass-1'],
            'online_payment_gateway' => 'paystation',
        ]);
        app(CompanyContext::class)->set($company);
        $this->actingAs($user);

        Livewire::test(CreateStorefrontSetting::class)
            ->fillForm([
                'site_type' => StorefrontSetting::SITE_NEXTJS,
                'company_id' => $company->getKey(),
                'copy_from_blade' => true,
                'is_published' => true,
                'customer_accounts_enabled' => true,
                'nextjs_site_enabled' => true,
                'nextjs_site_domain' => 'https://www.Main.example.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $nextjs = StorefrontSetting::nextjsFor($company->getKey());

        $this->assertNotNull($nextjs);
        $this->assertSame('main.example.test', $nextjs->nextjs_site_domain);
        $this->assertTrue($nextjs->nextjs_site_enabled);
        $this->assertSame('#123456', $nextjs->theme_color, 'Copied from the Blade row, not the form default.');
        $this->assertSame('01744444444', $nextjs->whatsapp_number);
        $this->assertSame(['paystation_merchant_id' => 'mid-1', 'paystation_password' => 'pass-1'], $nextjs->payment_credentials, 'Credentials are copied intact, not re-encrypted.');
        $this->assertSame($blade->getRawOriginal('payment_credentials'), $nextjs->getRawOriginal('payment_credentials'));
        $this->assertSame('create-store.example.test', $company->fresh()->domain, 'Creating the Next.js row leaves the Blade domain alone.');
        $this->assertNull($blade->fresh()->nextjs_site_domain);
    }

    public function test_a_new_next_js_site_can_start_from_blank_defaults(): void
    {
        $user = User::factory()->create();
        $company = $user->defaultCompany();
        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'theme_color' => '#123456',
            'payment_credentials' => ['paystation_merchant_id' => 'mid-1'],
        ]);
        app(CompanyContext::class)->set($company);
        $this->actingAs($user);

        Livewire::test(CreateStorefrontSetting::class)
            ->fillForm([
                'site_type' => StorefrontSetting::SITE_NEXTJS,
                'company_id' => $company->getKey(),
                'copy_from_blade' => false,
                'is_published' => true,
                'customer_accounts_enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $nextjs = StorefrontSetting::nextjsFor($company->getKey());

        $this->assertNotNull($nextjs);
        $this->assertNotSame('#123456', $nextjs->theme_color);
        $this->assertEmpty($nextjs->payment_credentials);
    }

    public function test_a_company_cannot_get_a_second_site_of_the_same_kind(): void
    {
        $user = User::factory()->create();
        $company = $user->defaultCompany();
        $this->nextjsSite($company);
        app(CompanyContext::class)->set($company);
        $this->actingAs($user);

        Livewire::test(CreateStorefrontSetting::class)
            ->fillForm([
                'site_type' => StorefrontSetting::SITE_NEXTJS,
                'company_id' => $company->getKey(),
                'is_published' => true,
                'customer_accounts_enabled' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['site_type']);

        $this->assertSame(1, StorefrontSetting::withoutGlobalScopes()->where('company_id', $company->getKey())->count());
    }

    public function test_orders_and_payments_remember_their_site_and_fall_back_to_the_blade_row(): void
    {
        $company = $this->company('wholesale', 'offer.example.test');
        $blade = $this->bladeSite($company);
        $nextjs = $this->nextjsSite($company);
        app(CompanyContext::class)->set($company);

        $this->assertNull($blade->recordedSiteId(), 'The Blade storefront is stored as NULL.');
        $this->assertSame($nextjs->getKey(), $nextjs->recordedSiteId());

        $legacy = new Order(['company_id' => $company->getKey()]);
        $this->assertTrue($legacy->siteSetting()->is($blade), 'Orders from before the split read the Blade row.');

        $fromNextjs = new Order(['company_id' => $company->getKey(), 'storefront_setting_id' => $nextjs->getKey()]);
        $this->assertTrue($fromNextjs->siteSetting()->is($nextjs));

        $payment = new StorefrontPayment(['company_id' => $company->getKey(), 'storefront_setting_id' => $nextjs->getKey()]);
        $this->assertTrue($payment->siteSetting()->is($nextjs));
        $this->assertTrue((new StorefrontPayment(['company_id' => $company->getKey()]))->siteSetting()->is($blade));
    }

    public function test_the_migration_moves_an_existing_next_js_config_into_its_own_row(): void
    {
        $withSite = $this->company('wholesale', 'offer.example.test');
        $plain = $this->company('plain', 'plain.example.test');

        // Shape of the data before the split: one Blade row carrying the Next.js switch and domain.
        $old = StorefrontSetting::query()->create([
            'company_id' => $withSite->getKey(),
            'theme_color' => '#123456',
            'payment_credentials' => ['paystation_merchant_id' => 'mid-1'],
            'nextjs_site_enabled' => true,
            'nextjs_site_domain' => 'wholesale.example.test',
            'nextjs_site_domain_verified' => true,
        ]);
        $untouched = $this->bladeSite($plain);

        $migration = require base_path('database/migrations/2026_10_07_100000_add_site_type_to_storefront_settings_table.php');
        $split = new \ReflectionMethod($migration, 'splitExistingNextjsSites');

        $split->invoke($migration);
        $split->invoke($migration); // running it again changes nothing

        $this->assertSame(2, StorefrontSetting::withoutGlobalScopes()->where('company_id', $withSite->getKey())->count());

        $blade = $old->fresh();
        $this->assertTrue($blade->isBlade());
        $this->assertFalse($blade->nextjs_site_enabled);
        $this->assertNull($blade->nextjs_site_domain);
        $this->assertFalse($blade->nextjs_site_domain_verified);

        $nextjs = StorefrontSetting::nextjsFor($withSite->getKey());
        $this->assertTrue($nextjs->nextjs_site_enabled);
        $this->assertSame('wholesale.example.test', $nextjs->nextjs_site_domain);
        $this->assertTrue($nextjs->nextjs_site_domain_verified);
        $this->assertSame('#123456', $nextjs->theme_color, 'The new row starts as a copy of the Blade settings.');
        $this->assertSame(['paystation_merchant_id' => 'mid-1'], $nextjs->payment_credentials);

        $this->assertSame(1, StorefrontSetting::withoutGlobalScopes()->where('company_id', $plain->getKey())->count(), 'A company without a Next.js site gets no extra row.');
        $this->assertTrue($untouched->fresh()->isBlade());
    }

    protected function company(string $slug, string $domain): Company
    {
        return Company::query()->create([
            'name' => 'Company '.$slug,
            'slug' => $slug,
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ])->fresh();
    }

    /** @param  array<string, mixed>  $attributes */
    protected function bladeSite(Company $company, array $attributes = []): StorefrontSetting
    {
        return StorefrontSetting::query()->create($attributes + [
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_BLADE,
            'is_published' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function nextjsSite(Company $company, array $attributes = []): StorefrontSetting
    {
        return StorefrontSetting::query()->create($attributes + [
            'company_id' => $company->getKey(),
            'site_type' => StorefrontSetting::SITE_NEXTJS,
            'is_published' => true,
            'nextjs_site_enabled' => true,
            'nextjs_site_domain' => 'main.example.test',
        ]);
    }

    /** The edit/list pages only show records of the company currently selected in the panel. */
    protected function actingAsSuperAdmin(Company $company): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        app(CompanyContext::class)->set($company);
    }
}
