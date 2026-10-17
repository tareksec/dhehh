<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner decision (2026-10-14): the admin enters a suggested selling
        // (market) price on each variation. A reseller sees it, and their
        // customers pay it unless the reseller sets their own price. What the
        // reseller pays is the variation's own (wholesale) price plus the
        // reseller cost per unit, so this is a different number from it.
        if (! Schema::hasColumn('product_variants', 'reseller_suggested_price')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->decimal('reseller_suggested_price', 12, 2)->nullable()->after('sale_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('product_variants', 'reseller_suggested_price')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->dropColumn('reseller_suggested_price');
            });
        }
    }
};
