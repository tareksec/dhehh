<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade Portal, phase 3 (docs/tasneem-samples/PLAN.md §5): the Trade Owner
 * role gets the cash book and the cash/bank accounts when it exists. No table
 * changes — the cash book reads the ERP's account ledger.
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
        $permissions = array_values(array_unique([...$permissions, 'trade.cashbook', 'trade.money_accounts']));

        DB::table('user_roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions), 'updated_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
