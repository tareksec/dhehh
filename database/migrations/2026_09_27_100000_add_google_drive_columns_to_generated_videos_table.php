<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md Phase 4 — "Save to Google Drive".
 * A copy of a finished clip can be sent to the company's Google Drive folder
 * (SaveVideoToDriveJob). The storefront keeps playing the company's own
 * public storage copy; these columns only record the Drive copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('generated_videos') || Schema::hasColumn('generated_videos', 'drive_status')) {
            return;
        }

        Schema::table('generated_videos', function (Blueprint $table): void {
            $table->string('drive_status')->nullable()->after('is_favorite');
            $table->string('drive_file_id')->nullable()->after('drive_status');
            $table->string('drive_url', 500)->nullable()->after('drive_file_id');
            $table->timestamp('drive_saved_at')->nullable()->after('drive_url');
            $table->text('drive_error')->nullable()->after('drive_saved_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('generated_videos', 'drive_status')) {
            return;
        }

        Schema::table('generated_videos', function (Blueprint $table): void {
            $table->dropColumn(['drive_status', 'drive_file_id', 'drive_url', 'drive_saved_at', 'drive_error']);
        });
    }
};
