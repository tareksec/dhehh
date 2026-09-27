<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md Phase 5 — `ai_tools.video_generation.review`
 * joins the Manager role's ROLE_PERMISSIONS. The role editor reads built-in
 * roles from the `user_roles` snapshot, so the permission is appended to the
 * stored Manager row too.
 *
 * Additive only: the permission is added to a Manager row that already holds
 * `ai_tools.video_generation`, and nothing else in any row is changed, so an
 * owner's own edits to built-in roles survive.
 */
return new class extends Migration
{
    private const TOOL = 'ai_tools.video_generation';

    private const REVIEW = 'ai_tools.video_generation.review';

    public function up(): void
    {
        if (! Schema::hasTable('user_roles')) {
            return;
        }

        $row = DB::table('user_roles')->where('slug', 'manager')->first();

        if ($row === null) {
            return;
        }

        $permissions = json_decode((string) $row->permissions, true);

        if (! is_array($permissions) || ! in_array(self::TOOL, $permissions, true) || in_array(self::REVIEW, $permissions, true)) {
            return;
        }

        $permissions[] = self::REVIEW;

        DB::table('user_roles')->where('slug', 'manager')->update([
            'permissions' => json_encode(array_values($permissions)),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        //
    }
};
