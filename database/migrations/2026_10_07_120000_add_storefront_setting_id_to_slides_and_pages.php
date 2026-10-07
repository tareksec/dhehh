<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Show on": a hero slide or a custom page can be limited to one of the
 * company's sites (its Blade storefront or its Next.js site). NULL means all
 * sites, which is what every existing slide and page keeps being, so nothing
 * changes until the owner picks a site.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['storefront_slides', 'storefront_pages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('storefront_setting_id')->nullable()->constrained('storefront_settings')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['storefront_slides', 'storefront_pages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('storefront_setting_id');
            });
        }
    }
};
