<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade Portal, phase 1 (docs/tasneem-samples/PLAN.md §1, §3): a company can
 * switch the /trade portal on, and its profile gains what the sale and import
 * papers print — the registered trade address and the factory / letterhead
 * address, a BIN for the Delivery Challan (the import papers keep bin_number),
 * bank accounts and a letterhead image.
 *
 * Also creates the editable "Trade Owner" role (Users → Roles) when it does
 * not exist yet. It only opens the portal; no company or business data is made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'trade_portal_enabled')) {
                $table->boolean('trade_portal_enabled')->default(false);
            }
            if (! Schema::hasColumn('companies', 'registered_address')) {
                $table->text('registered_address')->nullable();
            }
            if (! Schema::hasColumn('companies', 'factory_address')) {
                $table->text('factory_address')->nullable();
            }
            if (! Schema::hasColumn('companies', 'challan_bin_number')) {
                $table->string('challan_bin_number')->nullable();
            }
            if (! Schema::hasColumn('companies', 'bank_accounts')) {
                $table->json('bank_accounts')->nullable();
            }
            if (! Schema::hasColumn('companies', 'letterhead_path')) {
                $table->string('letterhead_path')->nullable();
            }
        });

        if (Schema::hasTable('user_roles') && ! DB::table('user_roles')->where('slug', 'trade_owner')->exists()) {
            DB::table('user_roles')->insert([
                'name' => 'Trade Owner',
                'slug' => 'trade_owner',
                'permissions' => json_encode(['trade.access', 'trade.profile.manage']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            foreach (['trade_portal_enabled', 'registered_address', 'factory_address', 'challan_bin_number', 'bank_accounts', 'letterhead_path'] as $column) {
                if (Schema::hasColumn('companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
