<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Phase 7b of 13_RESELLER_PORTAL_PLAN.md: a reseller's own domain.
            $table->boolean('reseller_custom_domains_enabled')->default(false)->after('reseller_subdomains_enabled');
        });

        Schema::table('customers', function (Blueprint $table): void {
            // A host name is global (it is the whole host, not a label under a
            // company domain), so it is unique across every company.
            $table->string('reseller_custom_domain')->nullable()->unique()->after('reseller_subdomain');
            $table->timestamp('reseller_custom_domain_verified_at')->nullable()->after('reseller_custom_domain');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['reseller_custom_domain']);
            $table->dropColumn(['reseller_custom_domain', 'reseller_custom_domain_verified_at']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('reseller_custom_domains_enabled');
        });
    }
};
