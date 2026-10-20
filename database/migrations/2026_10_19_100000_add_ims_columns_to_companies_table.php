<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMS v2 (IMS-V2/TRD.md §3.2): a company can switch IMS on and give it its
 * own root domain. The domain serves the investor portal at its root and the
 * IMS office at /office (IMS-V2/ARCHITECTURE.md §2–3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('ims_enabled')->default(false)->after('investor_portal_domain');
            $table->string('ims_domain')->nullable()->after('ims_enabled');
            $table->unique('ims_domain', 'companies_ims_domain_unique');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique('companies_ims_domain_unique');
            $table->dropColumn(['ims_enabled', 'ims_domain']);
        });
    }
};
