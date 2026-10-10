<?php

namespace Tests\Feature;

use App\Filament\Pages\ConnectWhatsAppBusinessApp;
use App\Models\Company;
use App\Models\ConversationChannel;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Meta\EmbeddedSignupSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EmbeddedSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCompanyAndUser(string $role = 'super_admin'): array
    {
        $suffix = (string) Str::random(6);
        $company = Company::query()->create([
            'name' => 'Signup Co '.$suffix, 'slug' => 'signup-co-'.Str::lower($suffix), 'invoice_prefix' => 'S'.Str::upper(substr($suffix, 0, 2)),
            'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        app(CompanyContext::class)->set($company);

        return [$company, $user];
    }

    public function test_only_super_admin_can_access_the_connect_page(): void
    {
        [$company, $manager] = $this->makeCompanyAndUser('manager');
        $this->actingAs($manager)->withSession(['current_company_id' => $company->id])
            ->get('/admin/crm/connect-whats-app-business-app')
            ->assertForbidden();

        [$adminCompany, $superAdmin] = $this->makeCompanyAndUser('super_admin');
        $this->actingAs($superAdmin)->withSession(['current_company_id' => $adminCompany->id])
            ->get('/admin/crm/connect-whats-app-business-app')
            ->assertOk();
    }

    public function test_saving_settings_encrypts_the_app_secret_and_never_round_trips_it(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        Livewire::test(ConnectWhatsAppBusinessApp::class)
            ->set('settings.app_id', '123456789')
            ->set('settings.config_id', 'config-abc')
            ->set('settings.verify_token', 'verify-xyz')
            ->set('settings.app_secret', 'super-secret-value')
            ->call('save')
            ->assertSet('settings.app_secret', '')
            ->assertSet('settings.has_app_secret', true)
            ->assertSet('settings.ready', true);

        $stored = (array) $company->fresh()->settings['embedded_signup'];
        $this->assertNotSame('super-secret-value', $stored['app_secret']);
        $this->assertSame('super-secret-value', app(EmbeddedSignupSettingsService::class)->all($company->fresh())['app_secret']);
    }

    public function test_completing_embedded_signup_creates_a_coexistence_channel_scoped_to_the_company(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        app(EmbeddedSignupSettingsService::class)->save($company, [
            'app_id' => '123456789', 'config_id' => 'config-abc', 'verify_token' => 'verify-xyz', 'app_secret' => 'app-secret-value',
        ]);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'long-lived-token-abc'], 200),
            '*/subscribed_apps' => Http::response(['success' => true], 200),
        ]);

        Livewire::test(ConnectWhatsAppBusinessApp::class)
            ->call('completeEmbeddedSignup', 'auth-code-1', 'waba-1', '9990001111', 'Owner Shop');

        $channel = ConversationChannel::withoutGlobalScopes()->where('external_id', '9990001111')->first();
        $this->assertNotNull($channel);
        $this->assertSame($company->getKey(), $channel->company_id);
        $this->assertSame('whatsapp', $channel->provider);
        $this->assertSame('coexistence', $channel->connection_type);
        $this->assertSame('waba-1', $channel->waba_id);
        $this->assertSame('long-lived-token-abc', $channel->access_token);
        $this->assertSame('app-secret-value', $channel->app_secret);
        $this->assertSame('verify-xyz', $channel->verify_token);
        $this->assertTrue($channel->is_active);
    }

    public function test_reconnecting_the_same_number_updates_the_existing_channel_instead_of_duplicating(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        app(EmbeddedSignupSettingsService::class)->save($company, [
            'app_id' => '123456789', 'config_id' => 'config-abc', 'verify_token' => 'verify-xyz', 'app_secret' => 'app-secret-value',
        ]);

        Http::fake([
            '*/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'token-one'], 200)
                ->push(['access_token' => 'token-two'], 200),
            '*/subscribed_apps' => Http::response(['success' => true], 200),
        ]);

        $component = Livewire::test(ConnectWhatsAppBusinessApp::class);
        $component->call('completeEmbeddedSignup', 'auth-code-1', 'waba-1', '9990001111');
        $component->call('completeEmbeddedSignup', 'auth-code-2', 'waba-1', '9990001111');

        $this->assertSame(1, ConversationChannel::withoutGlobalScopes()->where('external_id', '9990001111')->count());
        $this->assertSame('token-two', ConversationChannel::withoutGlobalScopes()->where('external_id', '9990001111')->first()->access_token);
    }

    public function test_failed_code_exchange_does_not_create_a_channel_and_does_not_throw(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        app(EmbeddedSignupSettingsService::class)->save($company, [
            'app_id' => '123456789', 'config_id' => 'config-abc', 'verify_token' => 'verify-xyz', 'app_secret' => 'app-secret-value',
        ]);

        Http::fake([
            '*/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid authorization code', 'code' => 100]], 400),
        ]);

        Livewire::test(ConnectWhatsAppBusinessApp::class)
            ->call('completeEmbeddedSignup', 'bad-code', 'waba-1', '9990001111');

        $this->assertSame(0, ConversationChannel::withoutGlobalScopes()->where('external_id', '9990001111')->count());
    }

    public function test_missing_waba_or_phone_number_id_stops_before_exchanging_the_code(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

        app(EmbeddedSignupSettingsService::class)->save($company, [
            'app_id' => '123456789', 'config_id' => 'config-abc', 'verify_token' => 'verify-xyz', 'app_secret' => 'app-secret-value',
        ]);

        Http::fake();

        Livewire::test(ConnectWhatsAppBusinessApp::class)
            ->call('completeEmbeddedSignup', 'auth-code-1', '', '9990001111')
            ->assertNotified(__('Could not complete the connection'))
            ->call('completeEmbeddedSignup', 'auth-code-1', 'waba-1', '  ')
            ->assertNotified(__('Could not complete the connection'));

        Http::assertNothingSent();
        $this->assertSame(0, ConversationChannel::withoutGlobalScopes()->count());
    }
}
