<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner request (2026-10-07): a public reseller guide on the portal
        // host, so someone can read the rules before applying. The guide's
        // own text and rules are admin-managed (Resellers → Reseller Settings).
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->boolean('reseller_guide_enabled')->default(true)->after('reseller_min_withdrawal');
            $table->text('reseller_guide_intro')->nullable()->after('reseller_guide_enabled');
            $table->json('reseller_guide_rules')->nullable()->after('reseller_guide_intro');
            $table->boolean('reseller_guide_show_figures')->default(false)->after('reseller_guide_rules');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn(['reseller_guide_enabled', 'reseller_guide_intro', 'reseller_guide_rules', 'reseller_guide_show_figures']);
        });
    }
};
