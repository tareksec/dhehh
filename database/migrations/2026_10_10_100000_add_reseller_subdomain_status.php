<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner request (2026-10-10): after a reseller picks a store
        // subdomain, show how long until it is live and switch to it on its
        // own once DNS + SSL answer. NULL changed_at = chosen before this
        // existed, treated as already live.
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('reseller_subdomain_changed_at')->nullable()->after('reseller_subdomain');
            $table->timestamp('reseller_subdomain_live_at')->nullable()->after('reseller_subdomain_changed_at');
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reseller_subdomain_setup_minutes')->default(10)->after('reseller_min_withdrawal');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['reseller_subdomain_changed_at', 'reseller_subdomain_live_at']);
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn('reseller_subdomain_setup_minutes');
        });
    }
};
