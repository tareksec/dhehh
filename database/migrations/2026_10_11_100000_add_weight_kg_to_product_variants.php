<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            // Same precision as products.weight_kg. NULL = use the product's weight.
            $table->decimal('weight_kg', 8, 3)->nullable()->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('weight_kg');
        });
    }
};
