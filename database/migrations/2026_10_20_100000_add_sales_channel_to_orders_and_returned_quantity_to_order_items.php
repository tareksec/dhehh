<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nuport import (owner request 2026-10-20): the channel an order came
        // through (WhatsApp, website, ...) and the pieces of a line that were
        // returned or damaged after dispatch, so reports can use them.
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('sales_channel', 40)->nullable()->after('source');
            $table->index(['company_id', 'sales_channel'], 'orders_company_sales_channel_index');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('returned_quantity');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_company_sales_channel_index');
            $table->dropColumn('sales_channel');
        });
    }
};
