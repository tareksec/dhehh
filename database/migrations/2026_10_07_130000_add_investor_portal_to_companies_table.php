<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §4.2: the investor portal is
        // off until a company turns it on, and opens at "investor.<domain>"
        // unless a custom domain is set (the same rule as the reseller portal).
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('investor_portal_enabled')->default(false);
            $table->string('investor_portal_domain')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropUnique(['investor_portal_domain']);
            $table->dropColumn(['investor_portal_enabled', 'investor_portal_domain']);
        });
    }
};
