<?php

namespace Tests\Feature;

use App\Filament\Resources\StorefrontSettings\Pages\EditStorefrontSetting;
use App\Filament\Resources\StorefrontSettings\Pages\ListStorefrontSettings;
use App\Filament\Resources\StorefrontSettings\StorefrontSettingResource;
use App\Models\Company;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Owner decision 2026-10-03: the headless Next.js site is listed next to the
 * Blade storefront in Site → Storefront Settings and shares the very same
 * settings row, so it only adds a switch, a domain and a verified flag.
 */
class NextjsSiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_js_site_is_off_by_default_and_the_blade_site_is_untouched(): void
    {
        $setting = $this->company('wholesale', 'offer.example.test')->storefrontSetting;

        $this->assertFalse($setting->nextjsSiteEnabled());
        $this->assertNull($setting->nextjs_site_domain);
        $this->assertFalse($setting->nextjs_site_domain_verified);
        $this->assertSame('offer.example.test', $setting->company->domain);
    }

    public function test_the_edit_page_saves_the_next_js_site_and_normalises_the_domain(): void
    {
        $setting = $this->company('wholesale', 'offer.example.test')->storefrontSetting;
        $this->actingAsSuperAdmin($setting->company);

        Livewire::test(EditStorefrontSetting::class, ['record' => $setting->getKey()])
            ->call('selectSection', 'nextjs_site')
            ->assertSee(__('Enable Next.js site API'))
            ->set('data.nextjs_site_enabled', true)
            ->set('data.nextjs_site_domain', 'https://www.Wholesale.example.test/')
            ->call('save')
            ->assertHasNoFormErrors();

        $setting->refresh();
        $this->assertTrue($setting->nextjs_site_enabled);
        $this->assertSame('wholesale.example.test', $setting->nextjs_site_domain);
        $this->assertFalse($setting->nextjs_site_domain_verified, 'A new domain starts unverified.');
        // The Blade storefront domain is not touched by the Next.js fields.
        $this->assertSame('offer.example.test', $setting->company->fresh()->domain);
    }

    public function test_domain_verified_needs_a_second_save_and_resets_when_the_domain_changes(): void
    {
        $setting = $this->company('wholesale', 'offer.example.test')->storefrontSetting;
        $setting->update(['nextjs_site_enabled' => true, 'nextjs_site_domain' => 'wholesale.example.test']);
        $this->actingAsSuperAdmin($setting->company);

        Livewire::test(EditStorefrontSetting::class, ['record' => $setting->getKey()])
            ->set('data.nextjs_site_domain_verified', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($setting->refresh()->nextjs_site_domain_verified);

        Livewire::test(EditStorefrontSetting::class, ['record' => $setting->getKey()])
            ->set('data.nextjs_site_domain', 'shop.example.test')
            ->set('data.nextjs_site_domain_verified', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $setting->refresh();
        $this->assertSame('shop.example.test', $setting->nextjs_site_domain);
        $this->assertFalse($setting->nextjs_site_domain_verified);
    }

    public function test_a_next_js_domain_cannot_reuse_a_storefront_or_another_next_js_domain(): void
    {
        $this->company('retail', 'retail.example.test');
        $other = $this->company('other', 'other.example.test')->storefrontSetting;
        $other->update(['nextjs_site_domain' => 'taken.example.test']);
        $setting = $this->company('wholesale', 'offer.example.test')->storefrontSetting;
        $this->actingAsSuperAdmin($setting->company);

        foreach (['retail.example.test', 'www.retail.example.test', 'taken.example.test', 'offer.example.test'] as $domain) {
            Livewire::test(EditStorefrontSetting::class, ['record' => $setting->getKey()])
                ->set('data.nextjs_site_domain', $domain)
                ->call('save')
                ->assertHasFormErrors(['nextjs_site_domain']);
        }

        $this->assertNull($setting->refresh()->nextjs_site_domain);
    }

    public function test_a_blade_domain_cannot_be_a_next_js_site_domain(): void
    {
        $this->company('other', 'other.example.test')->storefrontSetting
            ->update(['nextjs_site_domain' => 'wholesale.example.test']);
        $setting = $this->company('wholesale', 'offer.example.test')->storefrontSetting;
        $this->actingAsSuperAdmin($setting->company);

        Livewire::test(EditStorefrontSetting::class, ['record' => $setting->getKey()])
            ->set('data.company_domain', 'wholesale.example.test')
            ->call('save')
            ->assertHasFormErrors(['company_domain']);

        $this->assertSame('offer.example.test', $setting->company->fresh()->domain);
    }

    public function test_resellers_cannot_claim_a_next_js_site_domain_as_their_own(): void
    {
        $this->company('wholesale', 'offer.example.test')->storefrontSetting
            ->update(['nextjs_site_domain' => 'wholesale.example.test']);

        $this->assertTrue(Company::hostIsTakenByThePlatform('wholesale.example.test'));
        $this->assertTrue(Company::hostIsTakenByThePlatform('www.wholesale.example.test'));
        $this->assertFalse(Company::hostIsTakenByThePlatform('free.example.test'));
    }

    public function test_the_list_shows_both_sites_of_a_company(): void
    {
        $setting = $this->company('wholesale', 'offer.example.test')->storefrontSetting;

        $this->assertSame(['Blade · offer.example.test'], StorefrontSettingResource::siteBadges($setting));

        $setting->update(['nextjs_site_domain' => 'wholesale.example.test']);
        $this->assertSame(
            ['Blade · offer.example.test', 'Next.js · wholesale.example.test ('.__('off').')'],
            StorefrontSettingResource::siteBadges($setting->fresh()),
        );

        $setting->update(['nextjs_site_enabled' => true]);
        $this->assertSame(
            ['Blade · offer.example.test', 'Next.js · wholesale.example.test'],
            StorefrontSettingResource::siteBadges($setting->fresh()),
        );

        $this->actingAsSuperAdmin($setting->company);

        Livewire::test(ListStorefrontSettings::class)
            ->assertSee('Blade · offer.example.test')
            ->assertSee('Next.js · wholesale.example.test');
    }

    protected function company(string $slug, string $domain): Company
    {
        $company = Company::query()->create([
            'name' => 'Company '.$slug,
            'slug' => $slug,
            'domain' => $domain,
            'domain_verified' => true,
            'invoice_prefix' => strtoupper(substr($slug, 0, 3)),
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        StorefrontSetting::query()->create([
            'company_id' => $company->getKey(),
            'is_published' => true,
        ]);

        return $company->fresh();
    }

    /** The edit/list pages only show records of the company currently selected in the panel. */
    protected function actingAsSuperAdmin(Company $company): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        app(CompanyContext::class)->set($company);
    }
}
