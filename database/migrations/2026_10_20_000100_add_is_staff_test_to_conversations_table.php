<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Practice mode (16_AI_INBOX_CONTROL_PLANE_PLAN.md, owner request
        // 2026-10-19): until the AI is live it answers only the chats staff
        // marked as test chats.
        Schema::table('conversations', function (Blueprint $table): void {
            $table->boolean('is_staff_test')->default(false)->after('ai_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('is_staff_test');
        });
    }
};
