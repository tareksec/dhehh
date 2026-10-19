<?php

namespace Tests\Feature;

use App\Filament\Pages\AiSetupChecklist;
use App\Models\Company;
use App\Models\CompanyFaq;
use App\Models\ConversationChannel;
use App\Models\CourierProvider;
use App\Models\CrmAiRun;
use App\Models\Product;
use App\Models\PushDevice;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\AiSettingsService;
use App\Services\Crm\AiSetupChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AI inbox control plane, phase 8 (checklist part): the setup steps are worked out from data. */
class AiSetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::query()->create(['name' => 'Setup Co', 'slug' => 'setup-co', 'invoice_prefix' => 'SC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($this->company);
    }

    /** @return array<string,array{done:bool,detail:?string}> */
    private function steps(?User $user = null): array
    {
        return collect(app(AiSetupChecklistService::class)->steps($this->company->fresh(), $user))->keyBy('key')->all();
    }

    private function product(string $sku, ?string $image): Product
    {
        return Product::query()->create(['name' => 'Item '.$sku, 'sku' => $sku, 'price' => 100, 'sale_price' => 100, 'cost_price' => 50, 'stock' => 5, 'unit' => 'pcs', 'is_active' => true, 'status' => 'available', 'image' => $image]);
    }

    public function test_a_new_company_has_nothing_done(): void
    {
        $steps = $this->steps();

        $this->assertSame(['channel', 'ai', 'about', 'products', 'delivery', 'answers', 'alerts', 'test', 'live'], array_keys($steps));
        $this->assertSame([], array_filter($steps, fn (array $step): bool => $step['done']));
    }

    public function test_each_step_turns_done_when_its_data_exists(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'page-1', 'display_name' => 'Page', 'access_token' => 'token', 'is_active' => true]);
        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => 'key', 'business_about' => 'We sell lamps.']);
        $this->product('A-1', 'products/a.jpg');
        CourierProvider::query()->create(['name' => 'Steadfast', 'code' => 'steadfast', 'is_active' => true, 'is_default' => true, 'settings' => ['delivery_fees' => ['Dhaka' => 60]]]);
        CompanyFaq::query()->create(['question' => 'Warranty?', 'answer' => '1 year', 'keywords' => 'warranty', 'is_active' => true]);
        PushDevice::query()->create(['user_id' => $user->id, 'token' => 'push-token', 'token_hash' => hash('sha256', 'push-token'), 'device_id' => 'phone-1', 'platform' => 'android', 'is_active' => true]);
        CrmAiRun::query()->create(['company_id' => $this->company->id, 'conversation_id' => null, 'is_test' => true, 'status' => 'tested', 'model' => 'm', 'estimated_cost_usd' => 0]);

        $steps = $this->steps($user);

        $this->assertSame([], array_keys(array_filter($steps, fn (array $step): bool => ! $step['done'])));
    }

    public function test_products_need_a_photo_each_and_the_count_is_shown(): void
    {
        $this->product('P-1', 'products/p1.jpg');
        $this->product('P-2', null);

        $step = $this->steps()['products'];
        $this->assertFalse($step['done']);
        $this->assertSame(__(':with of :total products have a photo', ['with' => 1, 'total' => 2]), $step['detail']);

        Product::query()->where('sku', 'P-2')->update(['image' => 'products/p2.jpg']);
        $this->assertTrue($this->steps()['products']['done']);
    }

    public function test_the_ai_step_needs_a_key_and_the_switch_on(): void
    {
        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => true, 'api_key' => '']);
        $this->assertFalse($this->steps()['ai']['done']);

        app(AiSettingsService::class)->save($this->company, 'messaging', [...AiSettingsService::DEFAULTS, 'enabled' => false, 'api_key' => 'key']);
        $this->assertFalse($this->steps()['ai']['done']);
    }

    public function test_another_companys_data_does_not_count(): void
    {
        $other = Company::query()->create(['name' => 'Other Co', 'slug' => 'other-co', 'invoice_prefix' => 'OC', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(CompanyContext::class)->set($other);
        ConversationChannel::query()->create(['provider' => 'messenger', 'external_id' => 'page-o', 'display_name' => 'Other', 'access_token' => 'token', 'is_active' => true]);
        $this->product('O-1', 'products/o.jpg');
        CompanyFaq::query()->create(['question' => 'Q', 'answer' => 'A', 'keywords' => 'q', 'is_active' => true]);
        CrmAiRun::query()->create(['company_id' => $other->id, 'conversation_id' => null, 'is_test' => true, 'status' => 'tested', 'model' => 'm', 'estimated_cost_usd' => 0]);
        app(CompanyContext::class)->set($this->company);

        $steps = $this->steps();

        foreach (['channel', 'products', 'answers', 'test'] as $key) {
            $this->assertFalse($steps[$key]['done'], $key);
        }
    }

    public function test_the_page_shows_progress_and_is_for_people_who_can_view_the_crm(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
        $this->product('V-1', 'products/v.jpg');

        Livewire::test(AiSetupChecklist::class)->assertOk()
            ->assertSee(__(':done of :total steps done', ['done' => 1, 'total' => 9]))
            ->assertSee(__('Connect a chat channel'));

        $this->actingAs(User::factory()->create(['role' => 'inventory_staff', 'is_active' => true]));
        $this->assertFalse(AiSetupChecklist::canAccess());
    }
}
