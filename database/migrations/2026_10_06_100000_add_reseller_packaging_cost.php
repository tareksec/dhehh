<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner rule (2026-10-06): an order sent with the reseller's own
        // courier pays a packaging charge per piece, set by the admin.
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->decimal('reseller_packaging_cost_per_unit', 12, 2)->default(0)->after('reseller_cost_per_unit');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('packaging_fee', 14, 2)->default(0)->after('shipping_fee');
        });

        // Owner rule (2026-10-06): a reseller's customer never sees the
        // store's price, only the retail price the reseller set. On an
        // own-courier order unit_price is what the reseller pays the store,
        // so the reseller's retail price is kept beside it for the buyer.
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('reseller_retail_price', 14, 2)->nullable()->after('reseller_base_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('reseller_retail_price');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('packaging_fee');
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn('reseller_packaging_cost_per_unit');
        });
    }
};
