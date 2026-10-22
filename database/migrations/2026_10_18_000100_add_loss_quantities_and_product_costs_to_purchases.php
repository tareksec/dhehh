<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier report inputs (owner request 2026-10-18):
 *  - purchase_items.short_quantity   billed by the supplier but never delivered
 *  - purchase_items.damaged_quantity delivered but not sellable
 *    (quantity stays the billed quantity; sellable = quantity - short - damaged)
 *  - purchases.product_costs         costs paid after the goods reached the house
 *    (packaging, ad, other), one {product_id, label, amount} line each
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_items', 'short_quantity')) {
                $table->unsignedInteger('short_quantity')->default(0)->after('quantity');
            }

            if (! Schema::hasColumn('purchase_items', 'damaged_quantity')) {
                $table->unsignedInteger('damaged_quantity')->default(0)->after('short_quantity');
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'product_costs')) {
                $table->json('product_costs')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            foreach (['damaged_quantity', 'short_quantity'] as $column) {
                if (Schema::hasColumn('purchase_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'product_costs')) {
                $table->dropColumn('product_costs');
            }
        });
    }
};
