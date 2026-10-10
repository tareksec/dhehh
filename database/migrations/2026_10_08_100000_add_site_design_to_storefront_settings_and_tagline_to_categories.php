<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the headless Next.js site needs to be customised from the dashboard:
 *
 *  - `home_sections`: the Home Page Builder — the ordered sections of the home
 *    page (`[{type, data}]`, same shape as `footer_blocks`). NULL = the default
 *    layout (StorefrontSiteDesign::DEFAULT_HOME_SECTIONS), so every row that
 *    exists today keeps working.
 *  - `site_design`: top bar, header, footer colours and the floating help
 *    button (one JSON object; missing keys fall back to the built-in defaults).
 *  - `categories.tagline`: the short line under a category's name on the
 *    website's "Shop by Category" tiles.
 *  - `storefront_slides.eyebrow`: the small spaced label above a hero slide's
 *    heading (the other slide texts already exist; the form now exposes them).
 *
 * Only the Next.js row reads the first two; the Blade storefront ignores them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->json('home_sections')->nullable()->after('footer_blocks');
            $table->json('site_design')->nullable()->after('home_sections');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->string('tagline', 80)->nullable()->after('description');
        });

        Schema::table('storefront_slides', function (Blueprint $table): void {
            $table->string('eyebrow', 60)->nullable()->after('fit_to_frame');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_slides', function (Blueprint $table): void {
            $table->dropColumn('eyebrow');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('tagline');
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn(['home_sections', 'site_design']);
        });
    }
};
