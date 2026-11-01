<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseReceipts\Pages\CreatePurchaseReceipt;
use App\Filament\Resources\PurchaseReceipts\Pages\EditPurchaseReceipt;
use App\Filament\Resources\PurchaseReceipts\Pages\ListPurchaseReceipts;
use App\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseReceiptResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_pages_render_for_a_purchasing_user(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        [$receipt] = $this->seedReceipt();

        $this->actingAs($user)
            ->get('/admin/purchasing/purchase-receipts')
            ->assertOk()
            ->assertSee($receipt->receipt_number)
            ->assertSee('Shahin Khan');

        $this->actingAs($user)
            ->get("/admin/purchasing/purchase-receipts/{$receipt->id}")
            ->assertOk()
            ->assertSee('Love Pearl');

        $this->actingAs($user)
            ->get("/admin/purchasing/purchase-receipts/{$receipt->id}/edit")
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/purchasing/purchase-receipts/create')
            ->assertOk();
    }

    public function test_a_receipt_is_created_through_the_form_and_books_stock(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Shahin Khan', 'opening_balance' => 0, 'is_active' => true]);
        $product = $this->product('Love Rose');

        $this->actingAs($user);

        Livewire::test(CreatePurchaseReceipt::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'received_on' => '2026-02-05',
                'items' => [[
                    'product_id' => $product->id,
                    'cartons' => 10,
                    'pcs_per_carton' => 60,
                    'quantity' => 600,
                    'short_quantity' => 5,
                    'damage_quantity' => 15,
                    'unit_cost' => 80,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $receipt = PurchaseReceipt::query()->firstOrFail();
        $this->assertSame('2026-02-05', $receipt->received_on->toDateString());
        $this->assertSame(580, $product->fresh()->stock);
        $this->assertSame(600, $receipt->totalPieces());
        $this->assertSame(48000.0, $receipt->totalValue());
    }

    public function test_a_bad_line_shows_a_notification_and_saves_nothing(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Shahin Khan', 'opening_balance' => 0, 'is_active' => true]);
        $product = $this->product('Love Forever');

        $this->actingAs($user);

        Livewire::test(CreatePurchaseReceipt::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'received_on' => '2026-02-05',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 10,
                    'short_quantity' => 11,
                    'damage_quantity' => 0,
                    'unit_cost' => 80,
                ]],
            ])
            ->call('create')
            ->assertNotified(__('Could not save'));

        $this->assertSame(0, PurchaseReceipt::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_deleting_a_sold_receipt_is_refused_with_a_notification(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        [$receipt, $product] = $this->seedReceipt(quantity: 50);

        StockMovement::query()->create([
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity' => 40,
            'reference_type' => Product::class,
            'reference_id' => $product->id,
        ]);

        $this->actingAs($user);

        Livewire::test(EditPurchaseReceipt::class, ['record' => $receipt->getKey()])
            ->callAction('delete')
            ->assertNotified(__('This receipt cannot be deleted'));

        // 50 sent - 10 broken = 40 in stock, all 40 sold: nothing may be taken back out.
        $this->assertNotNull($receipt->fresh());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(3, StockMovement::query()->count());
    }

    public function test_list_and_view_pages_work_as_livewire_components(): void
    {
        $user = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        [$receipt] = $this->seedReceipt();

        $this->actingAs($user);

        Livewire::test(ListPurchaseReceipts::class)
            ->assertCanSeeTableRecords([$receipt]);

        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->getKey()])
            ->assertOk();
    }

    public function test_a_user_without_purchasing_permission_cannot_open_receipts(): void
    {
        $user = User::factory()->create(['role' => 'sales_staff', 'is_active' => true]);

        $this->actingAs($user)
            ->get('/admin/purchasing/purchase-receipts')
            ->assertForbidden();
    }

    /**
     * @return array{0: PurchaseReceipt, 1: Product}
     */
    private function seedReceipt(int $quantity = 100): array
    {
        $supplier = Supplier::query()->create(['name' => 'Shahin Khan', 'opening_balance' => 0, 'is_active' => true]);
        $product = $this->product('Love Pearl');
        $receipt = PurchaseReceipt::query()->create(['supplier_id' => $supplier->id, 'received_on' => '2026-02-05']);

        PurchaseReceiptItem::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'short_quantity' => 0,
            'damage_quantity' => 10,
            'unit_cost' => 80,
        ]);

        return [$receipt, $product];
    }

    private function product(string $name): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'receipt-category'], ['name' => 'Receipt Category']);

        return Product::query()->create([
            'name' => $name,
            'sku' => 'SKU-'.strtoupper(str_replace(' ', '-', $name)),
            'unit' => 'pcs',
            'cost_price' => 0,
            'sale_price' => 100,
            'price' => 100,
            'stock' => 0,
            'reorder_level' => 0,
            'is_active' => true,
            'category_id' => $category->id,
        ]);
    }
}
