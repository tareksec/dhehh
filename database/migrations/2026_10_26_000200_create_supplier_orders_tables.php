<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->nullable()->after('stock_pool_id')->constrained()->nullOnDelete();
        });

        // Goods a supplier gives his own customers from our stock. Kept apart from
        // `orders` on purpose: the revenue is not ours, so these never reach our
        // sales, profit, customer dues, courier, notifications or website webhooks.
        Schema::create('supplier_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('order_number');
            $table->date('order_date');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->date('delivery_date')->nullable();
            $table->string('status')->default('open');
            // Money the customer paid up front, and who is holding it.
            $table->decimal('advance_amount', 12, 2)->default(0);
            $table->string('advance_collected_by')->default('supplier');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'order_number']);
            $table->index(['company_id', 'supplier_id', 'order_date']);
        });

        Schema::create('supplier_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            // What the customer pays per piece.
            $table->decimal('unit_price', 12, 2)->default(0);
            // The supplier's buy price per piece when the order was entered: what comes off his account.
            $table->decimal('supplier_unit_cost', 12, 2)->default(0);
            $table->timestamps();
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_order_items');
        Schema::dropIfExists('supplier_orders');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
