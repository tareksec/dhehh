<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade Portal, phase 6 (docs/tasneem-samples/PLAN.md §6): the Trade Owner role
 * gets "trade.reports" when it exists. No table changes — the reports read
 * the ERP's records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_roles')) {
            return;
        }

        $role = DB::table('user_roles')->where('slug', 'trade_owner')->first();

        if (! $role) {
            return;
        }

        $permissions = json_decode((string) $role->permissions, true) ?: [];
        $permissions = array_values(array_unique([...$permissions, 'trade.reports']));

        DB::table('user_roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions), 'updated_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
