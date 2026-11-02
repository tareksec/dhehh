<?php

namespace Tests\Feature;

use App\Filament\Pages\Integrations;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\Crm\AiSettingsService;
use App\Services\OrderAutofill\OrderAutofillService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Owner request 2026-11-02: "Fill with AI" on the new-order form. Staff paste a customer's message; the company's own
 * (possibly free) model reads it; the result is checked against the real records before it reaches the form.
 */
class OrderAutofillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $lamp;

    private Product $shirt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Fill Co', 'slug' => 'fill-co', 'invoice_prefix' => 'FIL', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        app(CompanyContext::class)->set($this->company);
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]))
            ->withSession(['current_company_id' => $this->company->id, 'current_company_selection_explicit' => true]);

        $this->lamp = Product::query()->create(['name' => 'LED Table Lamp', 'sku' => 'FIL-LAMP', 'price' => 500, 'sale_price' => 450, 'stock' => 50, 'is_active' => true]);
        $this->shirt = Product::query()->create(['name' => 'Cotton Shirt', 'sku' => 'FIL-SHIRT', 'price' => 900, 'sale_price' => 800, 'stock' => 0, 'is_active' => true, 'has_variants' => true]);
    }

    private function turnOn(array $extra = []): void
    {
        app(AiSettingsService::class)->save($this->company, AiSettingsService::TOOL_ORDER_AUTOFILL, [
            'enabled' => true, 'api_format' => 'openai', 'provider' => 'Groq', 'base_url' => 'https://api.groq.test/v1/chat/completions',
            'model' => 'free-model', 'api_key' => 'free-key', ...$extra,
        ]);
        $this->company->refresh();
        app(CompanyContext::class)->set($this->company);
    }

    /** A new fake each time: stubs added to the same fake stack up and the first one always wins. */
    private function freshHttp(): void
    {
        app()->forgetInstance(HttpFactory::class);
        Http::clearResolvedInstance(HttpFactory::class);
    }

    private function modelSays(array|string $reply): void
    {
        $this->freshHttp();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => is_array($reply) ? json_encode($reply, JSON_UNESCAPED_UNICODE) : $reply]]]])]);
    }

    private function message(): string
    {
        return "আসসালামু আলাইকুম, আমি রহিম। ০১৭১২-৩৪৫৬ ৯০ এই নাম্বারে ফোন দিয়েন\nঠিকানা: বাড়ি ৫, রোড ২, মিরপুর, ঢাকা\n২টা LED Table Lamp লাগবে। বিকাশে ২০০ টাকা অগ্রিম দিলাম। ডেলিভারির আগে কল দিবেন।";
    }

    private function goodReply(): array
    {
        return [
            'customer_name' => 'রহিম', 'phone' => '01700000000', 'address' => 'বাড়ি ৫, রোড ২, মিরপুর, ঢাকা',
            'items' => [['product_id' => $this->lamp->id, 'variant_id' => null, 'name' => 'LED Table Lamp', 'quantity' => '২']],
            'advance_paid' => '২০০', 'payment_method' => 'bKash', 'note' => 'ডেলিভারির আগে কল দিবেন',
        ];
    }

    public function test_it_reads_the_customer_products_advance_and_note(): void
    {
        $this->turnOn();
        $this->modelSays($this->goodReply());
        $bkash = Account::query()->create(['name' => 'bKash Personal', 'type' => 'mobile_banking', 'opening_balance' => 0, 'is_active' => true]);

        $result = app(OrderAutofillService::class)->read($this->company, $this->message());

        // The phone comes from the message itself (Bangla digits, a stray space), not from what the model said.
        $this->assertSame('01712345690', $result['customer']['phone']);
        $this->assertSame('রহিম', $result['customer']['name']);
        $this->assertTrue($result['customer']['is_new']);
        $this->assertNull($result['customer']['id']);
        $this->assertSame([['product_id' => $this->lamp->id, 'variant_id' => null, 'variant_label' => null, 'name' => 'LED Table Lamp', 'quantity' => 2, 'unit_price' => 450.0]], $result['items']);
        $this->assertSame(200.0, $result['advance']);
        $this->assertSame($bkash->id, $result['payment_account_id']);
        $this->assertSame('ডেলিভারির আগে কল দিবেন', $result['note']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_a_customer_with_that_phone_is_found_not_duplicated(): void
    {
        $this->turnOn();
        $this->modelSays($this->goodReply());
        $existing = Customer::query()->create(['name' => 'Rahim Old', 'phone' => '+8801712345690', 'address' => 'Mirpur, Dhaka']);

        $result = app(OrderAutofillService::class)->read($this->company, $this->message());

        $this->assertSame($existing->id, $result['customer']['id']);
        $this->assertSame('Rahim Old', $result['customer']['name']);
        $this->assertFalse($result['customer']['is_new']);
        // The saved address is kept; the message's different one is shown to staff, never written over it.
        $this->assertSame('Mirpur, Dhaka', $result['customer']['address']);
        $this->assertStringContainsString('differs', implode(' ', $result['warnings']));
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_a_product_the_model_made_up_is_dropped_and_listed_for_staff(): void
    {
        $this->turnOn();
        $this->modelSays([...$this->goodReply(), 'items' => [
            ['product_id' => 99999, 'variant_id' => null, 'name' => 'Magic Wand', 'quantity' => 1],
            ['product_id' => null, 'variant_id' => null, 'name' => 'led table lamp', 'quantity' => 1],
        ]]);

        $result = app(OrderAutofillService::class)->read($this->company, $this->message());

        $this->assertCount(1, $result['items']);
        $this->assertSame($this->lamp->id, $result['items'][0]['product_id']);
        $this->assertSame([['text' => 'Magic Wand', 'quantity' => 1]], $result['unmatched']);
        $this->assertStringContainsString('Magic Wand', implode(' ', $result['warnings']));
    }

    public function test_the_same_product_twice_is_one_line_and_a_price_in_the_reply_is_ignored(): void
    {
        $this->turnOn();
        $this->modelSays([...$this->goodReply(), 'items' => [
            ['product_id' => $this->lamp->id, 'name' => 'lamp', 'quantity' => 1, 'unit_price' => 1],
            ['product_id' => $this->lamp->id, 'name' => 'lamp', 'quantity' => 2, 'price' => 1],
        ]]);

        $items = app(OrderAutofillService::class)->read($this->company, $this->message())['items'];

        $this->assertCount(1, $items);
        $this->assertSame(3, $items[0]['quantity']);
        $this->assertSame(450.0, $items[0]['unit_price']);
    }

    public function test_a_variation_is_taken_only_when_it_is_one_of_the_products_own(): void
    {
        $this->turnOn();
        $medium = ProductVariant::query()->create(['product_id' => $this->shirt->id, 'options' => ['Size' => 'M'], 'sku' => 'FIL-SHIRT-M', 'sale_price' => 820, 'stock' => 5, 'is_active' => true]);
        $service = app(OrderAutofillService::class);

        $this->modelSays([...$this->goodReply(), 'items' => [['product_id' => $this->shirt->id, 'variant_id' => $medium->id, 'name' => 'shirt M', 'quantity' => 1]]]);
        $result = $service->read($this->company, $this->message());
        $this->assertSame($medium->id, $result['items'][0]['variant_id']);
        $this->assertSame(820.0, $result['items'][0]['unit_price']);
        $this->assertSame([], $result['warnings']);

        // A variation id that is not this product's: left empty, and staff are told to choose.
        $this->modelSays([...$this->goodReply(), 'items' => [['product_id' => $this->shirt->id, 'variant_id' => 12345, 'name' => 'shirt', 'quantity' => 1]]]);
        $result = $service->read($this->company, $this->message());
        $this->assertNull($result['items'][0]['variant_id']);
        $this->assertStringContainsString('Choose the variation', implode(' ', $result['warnings']));
    }

    public function test_the_phone_is_found_by_code_in_any_spelling(): void
    {
        $service = app(OrderAutofillService::class);

        foreach (['01712345678', '+8801712345678', '8801712345678', '+880 1712-345678', '০১৭১২৩৪৫৬৭৮', 'call 017 1234 5678 now'] as $text) {
            $this->assertSame('01712345678', $service->phoneFromMessage("name: Karim {$text}"), $text);
        }

        $this->assertNull($service->phoneFromMessage('order number 12345, 2 pieces'));
        $this->assertNull($service->phoneFromMessage('01212345678'));
    }

    public function test_a_message_without_a_number_leaves_the_customer_for_staff(): void
    {
        $this->turnOn();
        $this->modelSays([...$this->goodReply(), 'phone' => '12']);

        $result = app(OrderAutofillService::class)->read($this->company, '২টা lamp লাগবে');

        $this->assertNull($result['customer']['phone']);
        $this->assertFalse($result['customer']['is_new']);
        $this->assertStringContainsString('No valid mobile number', implode(' ', $result['warnings']));
    }

    public function test_it_copes_with_the_ways_free_models_wrap_their_answer(): void
    {
        $this->turnOn();
        $service = app(OrderAutofillService::class);
        $json = json_encode($this->goodReply(), JSON_UNESCAPED_UNICODE);

        foreach (["```json\n{$json}\n```", "<think>let me see {not json}</think>\nHere you go: {$json}\nHope that helps!", $json] as $wrapped) {
            $this->modelSays($wrapped);
            $this->assertSame('রহিম', $service->read($this->company, $this->message())['customer']['name']);
        }

        $this->modelSays('Sorry, I cannot help with that.');
        $this->expectException(RuntimeException::class);
        $service->read($this->company, $this->message());
    }

    public function test_the_request_asks_for_plain_json_with_no_tools_so_any_chat_model_works(): void
    {
        $this->turnOn();
        $this->modelSays($this->goodReply());

        app(OrderAutofillService::class)->read($this->company, $this->message());

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.groq.test/v1/chat/completions'
                && ! array_key_exists('tools', $body)
                && $body['model'] === 'free-model'
                && str_contains($body['messages'][0]['content'], 'LED Table Lamp')
                && str_contains($body['messages'][1]['content'], 'রহিম');
        });
    }

    public function test_a_large_catalog_sends_only_the_products_that_look_relevant(): void
    {
        $this->turnOn();
        $this->modelSays($this->goodReply());

        foreach (range(1, 80) as $number) {
            Product::query()->create(['name' => "Filler Gadget {$number}", 'sku' => "FIL-F-{$number}", 'price' => 10, 'sale_price' => 10, 'stock' => 1, 'is_active' => true]);
        }

        $result = app(OrderAutofillService::class)->read($this->company, 'রহিম 01712345678 — 2 pcs LED Table Lamp please');

        Http::assertSent(function (Request $request): bool {
            $system = $request->data()['messages'][0]['content'];

            return str_contains($system, 'LED Table Lamp') && substr_count($system, 'Filler Gadget') < 40;
        });
        $this->assertSame($this->lamp->id, $result['items'][0]['product_id']);
    }

    public function test_it_refuses_when_off_empty_or_over_the_daily_limit(): void
    {
        $service = app(OrderAutofillService::class);
        $this->modelSays($this->goodReply());

        // Off by default, even for a company with an old shared AI key: this tool never borrows it.
        $settings = (array) $this->company->settings;
        $settings['ai'] = ['enabled' => true, 'provider' => 'openai', 'api_format' => 'openai', 'model' => 'paid-model', 'api_key' => Crypt::encryptString('paid-key')];
        $this->company->forceFill(['settings' => $settings])->save();
        $this->assertFalse($service->isConfigured($this->company->refresh()));
        $this->assertTrue(app(AiSettingsService::class)->enabled($this->company, AiSettingsService::TOOL_MESSAGING));

        try {
            $service->read($this->company, $this->message());
            $this->fail('A tool that is off must not run.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not set up', $exception->getMessage());
        }
        Http::assertNothingSent();

        $this->turnOn(['daily_run_limit' => 2]);
        Cache::flush();

        try {
            $service->read($this->company, '   ');
            $this->fail('An empty message must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Paste', $exception->getMessage());
        }

        $service->read($this->company, $this->message());
        $service->read($this->company, $this->message());
        $this->expectExceptionMessage('daily limit');
        $service->read($this->company, $this->message());
    }

    public function test_a_provider_limit_or_outage_becomes_a_plain_message(): void
    {
        $this->turnOn();
        $service = app(OrderAutofillService::class);

        $this->freshHttp();
        Http::fake(['*' => Http::response(['error' => 'rate limited'], 429)]);
        try {
            $service->read($this->company, $this->message());
            $this->fail('A 429 must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('limit is reached', $exception->getMessage());
            $this->assertStringNotContainsString('free-key', $exception->getMessage());
        }

        $this->freshHttp();
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->expectExceptionMessage('error 500');
        $service->read($this->company, $this->message());
    }

    public function test_the_form_button_shows_only_when_the_tool_is_on(): void
    {
        Livewire::test(CreateOrder::class)->assertDontSee('Fill with AI');

        $this->turnOn();

        Livewire::test(CreateOrder::class)->assertSee('Fill with AI');
    }

    public function test_the_form_button_fills_the_customer_items_advance_and_totals(): void
    {
        $this->turnOn();
        $this->modelSays($this->goodReply());
        $bkash = Account::query()->create(['name' => 'bKash', 'type' => 'mobile_banking', 'opening_balance' => 0, 'is_active' => true]);

        Livewire::test(CreateOrder::class)
            ->callAction(TestAction::make('fillWithAi')->schemaComponent('aiFill', schema: 'form'), data: ['message' => $this->message(), 'create_customer' => true])
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertFormSet(function (array $state) use ($bkash): void {
                $customer = Customer::query()->where('phone', '01712345690')->firstOrFail();
                $this->assertEquals($customer->id, $state['customer_id']);
                $this->assertSame('রহিম', $customer->name);
                $line = array_values($state['items'])[0];
                $this->assertSame($this->lamp->id, $line['product_id']);
                $this->assertEquals(2, $line['quantity']);
                $this->assertEquals(900, $state['subtotal']);
                $this->assertEquals(200, $state['paid_amount']);
                $this->assertEquals($bkash->id, $state['payment_account_id']);
                $this->assertStringContainsString('ডেলিভারির আগে কল দিবেন', $state['note']);
            });

        // Nothing but the customer was written: no order exists until Create is pressed.
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_leaving_the_new_customer_switch_off_creates_no_customer(): void
    {
        $this->turnOn();
        $this->modelSays($this->goodReply());

        Livewire::test(CreateOrder::class)
            ->callAction(TestAction::make('fillWithAi')->schemaComponent('aiFill', schema: 'form'), data: ['message' => $this->message(), 'create_customer' => false])
            ->assertNotified()
            ->assertFormSet(fn (array $state) => $this->assertEmpty($state['customer_id'] ?? null));

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_a_failing_model_shows_a_message_and_leaves_the_form_alone(): void
    {
        $this->turnOn();
        $this->freshHttp();
        Http::fake(['*' => Http::response('boom', 500)]);

        Livewire::test(CreateOrder::class)
            ->callAction(TestAction::make('fillWithAi')->schemaComponent('aiFill', schema: 'form'), data: ['message' => $this->message(), 'create_customer' => true])
            ->assertNotified('Could not fill the form');

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_the_tool_is_set_up_on_the_integrations_page_and_the_key_stays_encrypted(): void
    {
        Livewire::test(Integrations::class)
            ->assertSee('Order Form AI Fill')
            ->set('data.ai_order_autofill_enabled', true)
            ->set('data.ai_order_autofill_api_format', 'openai')
            ->set('data.ai_order_autofill_provider', 'Groq')
            ->set('data.ai_order_autofill_base_url', 'https://api.groq.com/openai/v1/chat/completions')
            ->set('data.ai_order_autofill_model', 'openai/gpt-oss-20b')
            ->set('data.ai_order_autofill_api_key', 'gsk-free-key')
            ->set('data.ai_order_autofill_daily_run_limit', 120)
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(AiSettingsService::class)->all($this->company->fresh(), AiSettingsService::TOOL_ORDER_AUTOFILL);
        $this->assertTrue($settings['enabled']);
        $this->assertSame('openai/gpt-oss-20b', $settings['model']);
        $this->assertSame('gsk-free-key', $settings['api_key']);
        $this->assertSame(120, $settings['daily_run_limit']);
        $this->assertStringNotContainsString('gsk-free-key', json_encode($this->company->fresh()->settings));
        $this->assertTrue(app(OrderAutofillService::class)->isConfigured($this->company->fresh()));
    }
}
