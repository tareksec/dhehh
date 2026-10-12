<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner decision (2026-10-12): the admin sets the price a reseller
        // pays for each variation (its cost to the reseller, never shown on a
        // reseller's storefront); the reseller then picks their own customer
        // price per variation. NULL reseller_price = the variation has no
        // reseller price, so it sells at its normal price with no commission.
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('reseller_price', 12, 2)->nullable()->after('sale_price');
        });

        // {variant id: the reseller's customer price}; a variation not listed
        // sells at its suggested (normal) price.
        Schema::table('reseller_products', function (Blueprint $table): void {
            $table->json('variant_prices')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_products', function (Blueprint $table): void {
            $table->dropColumn('variant_prices');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('reseller_price');
        });
    }
};
