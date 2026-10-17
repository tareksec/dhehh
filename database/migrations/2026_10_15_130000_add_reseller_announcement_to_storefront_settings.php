<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner request (2026-10-15): a notice the company writes in Reseller
        // Settings and every approved reseller sees at the top of the portal
        // dashboard. The text is admin-managed (never hardcoded).
        Schema::table('storefront_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('storefront_settings', 'reseller_announcement_enabled')) {
                $table->boolean('reseller_announcement_enabled')->default(false)->after('reseller_guide_show_figures');
            }

            if (! Schema::hasColumn('storefront_settings', 'reseller_announcement')) {
                $table->text('reseller_announcement')->nullable()->after('reseller_announcement_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            foreach (['reseller_announcement_enabled', 'reseller_announcement'] as $column) {
                if (Schema::hasColumn('storefront_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
