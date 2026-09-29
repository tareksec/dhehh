<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Optional fully custom domain for this company's reseller
            // portal (e.g. "zamzamreseller.com"). When empty, the portal
            // is reachable at "reseller.<domain>" instead -- see
            // Company::forResellerPortalHost().
            $table->string('reseller_portal_domain')->nullable()->unique()->after('reseller_module_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('reseller_portal_domain');
        });
    }
};
