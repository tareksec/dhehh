<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CompanyContext;
use App\Services\StorefrontCatalogService;
use App\Services\StorefrontDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-variation weight: the Variations repeater in the product form has a
 * weight field; empty falls back to the product's weight everywhere weight is
 * used (delivery charge, invoice, order view, storefront API).
 */
class ProductVariantWeightTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::query()->create([
            'name' => 'Weight Co',
            'slug' => 'weight-co',
            'invoice_prefix' => 'WGT',
            'currency' => 'BDT',
            'timezone' => 'Asia/Dhaka',
            'is_active' => true,
        ]);

        app(CompanyContext::class)->set($this->company);
    }

    private function variableProduct(?float $weight = 1.0): Product
    {
        return Product::query()->create([
            'name' => 'Weighted Jar',
            'sku' => 'WGT-JAR-001',
            'price' => 500,
            'sale_price' => 500,
            'cost_price' => 300,
            'stock' => 0,
            'unit' => 'pcs',
            'reorder_level' => 1,
            'vat_rate' => 0,
            'weight_kg' => $weight,
            'category_id' => Category::query()->firstOrCreate(['slug' => 'jars'], ['name' => 'Jars'])->getKey(),
            'is_active' => true,
            'status' => Product::STATUS_AVAILABLE,
            'has_variants' => true,
            'variant_attributes' => ['Size' => ['500g', '2kg']],
        ]);
    }

    public function test_variant_weight_is_saved_and_falls_back_to_the_product_weight(): void
    {
        $product = $this->variableProduct(1.0);

        $heavy = $product->variants()->create(['options' => ['Size' => '2kg'], 'weight_kg' => 2.25, 'stock' => 3]);
        $plain = $product->variants()->create(['options' => ['Size' => '500g'], 'stock' => 3]);

        $this->assertSame('2.250', (string) $heavy->fresh()->weight_kg);
        $this->assertSame(2.25, $heavy->fresh()->effectiveWeightKg());

        $this->assertNull($plain->fresh()->weight_kg);
        $this->assertSame(1.0, $plain->fresh()->effectiveWeightKg());
    }

    public function test_effective_weight_is_null_when_neither_variant_nor_product_has_one(): void
    {
        $product = $this->variableProduct(null);
        $variant = $product->variants()->create(['options' => ['Size' => '500g'], 'stock' => 1]);

        $this->assertNull($variant->effectiveWeightKg());
    }

    public function test_product_form_saves_the_variation_weight(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $this->company->getKey()]);

        $product = $this->variableProduct(1.0);
        $variant = $product->variants()->create([
            'options' => ['Size' => '2kg'],
            'sku' => 'WGT-JAR-2KG',
            'stock' => 4,
            'is_active' => true,
        ]);

        $component = Livewire::test(ListProducts::class)->mountTableAction('quickEdit', $product);

        $variants = $component->get('mountedActions.0.data.variants');
        $this->assertCount(1, $variants);
        $key = array_key_first($variants);
        $this->assertArrayHasKey('weight_kg', $variants[$key]);

        $variants[$key]['weight_kg'] = '2.5';

        $component
            ->set('mountedActions.0.data.variants', $variants)
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('2.500', (string) $variant->fresh()->weight_kg);
    }

    public function test_product_form_rejects_a_negative_variation_weight(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $this->company->getKey()]);

        $product = $this->variableProduct(1.0);
        $variant = $product->variants()->create(['options' => ['Size' => '2kg'], 'stock' => 4, 'is_active' => true]);

        $component = Livewire::test(ListProducts::class)->mountTableAction('quickEdit', $product);

        $variants = $component->get('mountedActions.0.data.variants');
        $key = array_key_first($variants);
        $variants[$key]['weight_kg'] = '-1';

        $component
            ->set('mountedActions.0.data.variants', $variants)
            ->callMountedTableAction()
            ->assertHasTableActionErrors();

        $this->assertTrue(
            collect($component->errors()->keys())->contains(fn (string $key): bool => str_ends_with($key, '.weight_kg')),
            'The negative weight should fail validation on the weight_kg field itself.'
        );
        $this->assertNull($variant->fresh()->weight_kg);
    }

    public function test_delivery_quote_uses_the_variant_weight_before_the_product_weight(): void
    {
        $product = $this->variableProduct(1.0);
        $heavy = $product->variants()->create(['options' => ['Size' => '2kg'], 'weight_kg' => 2.5, 'stock' => 5]);
        $plain = $product->variants()->create(['options' => ['Size' => '500g'], 'stock' => 5]);

        $setting = new StorefrontSetting([
            'delivery_first_kg_inside' => 70,
            'delivery_first_kg_outside' => 110,
            'delivery_additional_per_kg' => 20,
        ]);
        $service = new StorefrontDeliveryService;

        // 2 × 2.5 kg = 5 kg -> 70 + 4 × 20.
        $quote = $service->quote([['product' => $product, 'variant' => $heavy, 'quantity' => 2]], 'inside', $setting);
        $this->assertSame(5.0, $quote['weight']);
        $this->assertSame(5, $quote['billed_weight']);
        $this->assertSame(150.0, $quote['fee']);

        // No variant weight -> the product's 1 kg, and no variant at all behaves as before.
        $this->assertSame(2.0, $service->quote([['product' => $product, 'variant' => $plain, 'quantity' => 2]], 'inside', $setting)['weight']);
        $this->assertSame(2.0, $service->quote([['product' => $product, 'quantity' => 2]], 'inside', $setting)['weight']);

        // Mixed cart: 2.5 + 1 = 3.5 kg -> billed 4 kg.
        $mixed = $service->quote([
            ['product' => $product, 'variant' => $heavy, 'quantity' => 1],
            ['product' => $product, 'variant' => $plain, 'quantity' => 1],
        ], 'inside', $setting);
        $this->assertSame(3.5, $mixed['weight']);
        $this->assertSame(4, $mixed['billed_weight']);
    }

    public function test_order_item_weight_prefers_the_variant_and_falls_back_to_the_product(): void
    {
        $product = $this->variableProduct(1.0);
        $heavy = $product->variants()->create(['options' => ['Size' => '2kg'], 'weight_kg' => 2.25, 'stock' => 5]);
        $plain = $product->variants()->create(['options' => ['Size' => '500g'], 'stock' => 5]);
        $order = $this->order();

        $withVariantWeight = OrderItem::query()->create([
            'order_id' => $order->getKey(), 'product_id' => $product->getKey(), 'product_variant_id' => $heavy->getKey(),
            'variant_label' => $heavy->label(), 'quantity' => 1, 'unit_price' => 500,
        ]);
        $withoutVariantWeight = OrderItem::query()->create([
            'order_id' => $order->getKey(), 'product_id' => $product->getKey(), 'product_variant_id' => $plain->getKey(),
            'variant_label' => $plain->label(), 'quantity' => 1, 'unit_price' => 500,
        ]);
        $noVariant = OrderItem::query()->create([
            'order_id' => $order->getKey(), 'product_id' => $product->getKey(), 'quantity' => 1, 'unit_price' => 500,
        ]);

        $this->assertSame(2.25, $withVariantWeight->unitWeightKg());
        $this->assertSame(1.0, $withoutVariantWeight->unitWeightKg());
        $this->assertSame(1.0, $noVariant->unitWeightKg());
    }

    public function test_printed_invoice_shows_the_variant_weight(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($user)->withSession(['current_company_id' => $this->company->getKey()]);

        $product = $this->variableProduct(1.0);
        $heavy = $product->variants()->create(['options' => ['Size' => '2kg'], 'weight_kg' => 2.25, 'stock' => 5]);
        $order = $this->order();
        OrderItem::query()->create([
            'order_id' => $order->getKey(), 'product_id' => $product->getKey(), 'product_variant_id' => $heavy->getKey(),
            'variant_label' => $heavy->label(), 'quantity' => 1, 'unit_price' => 500,
        ]);

        $this->get(route('orders.print', $order))
            ->assertOk()
            ->assertSee('2.25 kg');
    }

    public function test_storefront_api_variants_carry_their_weight(): void
    {
        $product = $this->variableProduct(1.0);
        $product->variants()->create(['options' => ['Size' => '2kg'], 'weight_kg' => 2.25, 'stock' => 5, 'is_active' => true]);
        $product->variants()->create(['options' => ['Size' => '500g'], 'stock' => 5, 'is_active' => true]);

        $detail = (new StorefrontCatalogService($this->company))->detail($product->fresh());

        $weights = collect($detail['variants'])->pluck('weight_kg', 'label');
        $this->assertSame(2.25, $weights['Size: 2kg']);
        $this->assertSame(1.0, $weights['Size: 500g']);
    }

    private function order(): Order
    {
        $customer = Customer::query()->create([
            'name' => 'Weight Buyer',
            'phone' => '01711000111',
            'address' => 'Mirpur, Dhaka',
        ]);

        return Order::query()->create([
            'customer_id' => $customer->getKey(),
            'order_date' => '2026-10-11',
            'status' => 'draft',
        ])->refresh();
    }
}
