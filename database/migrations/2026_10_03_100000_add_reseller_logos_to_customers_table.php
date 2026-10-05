<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // The reseller's own logo for their store header: one for light
            // mode and an optional one for dark mode (13_RESELLER_PORTAL_PLAN.md).
            $table->string('reseller_logo')->nullable()->after('reseller_store_name');
            $table->string('reseller_logo_dark')->nullable()->after('reseller_logo');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['reseller_logo', 'reseller_logo_dark']);
        });
    }
};
