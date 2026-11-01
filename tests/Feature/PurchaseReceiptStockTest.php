<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseReceiptStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_line_books_arrived_pieces_and_damage(): void
    {
        $product = $this->product('Love Pearl');
        $receipt = $this->receipt('2026-02-05');

        $item = $this->line($receipt, $product, quantity: 100, short: 5, damage: 10);

        $this->assertSame(85, $product->fresh()->stock);
        $this->assertSame(95, (int) StockMovement::query()->where('type', 'purchase')->where('reference_id', $item->id)->value('quantity'));
        $this->assertSame(10, (int) StockMovement::query()->where('type', 'damage')->where('reference_id', $item->id)->value('quantity'));
        $this->assertSame(100, $receipt->fresh()->totalPieces());
    }

    public function test_pieces_come_from_cartons_when_not_typed(): void
    {
        $product = $this->product('Love Rose');
        $receipt = $this->receipt('2026-02-05');

        $item = PurchaseReceiptItem::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'cartons' => 12,
            'pcs_per_carton' => 50,
            'unit_cost' => 80,
        ]);

        $this->assertSame(600, $item->quantity);
        $this->assertSame(600, $product->fresh()->stock);
        $this->assertSame(48000.0, $item->value());
    }

    public function test_stock_history_is_dated_on_the_receive_date_and_follows_edits(): void
    {
        $product = $this->product('Love Forever');
        $receipt = $this->receipt('2026-02-05');
        $item = $this->line($receipt, $product, quantity: 20);

        $movement = StockMovement::query()->where('reference_id', $item->id)->firstOrFail();
        $this->assertSame('2026-02-05', $movement->created_at->toDateString());

        $receipt->update(['received_on' => '2026-02-21']);

        $this->assertSame('2026-02-21', $movement->fresh()->created_at->toDateString());
    }

    public function test_editing_a_line_down_works_even_when_the_stock_is_fully_sold(): void
    {
        $product = $this->product('Turbo Fan');
        $receipt = $this->receipt('2026-03-16');
        $item = $this->line($receipt, $product, quantity: 100, damage: 10);

        $this->sell($product, 90);
        $this->assertSame(0, $product->fresh()->stock);

        // 100 sent / 10 broken becomes 90 sent / none broken: the same 90 pieces in stock.
        $item->update(['quantity' => 90, 'damage_quantity' => 0]);

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, StockMovement::query()->where('type', 'damage')->count());
    }

    public function test_a_change_that_would_make_stock_negative_is_refused_and_rolled_back(): void
    {
        $product = $this->product('Mini Quran');
        $receipt = $this->receipt('2026-03-05');
        $item = $this->line($receipt, $product, quantity: 100);

        $this->sell($product, 80);

        try {
            $item->update(['quantity' => 50]);
            $this->fail('Reducing the received pieces below what was sold must be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(100, $item->fresh()->quantity);
        $this->assertSame(20, $product->fresh()->stock);
    }

    public function test_deleting_a_receipt_returns_the_stock_unless_it_was_sold(): void
    {
        $product = $this->product('K801');
        $receipt = $this->receipt('2026-03-10');
        $this->line($receipt, $product, quantity: 40, damage: 4);

        $this->assertSame(36, $product->fresh()->stock);

        $receipt->delete();

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, PurchaseReceiptItem::query()->count());

        $sold = $this->receipt('2026-03-11');
        $this->line($sold, $product, quantity: 40);
        $this->sell($product, 30);

        try {
            $sold->delete();
            $this->fail('A receipt whose goods were sold must not be deletable.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertNotNull($sold->fresh());
        $this->assertSame(1, PurchaseReceiptItem::query()->count());
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_moving_a_line_to_another_product_moves_the_stock(): void
    {
        $first = $this->product('FC8');
        $second = $this->product('X16');
        $receipt = $this->receipt('2026-03-12');
        $item = $this->line($receipt, $first, quantity: 25);

        $item->update(['product_id' => $second->id]);

        $this->assertSame(0, $first->fresh()->stock);
        $this->assertSame(25, $second->fresh()->stock);
    }

    public function test_line_validation_rules(): void
    {
        $product = $this->product('608 Solar');
        $receipt = $this->receipt('2026-03-12');

        foreach ([
            ['quantity' => 0, 'field' => 'quantity'],
            ['quantity' => 10, 'short_quantity' => 11, 'field' => 'short_quantity'],
            ['quantity' => 10, 'short_quantity' => 4, 'damage_quantity' => 7, 'field' => 'damage_quantity'],
        ] as $case) {
            try {
                $this->line($receipt, $product, $case['quantity'], $case['short_quantity'] ?? 0, $case['damage_quantity'] ?? 0);
                $this->fail("Expected a validation error on {$case['field']}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($case['field'], $exception->errors());
            }
        }

        $this->assertSame(0, PurchaseReceiptItem::query()->count());
        $this->assertSame(0, $product->fresh()->stock);
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

    private function receipt(string $date): PurchaseReceipt
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['name' => 'Shahin Khan'],
            ['opening_balance' => 0, 'is_active' => true],
        );

        return PurchaseReceipt::query()->create([
            'supplier_id' => $supplier->id,
            'received_on' => $date,
        ]);
    }

    private function line(PurchaseReceipt $receipt, Product $product, int $quantity, int $short = 0, int $damage = 0): PurchaseReceiptItem
    {
        return PurchaseReceiptItem::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'short_quantity' => $short,
            'damage_quantity' => $damage,
            'unit_cost' => 80,
        ]);
    }

    private function sell(Product $product, int $quantity): void
    {
        StockMovement::query()->create([
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity' => $quantity,
            'reference_type' => Product::class,
            'reference_id' => $product->id,
        ]);
    }
}
