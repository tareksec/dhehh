<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // An item added to an order after it was created (owner request,
            // 2026-10-05). Shown as an "Upsell" tag in the admin panel only,
            // never on anything the customer sees.
            $table->boolean('is_upsell')->default(false)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('is_upsell');
        });
    }
};
