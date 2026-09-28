<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_products', function (Blueprint $table): void {
            // The reseller's own selling price for this product. Null means
            // "use the suggested price" (the product's normal selling price).
            $table->decimal('price', 12, 2)->nullable()->after('product_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            // Per-unit reseller base (wholesale tier rate + reseller cost)
            // frozen at checkout. Null on every non-reseller line; the
            // reseller's commission is (unit_price - this) * quantity.
            $table->decimal('reseller_base_price', 12, 2)->nullable()->after('unit_cost');
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            // One per-unit amount added on top of the wholesale tier rate
            // to get a reseller's base price (owner: set once, company-wide).
            $table->decimal('reseller_cost_per_unit', 12, 2)->default(0)->after('reseller_program_enabled');
            $table->decimal('reseller_min_withdrawal', 12, 2)->default(0)->after('reseller_cost_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn(['reseller_cost_per_unit', 'reseller_min_withdrawal']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('reseller_base_price');
        });

        Schema::table('reseller_products', function (Blueprint $table): void {
            $table->dropColumn('price');
        });
    }
};
