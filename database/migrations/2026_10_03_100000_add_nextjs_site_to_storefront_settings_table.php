<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            // A company's headless Next.js storefront. It sits next to the Blade
            // storefront (domain on `companies`) and is driven by the very same
            // storefront settings row, so it has its own switch and domain only.
            // Off by default: existing companies behave exactly as before and the
            // /api/v1/storefront/* endpoints answer 403 until the owner turns it on.
            $table->boolean('nextjs_site_enabled')->default(false)->after('is_published');
            $table->string('nextjs_site_domain')->nullable()->unique()->after('nextjs_site_enabled');
            $table->boolean('nextjs_site_domain_verified')->default(false)->after('nextjs_site_domain');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropUnique(['nextjs_site_domain']);
            $table->dropColumn(['nextjs_site_enabled', 'nextjs_site_domain', 'nextjs_site_domain_verified']);
        });
    }
};
