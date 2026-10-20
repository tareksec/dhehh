<?php

namespace Tests\Feature\Ims;

use App\Models\Company;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Light and dark mode on both IMS panels, light by default, and the sign-in
 * page laid out like the owner's reference (2026-10-20): the welcome panel
 * with three steps, and the light / dark switch beside the language switch.
 */
class ThemeAndSignInTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::query()->create([
            'name' => 'IMS Co',
            'slug' => 'ims-co-'.uniqid(),
            'invoice_prefix' => 'IMS'.random_int(100, 999),
            'domain' => 'erp-shop.test',
            'is_active' => true,
            'ims_enabled' => true,
            'ims_domain' => 'ims-root.test',
        ]);
    }

    public function test_both_ims_panels_offer_dark_mode_and_start_in_light_mode(): void
    {
        foreach (['investor', 'office'] as $id) {
            $panel = Filament::getPanel($id);

            $this->assertTrue($panel->hasDarkMode(), $id);
            $this->assertFalse($panel->hasDarkModeForced(), $id);
            $this->assertSame(ThemeMode::Light, $panel->getDefaultThemeMode(), $id);
        }
    }

    public function test_the_investor_sign_in_shows_the_steps_and_the_theme_switch(): void
    {
        $this->company();

        $this->get('http://ims-root.test/investor/login')
            ->assertOk()
            ->assertSee('ims-auth-steps', false)
            ->assertSee(__('Investor portal'))
            ->assertSee(__('Sign in to your account'))
            ->assertSee(__('See your money and profit'))
            ->assertSee(__('Follow every payout'))
            ->assertSee('ims-theme-toggle', false)
            ->assertSee(__('Switch to dark mode'));
    }

    public function test_the_office_sign_in_shows_its_own_steps(): void
    {
        $this->company();

        $this->get('http://ims-root.test/office/login')
            ->assertOk()
            ->assertSee(__('IMS office'))
            ->assertSee(__('Sign in with your staff account'))
            ->assertSee(__('Approve profit and payouts'))
            ->assertSee('ims-theme-toggle', false);
    }
}
