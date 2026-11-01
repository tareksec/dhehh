<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `returned_quantity` already exists (2026_10_20_100000, Nuport import).
        Schema::table('order_items', function (Blueprint $table): void {
            // The discount on this line in total (Nuport's "Product Discount"); the net price is (subtotal - discount) / quantity.
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('discount_amount');
        });
    }
};
