<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Phase 7a of 13_RESELLER_PORTAL_PLAN.md: when on, approved
            // resellers can choose "name.<company domain>" for their store.
            $table->boolean('reseller_subdomains_enabled')->default(false)->after('reseller_portal_domain');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('reseller_subdomain')->nullable()->after('reseller_slug');
            $table->unique(['company_id', 'reseller_subdomain']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'reseller_subdomain']);
            $table->dropColumn('reseller_subdomain');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('reseller_subdomains_enabled');
        });
    }
};
