<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_items', 'hs_code')) {
                $table->string('hs_code')->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('purchase_items', 'spec_note')) {
                $table->string('spec_note')->nullable()->after('hs_code');
            }
            if (! Schema::hasColumn('purchase_items', 'fob_unit_price_usd')) {
                $table->decimal('fob_unit_price_usd', 14, 2)->nullable()->after('unit_cost');
            }
            if (! Schema::hasColumn('purchase_items', 'net_weight_kg')) {
                $table->decimal('net_weight_kg', 12, 3)->nullable()->after('landed_unit_cost');
            }
            if (! Schema::hasColumn('purchase_items', 'gross_weight_kg')) {
                $table->decimal('gross_weight_kg', 12, 3)->nullable()->after('net_weight_kg');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table): void {
            foreach (['hs_code', 'spec_note', 'fob_unit_price_usd', 'net_weight_kg', 'gross_weight_kg'] as $column) {
                if (Schema::hasColumn('purchase_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
