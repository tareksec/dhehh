<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // user_id only ever points at staff. A portal user (investor-portal
        // account, reseller) is recorded here instead, so their actions are
        // attributed too (15_INVESTOR_PORTAL_IMPLEMENTATION_PLAN.md §4.9).
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->nullableMorphs('actor');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropMorphs('actor');
        });
    }
};
