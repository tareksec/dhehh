<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner decision (2026-10-14): a variation has no separate reseller
        // price -- its own (wholesale) price is the reseller's rate. The
        // reseller only picks their own customer price per variation:
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
    }
};
