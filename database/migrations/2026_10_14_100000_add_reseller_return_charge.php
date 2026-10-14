<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner decision (2026-10-14): when a reseller's order is returned
        // after it went out for delivery, a return charge of 50 per order is
        // taken from the reseller's deposit. The amount is kept here so the
        // admin can change it in Reseller Settings (0 = no charge).
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->decimal('reseller_return_charge', 12, 2)->default(50);
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn('reseller_return_charge');
        });
    }
};
