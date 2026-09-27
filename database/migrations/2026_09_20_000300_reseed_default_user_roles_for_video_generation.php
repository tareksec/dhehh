<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12_AI_TOOL_MENU_VIDEO_GENERATION_PLAN.md §5(4) — re-runs
 * 2026_06_16_000000_seed_default_user_roles.php's exact snapshot logic now
 * that `ai_tools.video_generation` has been added to the Manager role's
 * ROLE_PERMISSIONS constant. Editing the PHP constant alone takes effect
 * immediately for User::hasPermission(), but the role editor UI reads its
 * built-in-role permission list from the `user_roles` table snapshot, which
 * only a migration (or manual resave) refreshes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_roles')) {
            return;
        }

        $now = now();

        foreach (User::ROLES as $slug => $name) {
            $values = [
                'name' => $name,
                'permissions' => json_encode(User::ROLE_PERMISSIONS[$slug] ?? []),
                'is_active' => true,
                'updated_at' => $now,
            ];

            $exists = DB::table('user_roles')->where('slug', $slug)->exists();

            if ($exists) {
                DB::table('user_roles')
                    ->where('slug', $slug)
                    ->update($values);

                continue;
            }

            DB::table('user_roles')->insert([
                ...$values,
                'slug' => $slug,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
