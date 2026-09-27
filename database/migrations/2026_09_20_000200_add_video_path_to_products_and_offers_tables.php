<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md §6.4 — Product/Offer video attach
 * columns. Written only by GeneratedVideoAttacher (a server-side path write);
 * neither column is ever exposed as a FileUpload field, which would
 * reintroduce the browser-upload-through-PHP-FPM size ceiling this tool's
 * design avoids (plan §1.1(2)).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'video_path')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('video_path')->nullable()->after('gallery_images');
            });
        }

        if (Schema::hasTable('offers') && ! Schema::hasColumn('offers', 'video_path')) {
            Schema::table('offers', function (Blueprint $table): void {
                $table->string('video_path')->nullable()->after('cover_image');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'video_path')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('video_path');
            });
        }

        if (Schema::hasColumn('offers', 'video_path')) {
            Schema::table('offers', function (Blueprint $table): void {
                $table->dropColumn('video_path');
            });
        }
    }
};
