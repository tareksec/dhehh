<?php

namespace Tests\Feature;

use App\Filament\Resources\StockPools\Pages\CreateStockPool;
use App\Filament\Resources\StockPools\Pages\EditStockPool;
use App\Filament\Resources\StockPools\StockPoolResource;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockPool;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockPoolResourceTest extends TestCase
{
    use RefreshDatabase;

    protected Company $international;

    protected Company $gadget;

    protected Company $outlet;

    protected Product $source;

    protected Product $mirror;

    protected Product $thirdMirror;

    protected function setUp(): void
    {
        parent::setUp();

        $this->international = Company::query()->create([
            'name' => 'ZamZam International', 'slug' => 'zi-pool-resource',
            'invoice_prefix' => 'ZIPR', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        $this->gadget = Company::query()->create([
            'name' => 'ZamZam Gadget', 'slug' => 'zg-pool-resource',
            'invoice_prefix' => 'ZGPR', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);
        $this->outlet = Company::query()->create([
            'name' => 'ZamZam Outlet', 'slug' => 'zo-pool-resource',
            'invoice_prefix' => 'ZOPR', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'is_active' => true,
        ]);

        app(CompanyContext::class)->set($this->international);
        $this->source = Product::query()->create([
            'name' => 'Widget', 'sku' => 'POOL-RES-001', 'price' => 500, 'sale_price' => 500, 'stock' => 0,
        ]);
        StockMovement::query()->create(['product_id' => $this->source->id, 'type' => 'opening', 'quantity' => 10]);

        app(CompanyContext::class)->set($this->gadget);
        $this->mirror = Product::query()->create([
            'name' => 'Widget (Retail)', 'sku' => 'POOL-RES-001-R', 'price' => 600, 'sale_price' => 600, 'stock' => 0,
        ]);

        app(CompanyContext::class)->set($this->outlet);
        $this->thirdMirror = Product::query()->create([
            'name' => 'Widget (Outlet)', 'sku' => 'POOL-RES-001-O', 'price' => 550, 'sale_price' => 550, 'stock' => 0,
        ]);

        app(CompanyContext::class)->all();
        $this->actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));
    }

    public function test_super_admin_can_link_two_companies_products_into_a_shared_pool(): void
    {
        Livewire::test(CreateStockPool::class)
            ->fillForm([
                'source_product_id' => $this->source->id,
                'member_product_ids' => [$this->mirror->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->source->refresh();
        $this->mirror->refresh();

        $this->assertNotNull($this->source->stock_pool_id);
        $this->assertSame($this->source->stock_pool_id, $this->mirror->stock_pool_id);
        $this->assertSame(10, $this->source->stock);
        $this->assertSame(10, $this->mirror->stock);
    }

    public function test_non_super_admin_cannot_view_or_create_pools(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales_staff', 'is_active' => true]));

        $this->assertFalse(StockPoolResource::canViewAny());
        $this->assertFalse(StockPoolResource::canCreate());
    }

    public function test_removing_a_member_on_edit_reverts_it_to_its_own_independent_stock(): void
    {
        $pool = StockPool::query()->create(['source_product_id' => $this->source->id]);
        StockPoolResource::syncMembers($pool, [$this->mirror->id, $this->thirdMirror->id]);

        StockMovement::query()->create([
            'product_id' => $this->thirdMirror->id,
            'type' => 'sale',
            'quantity' => 3,
        ]);
        $this->assertSame(7, $this->source->refresh()->stock);
        $this->assertSame(7, $this->mirror->refresh()->stock);
        $this->assertSame(7, $this->thirdMirror->refresh()->stock);

        // Drop the outlet from the pool but keep Gadget linked.
        Livewire::test(EditStockPool::class, ['record' => $pool->getKey()])
            ->fillForm([
                'source_product_id' => $this->source->id,
                'member_product_ids' => [$this->mirror->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->thirdMirror->refresh();
        $this->assertNull($this->thirdMirror->stock_pool_id);
        // The outlet's own ledger (sale -3, transfer +3) nets to zero, independent of the pool now.
        $this->assertSame(0, $this->thirdMirror->stock);

        // Gadget stays linked and still shows the live pool total.
        $this->assertSame($pool->getKey(), $this->mirror->refresh()->stock_pool_id);
        $this->assertSame(7, $this->mirror->stock);
        $this->assertSame(7, $this->source->refresh()->stock);
    }

    public function test_deleting_a_pool_unlinks_every_member(): void
    {
        $pool = StockPool::query()->create(['source_product_id' => $this->source->id]);
        StockPoolResource::syncMembers($pool, [$this->mirror->id]);

        Livewire::test(EditStockPool::class, ['record' => $pool->getKey()])
            ->callAction('delete', data: ['confirmation' => $this->source->name]);

        $this->assertNull(StockPool::query()->find($pool->getKey()));
        $this->assertNull($this->source->refresh()->stock_pool_id);
        $this->assertNull($this->mirror->refresh()->stock_pool_id);
        $this->assertSame(10, $this->source->stock);
    }
}
