<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner request (2026-10-22): the website's footer shows the "Become a reseller" link as a
        // banner button. This is the picture the admin uploads for it (Reseller Settings); empty
        // means the standard "Reselling Program" banner that ships with the ERP.
        Schema::table('storefront_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('storefront_settings', 'reseller_banner_image')) {
                $table->string('reseller_banner_image')->nullable()->after('reseller_announcement');
            }
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('storefront_settings', 'reseller_banner_image')) {
                $table->dropColumn('reseller_banner_image');
            }
        });
    }
};
